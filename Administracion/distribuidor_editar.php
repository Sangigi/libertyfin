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

// Variables
$mensaje = '';
$tipo_mensaje = '';
$distribuidor = null;
$id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($id <= 0) {
    header("Location: distribuidores.php?error=ID no válido");
    exit();
}

// Crear conexión
$conn = new mysqli($servername, $username, $password, $dbname);

// Verificar conexión
if ($conn->connect_error) {
    die("Error de conexión: " . $conn->connect_error);
}

// Obtener datos del distribuidor
$sql = "SELECT * FROM distribuidores WHERE id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    header("Location: distribuidores.php?error=Distribuidor no encontrado");
    exit();
}

$distribuidor = $result->fetch_assoc();
$stmt->close();

// Procesar el formulario cuando se envía
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // Recoger datos del formulario
    $nombre_distribuidor = trim($_POST['nombre_distribuidor'] ?? '');
    $telefono = trim($_POST['telefono'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $rfc = trim($_POST['rfc'] ?? '');
    $banco = trim($_POST['banco'] ?? '');
    $numero_cuenta = trim($_POST['numero_cuenta'] ?? '');
    $estado_verificacion = $_POST['estado_verificacion'] ?? 'pendiente';
    $observaciones = trim($_POST['observaciones'] ?? '');
    $declaracion_veracidad = isset($_POST['declaracion_veracidad']) ? 1 : 0;
    $activo = isset($_POST['activo']) ? 1 : 0;
    $password_plano = $_POST['password'] ?? '';
    
    // Validaciones básicas
    $errores = [];
    
    if (empty($nombre_distribuidor)) {
        $errores[] = "El nombre del distribuidor es obligatorio";
    }
    
    if (empty($telefono)) {
        $errores[] = "El teléfono es obligatorio";
    }
    
    if (empty($email)) {
        $errores[] = "El email es obligatorio";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errores[] = "El email no es válido";
    }
    
    if (empty($rfc)) {
        $errores[] = "El RFC es obligatorio";
    } elseif (!preg_match('/^[A-Z&Ñ]{3,4}[0-9]{6}[A-Z0-9]{3}$/', strtoupper($rfc))) {
        $errores[] = "El RFC no tiene un formato válido";
    }
    
    if (empty($banco)) {
        $errores[] = "El banco es obligatorio";
    }
    
    if (empty($numero_cuenta)) {
        $errores[] = "El número de cuenta es obligatorio";
    }
    
    // Verificar si el email ya existe (excepto el del distribuidor actual)
    if (empty($errores)) {
        $check_email = $conn->prepare("SELECT id FROM distribuidores WHERE email = ? AND id != ?");
        $check_email->bind_param("si", $email, $id);
        $check_email->execute();
        $check_email->store_result();
        
        if ($check_email->num_rows > 0) {
            $errores[] = "El email ya está registrado por otro distribuidor";
        }
        $check_email->close();
    }
    
    // Procesar archivos
    $directorio_base = "uploads/distribuidores/";
    $directorio_constancias = $directorio_base . "constancias/";
    $directorio_credenciales = $directorio_base . "credenciales/";

    // Crear directorios si no existen
    if (!file_exists($directorio_constancias)) {
        mkdir($directorio_constancias, 0777, true);
    }
    if (!file_exists($directorio_credenciales)) {
        mkdir($directorio_credenciales, 0777, true);
    }

    // Procesar constancia fiscal
    $constancia_fiscal = $distribuidor['constancia_fiscal'];
    $fecha_subida_constancia = $distribuidor['fecha_subida_constancia'];

    if (isset($_FILES['constancia_fiscal']) && $_FILES['constancia_fiscal']['error'] === UPLOAD_ERR_OK) {
        $archivo = $_FILES['constancia_fiscal'];
        $extension = strtolower(pathinfo($archivo['name'], PATHINFO_EXTENSION));
        
        if (!in_array($extension, ['pdf', 'jpg', 'jpeg', 'png'])) {
            $errores[] = "La constancia fiscal debe ser PDF, JPG o PNG";
        } else {
            $nombre_archivo = $distribuidor['numero_control'] . '_constancia.' . $extension;
            $ruta_destino = $directorio_constancias . $nombre_archivo;
            
            if (move_uploaded_file($archivo['tmp_name'], $ruta_destino)) {
                // Eliminar archivo anterior si existe
                if (!empty($constancia_fiscal) && file_exists($constancia_fiscal)) {
                    unlink($constancia_fiscal);
                }
                $constancia_fiscal = $directorio_constancias . $nombre_archivo;
                $fecha_subida_constancia = date('Y-m-d H:i:s');
            } else {
                $errores[] = "Error al subir la constancia fiscal";
            }
        }
    }

    // Procesar credencial/identificación
    $credencial_identificacion = $distribuidor['credencial_identificacion'];
    $fecha_subida_credencial = $distribuidor['fecha_subida_credencial'];

    if (isset($_FILES['credencial_identificacion']) && $_FILES['credencial_identificacion']['error'] === UPLOAD_ERR_OK) {
        $archivo = $_FILES['credencial_identificacion'];
        $extension = strtolower(pathinfo($archivo['name'], PATHINFO_EXTENSION));
        
        if (!in_array($extension, ['pdf', 'jpg', 'jpeg', 'png'])) {
            $errores[] = "La identificación debe ser PDF, JPG o PNG";
        } else {
            $nombre_archivo = $distribuidor['numero_control'] . '_credencial.' . $extension;
            $ruta_destino = $directorio_credenciales . $nombre_archivo;
            
            if (move_uploaded_file($archivo['tmp_name'], $ruta_destino)) {
                // Eliminar archivo anterior si existe
                if (!empty($credencial_identificacion) && file_exists($credencial_identificacion)) {
                    unlink($credencial_identificacion);
                }
                $credencial_identificacion = $directorio_credenciales . $nombre_archivo;
                $fecha_subida_credencial = date('Y-m-d H:i:s');
            } else {
                $errores[] = "Error al subir la identificación";
            }
        }
    }
    
    // Si no hay errores, actualizar en la base de datos
    if (empty($errores)) {
        
        // Si se proporcionó una nueva contraseña, actualizarla
        if (!empty($password_plano)) {
            if (strlen($password_plano) < 6) {
                $errores[] = "La contraseña debe tener al menos 6 caracteres";
            } else {
                $password_hash = password_hash($password_plano, PASSWORD_DEFAULT);
                $sql = "UPDATE distribuidores SET 
                            nombre_distribuidor = ?,
                            telefono = ?,
                            email = ?,
                            rfc = ?,
                            banco = ?,
                            numero_cuenta = ?,
                            password = ?,
                            constancia_fiscal = ?,
                            credencial_identificacion = ?,
                            fecha_subida_constancia = ?,
                            fecha_subida_credencial = ?,
                            declaracion_veracidad = ?,
                            estado_verificacion = ?,
                            observaciones_verificacion = ?,
                            activo = ?,
                            fecha_verificacion = CASE 
                                WHEN ? != estado_verificacion AND ? IN ('aprobado', 'rechazado') 
                                THEN NOW() 
                                ELSE fecha_verificacion 
                            END
                        WHERE id = ?";
                
                $stmt = $conn->prepare($sql);
                $stmt->bind_param(
                    "ssssssssssisssssi",
                    $nombre_distribuidor,
                    $telefono,
                    $email,
                    $rfc,
                    $banco,
                    $numero_cuenta,
                    $password_hash,
                    $constancia_fiscal,
                    $credencial_identificacion,
                    $fecha_subida_constancia,
                    $fecha_subida_credencial,
                    $declaracion_veracidad,
                    $estado_verificacion,
                    $observaciones,
                    $activo,
                    $estado_verificacion,
                    $estado_verificacion,
                    $id
                );
            }
        } else {
            // No actualizar contraseña
            $sql = "UPDATE distribuidores SET 
                        nombre_distribuidor = ?,
                        telefono = ?,
                        email = ?,
                        rfc = ?,
                        banco = ?,
                        numero_cuenta = ?,
                        constancia_fiscal = ?,
                        credencial_identificacion = ?,
                        fecha_subida_constancia = ?,
                        fecha_subida_credencial = ?,
                        declaracion_veracidad = ?,
                        estado_verificacion = ?,
                        observaciones_verificacion = ?,
                        activo = ?,
                        fecha_verificacion = CASE 
                            WHEN ? != estado_verificacion AND ? IN ('aprobado', 'rechazado') 
                            THEN NOW() 
                            ELSE fecha_verificacion 
                        END
                    WHERE id = ?";
            
            $stmt = $conn->prepare($sql);
            $stmt->bind_param(
                "ssssssssssissssi",
                $nombre_distribuidor,
                $telefono,
                $email,
                $rfc,
                $banco,
                $numero_cuenta,
                $constancia_fiscal,
                $credencial_identificacion,
                $fecha_subida_constancia,
                $fecha_subida_credencial,
                $declaracion_veracidad,
                $estado_verificacion,
                $observaciones,
                $activo,
                $estado_verificacion,
                $estado_verificacion,
                $id
            );
        }
        
        if (isset($stmt) && $stmt->execute()) {
            $mensaje = "Distribuidor actualizado exitosamente";
            $tipo_mensaje = "success";
            
            // Actualizar datos del distribuidor
            $sql = "SELECT * FROM distribuidores WHERE id = ?";
            $stmt2 = $conn->prepare($sql);
            $stmt2->bind_param("i", $id);
            $stmt2->execute();
            $result2 = $stmt2->get_result();
            $distribuidor = $result2->fetch_assoc();
            $stmt2->close();
            
        } else if (isset($stmt)) {
            $mensaje = "Error al actualizar el distribuidor: " . $stmt->error;
            $tipo_mensaje = "danger";
        }
        
        if (isset($stmt)) {
            $stmt->close();
        }
    } else {
        $mensaje = implode("<br>", $errores);
        $tipo_mensaje = "danger";
    }
}

$conn->close();

// Función para obtener la clase del estado
function claseEstado($estado) {
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
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Editar Distribuidor - Panel de Administración</title>
    <link rel="icon" href="/../images/favicon.ico" type="image/x-icon">
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- jQuery -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <style>
        :root {
            --primary-color: #27ae60;
            --secondary-color: #2ecc71;
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
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
        }

        .btn-primary {
            background-color: var(--primary-color);
            border-color: var(--primary-color);
        }

        .btn-primary:hover {
            background-color: var(--secondary-color);
            border-color: var(--secondary-color);
        }

        .form-label {
            font-weight: 500;
            color: #2c3e50;
        }

        .required:after {
            content: " *";
            color: red;
        }

        .documento-actual {
            background-color: #f8f9fa;
            padding: 10px;
            border-radius: 5px;
            border-left: 3px solid var(--primary-color);
        }

        @media (max-width: 767.98px) {
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
                transition: transform 0.3s ease;
            }

            .sidebar.show {
                transform: translateX(0);
            }

            .sidebar-toggle {
                display: block;
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
        }
    </style>
</head>

<body>
    <!-- Navbar -->
    <nav class="navbar navbar-expand-lg navbar-dark">
        <div class="container-fluid">
            <button class="sidebar-toggle d-md-none" type="button" id="sidebarToggle">
                <i class="fas fa-bars"></i>
            </button>

            <a class="navbar-brand d-flex align-items-center" href="#">
                <img src="../images/LibertyfinBlanco.png" alt="Logo" class="me-2" style="height: 30px;">
                <span>Panel de Administración</span>
            </a>

            <div class="navbar-nav ms-auto">
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown">
                        <i class="fas fa-user-circle me-1"></i>
                        <?php echo htmlspecialchars($_SESSION['usuario_nombre'] ?? 'Admin'); ?>
                    </a>
                    <ul class="dropdown-menu dropdown-menu-end">
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
                            <a class="nav-link" href="dashboard.php">
                                <i class="fas fa-tachometer-alt"></i>
                                Dashboard
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="empresas.php">
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
                            <a class="nav-link" href="solicitudes.php">
                                <i class="fas fa-file-alt"></i>
                                Solicitudes
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link active" href="distribuidores.php">
                                <i class="fas fa-users"></i>
                                Distribuidores
                            </a>
                        </li>
                    </ul>
                </div>
            </div>

            <!-- Main Content -->
            <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 py-4">
                <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                    <h1 class="h2">Editar Distribuidor</h1>
                    <div class="btn-toolbar mb-2 mb-md-0">
                        <a href="distribuidores.php" class="btn btn-sm btn-outline-secondary">
                            <i class="fas fa-arrow-left me-1"></i> Volver
                        </a>
                    </div>
                </div>

                <?php if (!empty($mensaje)): ?>
                <div class="alert alert-<?php echo $tipo_mensaje; ?> alert-dismissible fade show" role="alert">
                    <?php echo $mensaje; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
                <?php endif; ?>

                <div class="card">
                    <div class="card-body">
                        <form method="POST" action="" enctype="multipart/form-data">
                            <div class="row">
                                <div class="col-md-12 mb-3">
                                    <h5 class="border-bottom pb-2">Información del Distribuidor</h5>
                                    <div class="alert alert-info">
                                        <strong>Número de Control:</strong> <?php echo htmlspecialchars($distribuidor['numero_control']); ?>
                                    </div>
                                </div>
                                
                                <div class="col-md-6 mb-3">
                                    <label for="nombre_distribuidor" class="form-label required">Nombre del Distribuidor</label>
                                    <input type="text" class="form-control" id="nombre_distribuidor" name="nombre_distribuidor" 
                                           value="<?php echo htmlspecialchars($distribuidor['nombre_distribuidor']); ?>" required>
                                </div>
                                
                                <div class="col-md-6 mb-3">
                                    <label for="rfc" class="form-label required">RFC</label>
                                    <input type="text" class="form-control" id="rfc" name="rfc" 
                                           value="<?php echo htmlspecialchars($distribuidor['rfc']); ?>" 
                                           placeholder="Ej: ABC123456XYZ" required
                                           maxlength="13" style="text-transform:uppercase">
                                </div>
                                
                                <div class="col-md-6 mb-3">
                                    <label for="telefono" class="form-label required">Teléfono</label>
                                    <input type="tel" class="form-control" id="telefono" name="telefono" 
                                           value="<?php echo htmlspecialchars($distribuidor['telefono']); ?>" required>
                                </div>
                                
                                <div class="col-md-6 mb-3">
                                    <label for="email" class="form-label required">Email</label>
                                    <input type="email" class="form-control" id="email" name="email" 
                                           value="<?php echo htmlspecialchars($distribuidor['email']); ?>" required>
                                </div>
                                
                                <div class="col-md-6 mb-3">
                                    <label for="password" class="form-label">Nueva Contraseña</label>
                                    <input type="password" class="form-control" id="password" name="password" 
                                           minlength="6">
                                    <small class="text-muted">Dejar en blanco para mantener la contraseña actual. Mínimo 6 caracteres si se cambia.</small>
                                </div>
                                
                                <div class="col-md-12 mb-3">
                                    <h5 class="border-bottom pb-2">Información Bancaria</h5>
                                </div>
                                
                                <div class="col-md-6 mb-3">
                                    <label for="banco" class="form-label required">Banco</label>
                                    <select class="form-select" id="banco" name="banco" required>
                                        <option value="">Seleccione un banco</option>
                                        <option value="BBVA" <?php echo $distribuidor['banco'] == 'BBVA' ? 'selected' : ''; ?>>BBVA</option>
                                        <option value="Santander" <?php echo $distribuidor['banco'] == 'Santander' ? 'selected' : ''; ?>>Santander</option>
                                        <option value="Banamex" <?php echo $distribuidor['banco'] == 'Banamex' ? 'selected' : ''; ?>>Banamex</option>
                                        <option value="Banorte" <?php echo $distribuidor['banco'] == 'Banorte' ? 'selected' : ''; ?>>Banorte</option>
                                        <option value="HSBC" <?php echo $distribuidor['banco'] == 'HSBC' ? 'selected' : ''; ?>>HSBC</option>
                                        <option value="Scotiabank" <?php echo $distribuidor['banco'] == 'Scotiabank' ? 'selected' : ''; ?>>Scotiabank</option>
                                        <option value="Inbursa" <?php echo $distribuidor['banco'] == 'Inbursa' ? 'selected' : ''; ?>>Inbursa</option>
                                        <option value="Azteca" <?php echo $distribuidor['banco'] == 'Azteca' ? 'selected' : ''; ?>>Azteca</option>
                                        <option value="Otro" <?php echo $distribuidor['banco'] == 'Otro' ? 'selected' : ''; ?>>Otro</option>
                                    </select>
                                </div>
                                
                                <div class="col-md-6 mb-3">
                                    <label for="numero_cuenta" class="form-label required">Número de Cuenta</label>
                                    <input type="text" class="form-control" id="numero_cuenta" name="numero_cuenta" 
                                           value="<?php echo htmlspecialchars($distribuidor['numero_cuenta']); ?>" 
                                           placeholder="Número de cuenta o CLABE" required>
                                </div>
                                
                                <div class="col-md-12 mb-3">
                                    <h5 class="border-bottom pb-2">Estado y Verificación</h5>
                                </div>
                                
                                <div class="col-md-6 mb-3">
                                    <label for="estado_verificacion" class="form-label">Estado de Verificación</label>
                                    <select class="form-select" id="estado_verificacion" name="estado_verificacion">
                                        <option value="pendiente" <?php echo $distribuidor['estado_verificacion'] == 'pendiente' ? 'selected' : ''; ?>>Pendiente</option>
                                        <option value="en_revision" <?php echo $distribuidor['estado_verificacion'] == 'en_revision' ? 'selected' : ''; ?>>En Revisión</option>
                                        <option value="aprobado" <?php echo $distribuidor['estado_verificacion'] == 'aprobado' ? 'selected' : ''; ?>>Aprobado</option>
                                        <option value="rechazado" <?php echo $distribuidor['estado_verificacion'] == 'rechazado' ? 'selected' : ''; ?>>Rechazado</option>
                                    </select>
                                </div>
                                
                                <div class="col-md-6 mb-3">
                                    <div class="form-check mt-4">
                                        <input class="form-check-input" type="checkbox" id="activo" name="activo" 
                                               <?php echo $distribuidor['activo'] ? 'checked' : ''; ?>>
                                        <label class="form-check-label" for="activo">
                                            Distribuidor Activo
                                        </label>
                                    </div>
                                </div>
                                
                                <div class="col-md-12 mb-3">
                                    <label for="observaciones" class="form-label">Observaciones de Verificación</label>
                                    <textarea class="form-control" id="observaciones" name="observaciones" rows="3"><?php echo htmlspecialchars($distribuidor['observaciones_verificacion'] ?? ''); ?></textarea>
                                </div>
                                
                                <div class="col-md-12 mb-3">
                                    <h5 class="border-bottom pb-2">Documentos</h5>
                                </div>
                                
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Constancia Fiscal Actual</label>
                                    <?php if (!empty($distribuidor['constancia_fiscal'])): ?>
                                        <div class="documento-actual mb-2">
                                            <i class="fas fa-file-pdf text-danger me-2"></i>
                                            <?php echo basename($distribuidor['constancia_fiscal']); ?>
                                            <button type="button" class="btn btn-sm btn-link ver-archivo"
                                                    data-archivo="/Distribuidor/uploads/distribuidores/constancias/<?php echo htmlspecialchars($distribuidor['constancia_fiscal']); ?>"
                                                    data-tipo="<?php echo strpos($distribuidor['constancia_fiscal'], '.pdf') !== false ? 'pdf' : 'imagen'; ?>"
                                                    data-nombre="<?php echo basename($distribuidor['constancia_fiscal']); ?>"
                                                    data-titulo="Constancia Fiscal - <?php echo htmlspecialchars($distribuidor['nombre_distribuidor']); ?>">
                                                <i class="fas fa-eye"></i> Ver
                                            </button>
                                        </div>
                                    <?php else: ?>
                                        <p class="text-muted">No hay constancia fiscal</p>
                                    <?php endif; ?>
                                    
                                    <label for="constancia_fiscal" class="form-label">Actualizar Constancia Fiscal</label>
                                    <input type="file" class="form-control" id="constancia_fiscal" name="constancia_fiscal" 
                                           accept=".pdf,.jpg,.jpeg,.png">
                                    <small class="text-muted">Formatos: PDF, JPG, PNG (Máx. 5MB)</small>
                                </div>
                                
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Credencial/Identificación Actual</label>
                                    <?php if (!empty($distribuidor['credencial_identificacion'])): ?>
                                        <div class="documento-actual mb-2">
                                            <i class="fas fa-file-pdf text-danger me-2"></i>
                                            <?php echo basename($distribuidor['credencial_identificacion']); ?>
                                            <button type="button" class="btn btn-sm btn-link ver-archivo"
                                                    data-archivo="/Distribuidor/uploads/distribuidores/credenciales/<?php echo htmlspecialchars($distribuidor['credencial_identificacion']); ?>"
                                                    data-tipo="<?php echo strpos($distribuidor['credencial_identificacion'], '.pdf') !== false ? 'pdf' : 'imagen'; ?>"
                                                    data-nombre="<?php echo basename($distribuidor['credencial_identificacion']); ?>"
                                                    data-titulo="Credencial/Identificación - <?php echo htmlspecialchars($distribuidor['nombre_distribuidor']); ?>">
                                                <i class="fas fa-eye"></i> Ver
                                            </button>
                                        </div>
                                    <?php else: ?>
                                        <p class="text-muted">No hay identificación</p>
                                    <?php endif; ?>
                                    
                                    <label for="credencial_identificacion" class="form-label">Actualizar Identificación</label>
                                    <input type="file" class="form-control" id="credencial_identificacion" name="credencial_identificacion" 
                                           accept=".pdf,.jpg,.jpeg,.png">
                                    <small class="text-muted">Formatos: PDF, JPG, PNG (Máx. 5MB)</small>
                                </div>
                                
                                <div class="col-md-12 mb-3">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" id="declaracion_veracidad" name="declaracion_veracidad" 
                                               <?php echo $distribuidor['declaracion_veracidad'] ? 'checked' : ''; ?>>
                                        <label class="form-check-label" for="declaracion_veracidad">
                                            Declaración de veracidad aceptada
                                        </label>
                                    </div>
                                </div>
                            </div>
                            
                            <hr>
                            
                            <div class="d-flex justify-content-end gap-2">
                                <a href="distribuidores.php" class="btn btn-secondary">Cancelar</a>
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-save me-1"></i> Actualizar Distribuidor
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        // Sidebar functionality
        document.addEventListener('DOMContentLoaded', function() {
            const sidebar = document.getElementById('sidebar');
            const sidebarToggle = document.getElementById('sidebarToggle');
            const sidebarBackdrop = document.getElementById('sidebarBackdrop');
            
            function toggleSidebar() {
                sidebar.classList.toggle('show');
                sidebarBackdrop.classList.toggle('show');
            }
            
            if (sidebarToggle) {
                sidebarToggle.addEventListener('click', toggleSidebar);
            }
            
            if (sidebarBackdrop) {
                sidebarBackdrop.addEventListener('click', toggleSidebar);
            }
            
            // Convertir RFC a mayúsculas
            const rfcInput = document.getElementById('rfc');
            if (rfcInput) {
                rfcInput.addEventListener('input', function() {
                    this.value = this.value.toUpperCase();
                });
            }
        });

        // Función para ver archivos
        window.abrirArchivoModal = function(rutaArchivo, tipoArchivo, nombreArchivo, titulo) {
            window.open(rutaArchivo, '_blank');
        };

        // Delegación de eventos para botones de archivos
        $(document).on('click', '.ver-archivo', function(e) {
            e.preventDefault();
            const ruta = $(this).data('archivo');
            const tipo = $(this).data('tipo');
            const nombre = $(this).data('nombre');
            const titulo = $(this).data('titulo');

            if (ruta) {
                window.open(ruta, '_blank');
            }
        });
    </script>
</body>

</html>