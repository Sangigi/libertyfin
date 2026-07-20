<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0">
    <title>Registro de Empresa - Libertyfin</title>
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <style>
    :root {
        --primary-color: #27ae60;
        --primary-dark: #219653;
        --secondary-color: #2c3e50;
        --light-bg: #f8f9fa;
        --card-shadow: 0 4px 12px rgba(0, 0, 0, 0.08);
    }

    body {
        background: linear-gradient(135deg, var(--primary-color) 0%, var(--primary-dark) 100%);
        min-height: 100vh;
        font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        padding-top: 1rem;
        padding-bottom: 2rem;
    }

    .card {
        border: none;
        border-radius: 12px;
        box-shadow: var(--card-shadow);
        background-color: white;
    }

    .card-header {
        background: var(--primary-color);
        color: white;
        border-bottom: none;
        padding: 1.5rem;
        border-radius: 12px 12px 0 0 !important;
    }

    /* Paso Indicator */
    .step-container {
        margin-bottom: 2rem;
    }

    .step-progress {
        display: flex;
        justify-content: space-between;
        position: relative;
        margin: 0 auto;
        max-width: 800px;
    }

    .step-progress:before {
        content: '';
        position: absolute;
        top: 50%;
        left: 0;
        right: 0;
        height: 3px;
        background-color: #dee2e6;
        transform: translateY(-50%);
        z-index: 1;
    }

    .step-progress-line {
        position: absolute;
        top: 50%;
        left: 0;
        height: 3px;
        background-color: var(--primary-color);
        transform: translateY(-50%);
        transition: width 0.3s ease;
        z-index: 2;
    }

    .step-item {
        position: relative;
        z-index: 3;
        display: flex;
        flex-direction: column;
        align-items: center;
        width: 100px;
    }

    .step-circle {
        width: 40px;
        height: 40px;
        border-radius: 50%;
        background-color: white;
        border: 3px solid #dee2e6;
        display: flex;
        align-items: center;
        justify-content: center;
        font-weight: bold;
        color: #6c757d;
        transition: all 0.3s ease;
        margin-bottom: 0.5rem;
    }

    .step-item.active .step-circle {
        background-color: var(--primary-color);
        border-color: var(--primary-color);
        color: white;
        transform: scale(1.1);
    }

    .step-item.completed .step-circle {
        background-color: var(--primary-color);
        border-color: var(--primary-color);
        color: white;
    }

    .step-item.completed .step-circle:after {
        content: '✓';
    }

    .step-label {
        font-size: 0.85rem;
        text-align: center;
        color: #6c757d;
        font-weight: 500;
    }

    .step-item.active .step-label {
        color: var(--primary-color);
        font-weight: 600;
    }

    .step-item.completed .step-label {
        color: var(--primary-dark);
    }

    /* Contenido del formulario */
    .form-step {
        display: none;
        animation: fadeIn 0.5s ease;
    }

    .form-step.active {
        display: block;
    }

    @keyframes fadeIn {
        from {
            opacity: 0;
            transform: translateY(20px);
        }

        to {
            opacity: 1;
            transform: translateY(0);
        }
    }

    /* Botones de navegación */
    .btn-navigation {
        padding: 0.75rem 2rem;
        font-weight: 500;
        transition: all 0.3s ease;
    }

    .btn-next {
        background-color: var(--primary-color);
        border-color: var(--primary-color);
        color: white;
    }

    .btn-next:hover {
        background-color: var(--primary-dark);
        border-color: var(--primary-dark);
        transform: translateY(-2px);
        box-shadow: 0 4px 8px rgba(39, 174, 96, 0.3);
    }

    .btn-prev {
        background-color: #6c757d;
        border-color: #6c757d;
        color: white;
    }

    .btn-prev:hover {
        background-color: #5a6268;
        border-color: #545b62;
    }

    /* Responsividad para los pasos */
    @media (max-width: 768px) {
        .step-container {
            padding: 0 1rem;
            margin-bottom: 1.5rem;
        }

        .step-item {
            width: 70px;
        }

        .step-circle {
            width: 35px;
            height: 35px;
            font-size: 0.9rem;
        }

        .step-label {
            font-size: 0.75rem;
        }

        .step-progress:before {
            height: 2px;
        }

        .step-progress-line {
            height: 2px;
        }
    }

    @media (max-width: 576px) {
        .step-item {
            width: 60px;
        }

        .step-circle {
            width: 30px;
            height: 30px;
            font-size: 0.8rem;
            margin-bottom: 0.25rem;
        }

        .step-label {
            font-size: 0.7rem;
        }
    }

    /* Estilos generales del formulario */
    .form-control,
    .form-select {
        border: 1.5px solid #dee2e6;
        border-radius: 8px;
        padding: 0.75rem 1rem;
        transition: all 0.3s ease;
    }

    .form-control:focus,
    .form-select:focus {
        border-color: var(--primary-color);
        box-shadow: 0 0 0 0.25rem rgba(39, 174, 96, 0.15);
    }

    .required:after {
        content: " *";
        color: #e74c3c;
        font-weight: bold;
    }

    .optional {
        color: #6c757d;
        font-size: 0.85rem;
        font-weight: normal;
    }

    .logo-container {
        padding: 1rem 0;
        background: rgba(255, 255, 255, 0.1);
        border-radius: 10px;
        margin-bottom: 1.5rem;
        backdrop-filter: blur(10px);
    }

    .logo-horizontal {
        max-width: 100%;
        height: auto;
        max-height: 70px;
    }

    @media (max-width: 576px) {
        .logo-horizontal {
            max-height: 50px;
        }
    }

    .section-title {
        color: var(--secondary-color);
        font-weight: 600;
        margin-bottom: 1.5rem;
        padding-bottom: 0.75rem;
        border-bottom: 2px solid var(--primary-color);
    }

    .file-info {
        font-size: 0.85rem;
        color: #6c757d;
        margin-top: 0.25rem;
        display: block;
    }

    .optional-badge {
        background-color: #e9ecef;
        color: #6c757d;
        font-size: 0.75rem;
        padding: 0.25rem 0.5rem;
        border-radius: 4px;
        margin-left: 0.5rem;
        font-weight: normal;
    }

    .required-badge {
        background-color: #e74c3c !important;
        color: white !important;
        font-size: 0.75rem;
        padding: 0.25rem 0.5rem;
        border-radius: 4px;
        margin-left: 0.5rem;
        font-weight: normal;
    }

    /* Declaración condicional */
    .declaracion-condicional {
        background-color: #f8f9fa;
        border-radius: 8px;
        padding: 1.5rem;
        margin-top: 1.5rem;
        border-left: 4px solid #27ae60;
        transition: all 0.3s ease;
    }

    .declaracion-requerida {
        border-left-color: #e74c3c;
        background-color: #fff5f5;
    }

    .declaracion-texto {
        color: #495057;
        line-height: 1.6;
    }

    /* Resumen de datos */
    .summary-item {
        background-color: var(--light-bg);
        border-radius: 8px;
        padding: 1rem;
        margin-bottom: 1rem;
        border-left: 4px solid var(--primary-color);
    }

    .summary-label {
        font-weight: 600;
        color: var(--secondary-color);
        margin-bottom: 0.25rem;
    }

    .summary-value {
        color: #495057;
    }

    .summary-optional {
        color: #6c757d;
        font-style: italic;
    }

    .summary-error {
        color: #e74c3c;
        font-weight: 500;
    }

    /* Spinner */
    .spinner-border {
        width: 1rem;
        height: 1rem;
        margin-right: 0.5rem;
    }

    /* Validación */
    .is-invalid {
        border-color: #e74c3c !important;
    }

    .is-valid {
        border-color: var(--primary-color) !important;
    }

    /* Campos opcionales */
    .optional-field {
        opacity: 0.9;
    }

    .optional-field .form-label {
        color: #6c757d;
    }

    .required-field {
        border-left: 4px solid #e74c3c !important;
    }

    /* Alertas informativas */
    .alert-info {
        background-color: #e7f5ff;
        border-color: #a5d8ff;
        color: #1864ab;
    }

    .alert-warning {
        background-color: #fff9db;
        border-color: #ffd43b;
        color: #e67700;
    }

    .alert-required {
        background-color: #fff5f5;
        border-color: #ffa8a8;
        color: #e03131;
    }

    /* Estado condicional */
    .condicional-text {
        font-size: 0.9rem;
        color: #6c757d;
        font-style: italic;
        margin-top: 0.5rem;
    }

    .file-required {
        font-weight: 600;
        color: #e74c3c;
    }

    /* ============================================ */
    /* ESTILOS MEJORADOS PARA CREDENCIALES DE ACCESO */
    /* ============================================ */
    
    .registro-exitoso {
        animation: fadeIn 0.5s ease;
    }
    
    .registro-exitoso .card {
        border: 2px solid var(--primary-color);
        box-shadow: 0 8px 16px rgba(39, 174, 96, 0.2);
        overflow: hidden;
    }
    
    /* Tabla de credenciales */
    .registro-exitoso .table {
        margin-bottom: 0;
        table-layout: fixed;
        width: 100%;
        border-collapse: separate;
        border-spacing: 0;
    }
    
    .registro-exitoso .table th {
        width: 30%;
        background-color: #f8f9fa;
        font-weight: 600;
        color: var(--secondary-color);
        vertical-align: middle;
        padding: 12px;
        border: 1px solid #dee2e6;
        border-right: none;
    }
    
    .registro-exitoso .table td {
        word-break: break-word;
        overflow-wrap: break-word;
        vertical-align: middle;
        padding: 12px;
        border: 1px solid #dee2e6;
        border-left: none;
    }
    
    .registro-exitoso .table tr:first-child th,
    .registro-exitoso .table tr:first-child td {
        border-top: 1px solid #dee2e6;
    }
    
    .registro-exitoso .table code {
        background-color: #f1f3f5;
        padding: 6px 10px;
        border-radius: 6px;
        font-size: 0.95rem;
        color: #e83e8c;
        border: 1px solid #dee2e6;
        display: inline-block;
        max-width: 100%;
        overflow-wrap: break-word;
        word-break: break-all;
        font-family: 'Courier New', monospace;
    }
    
    /* Contenedor de contraseña con botón */
    .registro-exitoso .d-flex {
        display: flex;
        align-items: center;
        gap: 10px;
        flex-wrap: wrap;
    }
    
    .registro-exitoso .btn-outline-secondary {
        white-space: nowrap;
        padding: 6px 12px;
        font-size: 0.9rem;
        border-radius: 6px;
        transition: all 0.2s ease;
    }
    
    .registro-exitoso .btn-outline-secondary:hover {
        background-color: var(--primary-color);
        color: white;
        border-color: var(--primary-color);
    }
    
    /* Badge de período de prueba */
    .registro-exitoso .badge {
        font-size: 0.9rem;
        padding: 8px 12px !important;
        border-radius: 20px;
        font-weight: 500;
    }
    
    /* Panel de acceso al sistema */
    .registro-exitoso .bg-light {
        background-color: #f8f9fa !important;
        border-radius: 10px;
        border-left: 4px solid var(--primary-color);
    }
    
    .registro-exitoso .btn-success {
        background-color: var(--primary-color);
        border-color: var(--primary-color);
        padding: 10px 20px;
        font-weight: 500;
        border-radius: 8px;
        transition: all 0.3s ease;
    }
    
    .registro-exitoso .btn-success:hover {
        background-color: var(--primary-dark);
        border-color: var(--primary-dark);
        transform: translateY(-2px);
        box-shadow: 0 4px 8px rgba(39, 174, 96, 0.3);
    }
    
    /* Footer con botones */
    .registro-exitoso .card-footer {
        background-color: white;
        border-top: 1px solid rgba(0,0,0,0.1);
        padding: 15px;
    }
    
    .registro-exitoso .card-footer .d-flex {
        gap: 10px;
    }
    
    .registro-exitoso .card-footer .btn {
        padding: 8px 16px;
        font-weight: 500;
        border-radius: 8px;
        transition: all 0.2s ease;
    }
    
    .registro-exitoso .card-footer .btn-outline-success {
        color: var(--primary-color);
        border-color: var(--primary-color);
    }
    
    .registro-exitoso .card-footer .btn-outline-success:hover {
        background-color: var(--primary-color);
        color: white;
        transform: translateY(-2px);
    }
    
    .registro-exitoso .card-footer .btn-outline-primary {
        color: #0d6efd;
        border-color: #0d6efd;
    }
    
    .registro-exitoso .card-footer .btn-outline-primary:hover {
        background-color: #0d6efd;
        color: white;
        transform: translateY(-2px);
    }
    
    /* Responsive para móvil */
    @media (max-width: 768px) {
        .registro-exitoso .table th {
            width: 35%;
            padding: 10px;
            font-size: 0.9rem;
        }
        
        .registro-exitoso .table td {
            padding: 10px;
            font-size: 0.9rem;
        }
        
        .registro-exitoso .table code {
            font-size: 0.85rem;
            padding: 4px 8px;
            word-break: break-all;
        }
    }
    
    @media (max-width: 576px) {
        .registro-exitoso .table th {
            width: 40%;
            padding: 8px;
            font-size: 0.85rem;
        }
        
        .registro-exitoso .table td {
            padding: 8px;
            font-size: 0.85rem;
        }
        
        .registro-exitoso .table code {
            font-size: 0.8rem;
            padding: 3px 6px;
            word-break: break-all;
        }
        
        .registro-exitoso .d-flex {
            flex-direction: column;
            align-items: flex-start !important;
        }
        
        .registro-exitoso .d-flex button {
            margin-left: 0 !important;
            margin-top: 5px;
            width: 100%;
        }
        
        .registro-exitoso .card-footer .d-flex {
            flex-direction: column;
            width: 100%;
        }
        
        .registro-exitoso .card-footer .btn {
            width: 100%;
            margin: 0 !important;
        }
    }
    
    /* Estilos para impresión */
    @media print {
        .step-container, 
        .btn-navigation, 
        .logo-container, 
        .btn:not(.registro-exitoso .btn), 
        .footer {
            display: none !important;
        }
        
        .registro-exitoso {
            print-color-adjust: exact;
            -webkit-print-color-adjust: exact;
        }
        
        .registro-exitoso .card-header {
            background-color: #27ae60 !important;
            color: white !important;
        }
        
        .registro-exitoso .table th {
            background-color: #f0f0f0 !important;
        }
        
        .registro-exitoso .badge {
            background-color: #ffc107 !important;
        }
        
        .registro-exitoso .btn {
            border: 1px solid #ddd !important;
            background-color: white !important;
            color: black !important;
        }
    }
</style>
</head>

<body>
    <?php
    include 'registroEmpresa.php';
    ?>

    <div class="container">
        <div class="row justify-content-center">
            <div class="col-12">
                <!-- Logo -->
                <div class="logo-container text-center mb-4">
                    <img src="images/LibertyfinBlanco.png" alt="Logo LibertyFin"
                        class="logo-horizontal img-fluid">
                </div>

                <?php if ($registro_exitoso): ?>
                    <!-- Mostrar solo el mensaje de éxito con credenciales -->
                    <div class="card">
                        <div class="card-header text-center">
                            <h3 class="mb-0 fw-bold">¡Registro Completado!</h3>
                            <p class="mb-0 mt-2 opacity-75">Bienvenido a LibertyFin</p>
                        </div>
                        <div class="card-body">
                            <?php echo $mensaje; ?>
                            
                            <div class="text-center mt-4">
                                <a href="login.php" class="btn btn-success btn-lg">
                                    <i class="bi bi-box-arrow-in-right"></i> Ir al Login
                                </a>
                                <a href="solicitudEmpresa.php" class="btn btn-outline-secondary btn-lg ms-2">
                                    <i class="bi bi-plus-circle"></i> Nuevo Registro
                                </a>
                            </div>
                        </div>
                    </div>
                <?php else: ?>
                    <!-- Mostrar el formulario normal -->
                    
                    <!-- Indicador de Pasos -->
                    <div class="step-container">
                        <div class="step-progress">
                            <div class="step-progress-line" id="progressLine"></div>
                            <div class="step-item active" data-step="1">
                                <div class="step-circle">1</div>
                                <div class="step-label">Empresa</div>
                            </div>
                            <div class="step-item" data-step="2">
                                <div class="step-circle">2</div>
                                <div class="step-label">Documentos</div>
                            </div>
                            <div class="step-item" data-step="3">
                                <div class="step-circle">3</div>
                                <div class="step-label">Confirmación</div>
                            </div>
                        </div>
                    </div>

                    <!-- Formulario -->
                    <div class="card">
                        <div class="card-header text-center">
                            <h3 class="mb-0 fw-bold" id="formTitle">Registro de Empresa - Paso 1 de 3</h3>
                            <p class="mb-0 mt-2 opacity-75" id="formSubtitle">Información de la Empresa</p>
                        </div>

                        <div class="card-body p-lg-4">
                            <?php if (!empty($mensaje) && $tipo_mensaje == 'danger'): ?>
                                <div class="alert alert-danger alert-dismissible fade show mb-4" role="alert">
                                    <div class="d-flex align-items-center">
                                        <div class="flex-grow-1">
                                            <?php echo $mensaje; ?>
                                        </div>
                                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Cerrar"></button>
                                    </div>
                                </div>
                            <?php endif; ?>

                            <form id="registroForm" class="needs-validation" novalidate method="POST" action="" enctype="multipart/form-data">

                                <!-- Paso 1: Información de la Empresa -->
                                <div class="form-step active" id="step1">
                                    <h4 class="section-title">Información de la Empresa</h4>

                                    <div class="alert alert-info mb-4">
                                        <i class="bi bi-info-circle"></i> Los campos marcados con <span class="required"></span> son obligatorios. Los demás son opcionales.
                                    </div>

                                    <div class="row g-3">
                                        <div class="col-12">
                                            <div class="mb-3">
                                                <label for="nombre_empresa" class="form-label required">Nombre de la Empresa</label>
                                                <input type="text" class="form-control" id="nombre_empresa" name="nombre_empresa"
                                                    value="<?php echo isset($_POST['nombre_empresa']) ? htmlspecialchars($_POST['nombre_empresa']) : ''; ?>"
                                                    required placeholder="Ingrese el nombre comercial">
                                                <div class="invalid-feedback">
                                                    Por favor, ingrese el nombre de la empresa.
                                                </div>
                                            </div>
                                        </div>

                                        <div class="col-12 col-md-6">
                                            <div class="mb-3">
                                                <label for="giro_comercial" class="form-label required">Giro Comercial</label>
                                                <select class="form-select" id="giro_comercial" name="giro_comercial" required>
                                                    <option value="">Seleccione un giro comercial</option>
                                                    <?php
                                                    // Mostrar opciones desde la variable $giros_comerciales
                                                    if (!empty($giros_comerciales)) {
                                                        foreach ($giros_comerciales as $giro) {
                                                            $selected = '';
                                                            if (isset($_POST['giro_comercial']) && $_POST['giro_comercial'] == $giro['id']) {
                                                                $selected = 'selected';
                                                            }
                                                            echo '<option value="' . htmlspecialchars($giro['id']) . '" ' . $selected . '>';
                                                            echo htmlspecialchars($giro['nombre']);
                                                            echo '</option>';
                                                        }
                                                    }
                                                    ?>
                                                </select>
                                                <div class="invalid-feedback">
                                                    Por favor, seleccione el giro comercial de su empresa.
                                                </div>
                                            </div>
                                        </div>

                                        <div class="col-12 col-md-6">
                                            <div class="mb-3 optional-field">
                                                <label for="rfc" class="form-label">
                                                    RFC <span class="optional">(Opcional)</span>
                                                </label>
                                                <input type="text" class="form-control" id="rfc" name="rfc"
                                                    placeholder="Ej: ABC123456XYZ" maxlength="13"
                                                    value="<?php echo isset($_POST['rfc']) ? htmlspecialchars($_POST['rfc']) : ''; ?>">
                                                <div class="form-text">
                                                    Si no cuenta con RFC, puede dejarlo en blanco
                                                </div>
                                            </div>
                                        </div>

                                        <!-- NUEVO CAMPO: No. Distribuidor (No. Control) -->
                                        <div class="col-12 col-md-6">
                                            <div class="mb-3 optional-field">
                                                <label for="no_distribuidor" class="form-label">
                                                    No. Control / No. Distribuidor <span class="optional">(Opcional)</span>
                                                </label>
                                                <input type="text" class="form-control" id="no_distribuidor" name="no_distribuidor"
                                                    placeholder="Ej: 1234567" maxlength="7"
                                                    value="<?php echo isset($_POST['no_distribuidor']) ? htmlspecialchars($_POST['no_distribuidor']) : ''; ?>">
                                                <div class="form-text">
                                                    Número de control o distribuidor (máximo 7 caracteres)
                                                </div>
                                            </div>
                                        </div>

                                        <div class="col-12 col-md-6">
                                            <div class="mb-3">
                                                <label for="telefono" class="form-label required">Teléfono</label>
                                                <input type="tel" class="form-control" id="telefono" name="telefono"
                                                    value="<?php echo isset($_POST['telefono']) ? htmlspecialchars($_POST['telefono']) : ''; ?>"
                                                    required placeholder="Ej: (55) 1234-5678">
                                                <div class="invalid-feedback">
                                                    Ingrese un número de teléfono válido.
                                                </div>
                                            </div>
                                        </div>

                                        <div class="col-12 col-md-6">
                                            <div class="mb-3">
                                                <label for="nombre_contacto" class="form-label required">Nombre del Contacto</label>
                                                <input type="text" class="form-control" id="nombre_contacto" name="nombre_contacto"
                                                    value="<?php echo isset($_POST['nombre_contacto']) ? htmlspecialchars($_POST['nombre_contacto']) : ''; ?>"
                                                    required placeholder="Nombre del responsable">
                                                <div class="invalid-feedback">
                                                    Ingrese el nombre del contacto principal.
                                                </div>
                                            </div>
                                        </div>

                                        <div class="col-12 col-md-6">
                                            <div class="mb-3">
                                                <label for="email_admin" class="form-label required">Email del Administrador</label>
                                                <input type="email" class="form-control" id="email_admin" name="email_admin"
                                                    value="<?php echo isset($_POST['email_admin']) ? htmlspecialchars($_POST['email_admin']) : ''; ?>"
                                                    required placeholder="admin@empresa.com">
                                                <div class="invalid-feedback">
                                                    Ingrese un email válido para el administrador.
                                                </div>
                                                <div class="form-text">
                                                    En este email recibirá las credenciales de acceso
                                                </div>
                                            </div>
                                        </div>

                                        <div class="col-12">
                                            <div class="mb-3">
                                                <label for="direccion" class="form-label required">Dirección Fiscal</label>
                                                <textarea class="form-control" id="direccion" name="direccion" rows="3"
                                                    required placeholder="Calle, número, colonia, ciudad, estado, código postal"><?php echo isset($_POST['direccion']) ? htmlspecialchars($_POST['direccion']) : ''; ?></textarea>
                                                <div class="invalid-feedback">
                                                    Ingrese la dirección fiscal completa.
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- Paso 2: Documentos (Credencial OBLIGATORIA) -->
                                <div class="form-step" id="step2">
                                    <h4 class="section-title">Documentos</h4>

                                    <div class="alert alert-info mb-4">
                                        <i class="bi bi-info-circle"></i> Los campos marcados con <span class="required"></span> son obligatorios. Los demás son opcionales.
                                    </div>

                                    <div class="alert alert-warning mb-4">
                                        <div class="d-flex">
                                            <div class="me-3">
                                                <i class="bi bi-exclamation-triangle fs-4"></i>
                                            </div>
                                            <div>
                                                <h6 class="alert-heading">Importante</h6>
                                                <p class="mb-0"><strong class="file-required">Credencial de Identificación (INE/IFE) es obligatoria</strong> para completar el registro.</p>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="row g-3">
                                        <div class="col-12">
                                            <div class="mb-3 optional-field">
                                                <label for="constancia_fiscal" class="form-label">
                                                    Constancia de Situación Fiscal <span class="optional">(Opcional)</span>
                                                </label>
                                                <input type="file" class="form-control" id="constancia_fiscal" name="constancia_fiscal"
                                                    accept=".pdf,.jpg,.jpeg,.png">
                                                <small class="file-info">
                                                    📄 Puede subir este documento más tarde si no lo tiene disponible ahora
                                                </small>
                                            </div>
                                        </div>

                                        <div class="col-12">
                                            <div class="mb-3 required-field">
                                                <label for="credencial_identificacion" class="form-label required">
                                                    Credencial de Identificación (INE/IFE)
                                                </label>
                                                <input type="file" class="form-control" id="credencial_identificacion" name="credencial_identificacion"
                                                    accept=".pdf,.jpg,.jpeg,.png" required>
                                                <small class="file-info">
                                                    📄 Este documento es obligatorio para completar el registro
                                                </small>
                                                <div class="invalid-feedback">
                                                    Por favor, suba su credencial de identificación.
                                                </div>
                                            </div>
                                        </div>

                                        <!-- Declaración Condicional (OBLIGATORIA) -->
                                        <div class="col-12">
                                            <div id="declaracionContainer" class="declaracion-condicional declaracion-requerida">
                                                <div class="form-check">
                                                    <input class="form-check-input" type="checkbox" id="declaracion_veracidad" name="declaracion_veracidad" required>
                                                    <label class="form-check-label declaracion-texto" for="declaracion_veracidad">
                                                        <strong>Declaración de Veracidad:</strong><br>
                                                        Declaro bajo protesta de decir verdad que la información proporcionada es verídica
                                                        y que los documentos anexados (si los hay) son legítimos.
                                                        <span class="required"></span>
                                                    </label>
                                                    <div class="invalid-feedback" id="declaracionError">
                                                        Debe aceptar la declaración de veracidad.
                                                    </div>
                                                    <div class="condicional-text" id="declaracionCondicional">
                                                        <i class="bi bi-exclamation-triangle"></i> Este campo es obligatorio.
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- Paso 3: Confirmación -->
                                <div class="form-step" id="step3">
                                    <h4 class="section-title">Confirmación de Datos</h4>

                                    <div class="alert alert-success mb-4">
                                        <div class="d-flex">
                                            <div class="me-3">
                                                <i class="bi bi-check-circle fs-4"></i>
                                            </div>
                                            <div>
                                                <h6 class="alert-heading">¡Último paso!</h6>
                                                <p class="mb-0">Revise cuidadosamente la información antes de enviar el registro.</p>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="row">
                                        <div class="col-12">
                                            <h5 class="mb-3">📋 Resumen del Registro</h5>

                                            <!-- Información de la Empresa -->
                                            <div class="summary-item">
                                                <div class="summary-label">Información de la Empresa</div>
                                                <div class="summary-value">
                                                    <div><strong>Nombre:</strong> <span id="summary-nombre_empresa"></span></div>
                                                    <div><strong>Giro Comercial:</strong> <span id="summary-giro_comercial"></span></div>
                                                    <div><strong>RFC:</strong>
                                                        <span id="summary-rfc" class="summary-optional">
                                                            No proporcionado
                                                        </span>
                                                    </div>
                                                    <!-- NUEVO: No. Control en resumen -->
                                                    <div><strong>No. Control:</strong>
                                                        <span id="summary-no_distribuidor" class="summary-optional">
                                                            No proporcionado
                                                        </span>
                                                    </div>
                                                    <div><strong>Teléfono:</strong> <span id="summary-telefono"></span></div>
                                                    <div><strong>Dirección:</strong> <span id="summary-direccion"></span></div>
                                                    <div><strong>Contacto:</strong> <span id="summary-nombre_contacto"></span></div>
                                                    <div><strong>Email Administrador:</strong> <span id="summary-email_admin"></span></div>
                                                </div>
                                            </div>

                                            <!-- Documentos -->
                                            <div class="summary-item">
                                                <div class="summary-label">Documentos</div>
                                                <div class="summary-value">
                                                    <div>
                                                        <strong>Constancia Fiscal:</strong>
                                                        <span id="summary-constancia" class="summary-optional">
                                                            No cargado (opcional)
                                                        </span>
                                                    </div>
                                                    <div>
                                                        <strong>Credencial de Identificación:</strong>
                                                        <span id="summary-credencial" class="summary-error">
                                                            ❌ Pendiente (requerido)
                                                        </span>
                                                    </div>
                                                    <div>
                                                        <strong>Declaración de veracidad:</strong>
                                                        <span id="summary-declaracion" class="summary-error">
                                                            ❌ Pendiente (requerida)
                                                        </span>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="mt-4">
                                        <div class="form-check mb-3">
                                            <input class="form-check-input" type="checkbox" id="confirmacion_final" required>
                                            <label class="form-check-label" for="confirmacion_final">
                                                ✅ Confirmo que toda la información proporcionada es correcta y autorizo el <strong><a href="https://www.libertyfin.com.mx/avisos-privacidad" target="_blank" rel="noopener noreferrer" style="text-decoration: underline; color: #27ae60;">Aviso de Privacidad</a></strong> para el registro.
                                                <span class="required"></span>
                                            </label>
                                            <div class="invalid-feedback">
                                                Debe confirmar que la información es correcta.
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- Navegación -->
                                <div class="d-flex justify-content-between mt-4 pt-3 border-top">
                                    <button type="button" class="btn btn-prev btn-navigation" id="prevBtn" style="display: none;">
                                        <i class="bi bi-arrow-left me-2"></i>Anterior
                                    </button>

                                    <div class="ms-auto">
                                        <button type="button" class="btn btn-next btn-navigation" id="nextBtn">
                                            Siguiente<i class="bi bi-arrow-right ms-2"></i>
                                        </button>

                                        <button type="submit" class="btn btn-success btn-navigation" id="submitBtn" style="display: none;">
                                            <span id="submitText">
                                                <i class="bi bi-check-circle me-2"></i>Enviar Registro
                                            </span>
                                            <span class="spinner-border spinner-border-sm d-none" id="submitSpinner"></span>
                                        </button>
                                    </div>
                                </div>
                            </form>
                        </div>
                    </div>

                    <!-- Enlace a login -->
                    <div class="text-center mt-4">
                        <p class="text-white mb-2 fs-5">
                            ¿Ya tienes cuenta?
                        </p>
                        <a href="login.php" class="text-white fw-bold text-decoration-underline fs-5">
                            <i class="bi bi-box-arrow-in-right me-2"></i>Iniciar sesión aquí
                        </a>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Bootstrap JS Bundle -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <?php if ($registro_exitoso && !empty($datos_acceso)): ?>
    <script>
    // Funciones para copiar credenciales
    function copiarPassword() {
        const passwordField = document.getElementById('password-field');
        if (passwordField) {
            const password = passwordField.innerText;
            navigator.clipboard.writeText(password).then(() => {
                alert('Contraseña copiada al portapapeles');
            }).catch(() => {
                alert('No se pudo copiar la contraseña');
            });
        }
    }

    function copiarTodo() {
        // Obtener todos los datos de la tabla
        const rows = document.querySelectorAll('.registro-exitoso table tr');
        let texto = "=== CREDENCIALES DE ACCESO LIBERTYFIN ===\n\n";
        
        rows.forEach(row => {
            const th = row.querySelector('th');
            const td = row.querySelector('td');
            if (th && td) {
                const label = th.innerText.replace(':', '');
                const valor = td.innerText.trim();
                texto += `${label}: ${valor}\n`;
            }
        });
        
        texto += "\nURL de acceso: https://libertyfin.com.mx/login.php\n";
        texto += "================================";
        
        navigator.clipboard.writeText(texto).then(() => {
            alert('Todas las credenciales han sido copiadas al portapapeles');
        }).catch(() => {
            alert('No se pudieron copiar las credenciales');
        });
    }

    // Opcional: guardar en localStorage por si el usuario necesita consultarlos después
    localStorage.setItem('ultimo_registro', JSON.stringify({
        empresa: '<?php echo addslashes($datos_acceso['nombre_empresa']); ?>',
        email: '<?php echo addslashes($datos_acceso['email_admin']); ?>',
        fecha: '<?php echo date('Y-m-d H:i:s'); ?>'
    }));
    </script>
    <?php endif; ?>

    <script>
        // Variables globales
        let currentStep = 1;
        const totalSteps = 3;
        let credencialSubida = false;

        // Inicialización cuando el DOM está listo
        document.addEventListener('DOMContentLoaded', function() {
            // Solo ejecutar si el formulario existe (no estamos en pantalla de éxito)
            const form = document.getElementById('registroForm');
            if (!form) return;
            
            // Elementos del DOM
            const steps = document.querySelectorAll('.form-step');
            const stepItems = document.querySelectorAll('.step-item');
            const progressLine = document.getElementById('progressLine');
            const prevBtn = document.getElementById('prevBtn');
            const nextBtn = document.getElementById('nextBtn');
            const submitBtn = document.getElementById('submitBtn');
            const formTitle = document.getElementById('formTitle');
            const formSubtitle = document.getElementById('formSubtitle');
            const declaracionCheckbox = document.getElementById('declaracion_veracidad');
            const declaracionContainer = document.getElementById('declaracionContainer');
            const declaracionCondicional = document.getElementById('declaracionCondicional');
            const credencialInput = document.getElementById('credencial_identificacion');

            // Función para verificar si se ha subido la credencial (OBLIGATORIA)
            function verificarCredencialSubida() {
                const tieneCredencial = credencialInput.files.length > 0;

                credencialSubida = tieneCredencial;

                // Actualizar el estado de la credencial
                if (!tieneCredencial) {
                    credencialInput.required = true;
                    credencialInput.classList.add('is-invalid');
                } else {
                    credencialInput.required = true;
                    credencialInput.classList.remove('is-invalid');
                    credencialInput.classList.add('is-valid');
                }

                // Actualizar el estado de la declaración (siempre requerida ahora)
                actualizarEstadoDeclaracion();

                return tieneCredencial;
            }

            // Función para actualizar el estado de la declaración (SIEMPRE REQUERIDA)
            function actualizarEstadoDeclaracion() {
                declaracionCheckbox.required = true;
                declaracionContainer.classList.add('declaracion-requerida');
                declaracionCondicional.innerHTML = '<i class="bi bi-exclamation-triangle"></i> <strong>Requerida:</strong> Debe aceptar la declaración de veracidad.';
                declaracionCondicional.style.color = '#e74c3c';
            }

            // Actualizar el indicador de progreso
            function updateProgress() {
                const progress = ((currentStep - 1) / (totalSteps - 1)) * 100;
                progressLine.style.width = `${progress}%`;

                // Actualizar clases de los pasos
                stepItems.forEach((item, index) => {
                    const stepNumber = parseInt(item.dataset.step);

                    item.classList.remove('active', 'completed');

                    if (stepNumber < currentStep) {
                        item.classList.add('completed');
                    } else if (stepNumber === currentStep) {
                        item.classList.add('active');
                    }
                });

                // Actualizar título y subtítulo
                const titles = [
                    'Registro de Empresa - Paso 1 de 3',
                    'Registro de Empresa - Paso 2 de 3',
                    'Registro de Empresa - Paso 3 de 3'
                ];

                const subtitles = [
                    'Información de la Empresa',
                    'Documentos (Credencial Requerida)',
                    'Confirmación de Datos'
                ];

                formTitle.textContent = titles[currentStep - 1];
                formSubtitle.textContent = subtitles[currentStep - 1];

                // Mostrar/ocultar botones
                if (currentStep === 1) {
                    prevBtn.style.display = 'none';
                    nextBtn.style.display = 'inline-block';
                    submitBtn.style.display = 'none';
                } else if (currentStep === totalSteps) {
                    prevBtn.style.display = 'inline-block';
                    nextBtn.style.display = 'none';
                    submitBtn.style.display = 'inline-block';
                    updateSummary();
                } else {
                    prevBtn.style.display = 'inline-block';
                    nextBtn.style.display = 'inline-block';
                    submitBtn.style.display = 'none';
                }
            }

            // Validar paso actual
            function validateStep(step) {
                let isValid = true;
                const currentStepElement = document.getElementById(`step${step}`);

                if (step === 2) {
                    // Verificar si hay credencial subida (OBLIGATORIA)
                    const tieneCredencial = verificarCredencialSubida();

                    if (!tieneCredencial) {
                        isValid = false;
                    }

                    // Validar declaración (SIEMPRE REQUERIDA)
                    if (!declaracionCheckbox.checked) {
                        declaracionCheckbox.classList.add('is-invalid');
                        isValid = false;
                        document.getElementById('declaracionError').style.display = 'block';
                    } else {
                        declaracionCheckbox.classList.remove('is-invalid');
                        declaracionCheckbox.classList.add('is-valid');
                        document.getElementById('declaracionError').style.display = 'none';
                    }
                }

                // Solo validar campos requeridos en todos los pasos
                const requiredInputs = currentStepElement.querySelectorAll('[required]');

                // Limpiar validaciones anteriores
                requiredInputs.forEach(input => {
                    if (input.type !== 'checkbox' && input.type !== 'file') {
                        input.classList.remove('is-invalid', 'is-valid');
                    }
                });

                // Validar cada input requerido
                requiredInputs.forEach(input => {
                    if (input.id === 'declaracion_veracidad' || input.id === 'credencial_identificacion') {
                        // Ya validados arriba
                        return;
                    }

                    if (input.type === 'checkbox') {
                        if (!input.checked) {
                            input.classList.add('is-invalid');
                            isValid = false;
                        } else {
                            input.classList.add('is-valid');
                        }
                    } else if (input.type === 'file') {
                        // Solo credencial es requerida, ya validada arriba
                    } else {
                        if (!input.value.trim()) {
                            input.classList.add('is-invalid');
                            isValid = false;
                        } else if (input.id === 'email_admin') {
                            const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
                            if (!emailRegex.test(input.value.trim())) {
                                input.classList.add('is-invalid');
                                isValid = false;
                            } else {
                                input.classList.add('is-valid');
                            }
                        } else {
                            input.classList.add('is-valid');
                        }
                    }
                });

                return isValid;
            }

            // Cambiar al siguiente paso
            function nextStep() {
                if (validateStep(currentStep)) {
                    if (currentStep < totalSteps) {
                        // Ocultar paso actual
                        document.getElementById(`step${currentStep}`).classList.remove('active');

                        // Mostrar siguiente paso
                        currentStep++;
                        document.getElementById(`step${currentStep}`).classList.add('active');

                        updateProgress();

                        // Desplazar hacia arriba en móviles
                        if (window.innerWidth <= 768) {
                            window.scrollTo({
                                top: 0,
                                behavior: 'smooth'
                            });
                        }
                    }
                } else {
                    // Mostrar el primer error
                    const firstInvalid = document.querySelector('.is-invalid');
                    if (firstInvalid) {
                        firstInvalid.scrollIntoView({
                            behavior: 'smooth',
                            block: 'center'
                        });
                        firstInvalid.focus();
                    }
                }
            }

            // Volver al paso anterior
            function prevStep() {
                if (currentStep > 1) {
                    // Ocultar paso actual
                    document.getElementById(`step${currentStep}`).classList.remove('active');

                    // Mostrar paso anterior
                    currentStep--;
                    document.getElementById(`step${currentStep}`).classList.add('active');

                    updateProgress();

                    // Desplazar hacia arriba en móviles
                    if (window.innerWidth <= 768) {
                        window.scrollTo({
                            top: 0,
                            behavior: 'smooth'
                        });
                    }
                }
            }

            // Actualizar resumen en el paso 3
            function updateSummary() {
                // Información de la empresa
                document.getElementById('summary-nombre_empresa').textContent =
                    document.getElementById('nombre_empresa').value || 'No proporcionado';

                // Giro comercial
                const giroComercialSelect = document.getElementById('giro_comercial');
                const giroComercialText = giroComercialSelect.options[giroComercialSelect.selectedIndex].text;
                document.getElementById('summary-giro_comercial').textContent =
                    giroComercialText || 'No proporcionado';

                const rfcValue = document.getElementById('rfc').value;
                if (rfcValue) {
                    document.getElementById('summary-rfc').textContent = rfcValue;
                    document.getElementById('summary-rfc').className = 'summary-value';
                } else {
                    document.getElementById('summary-rfc').textContent = 'No proporcionado (opcional)';
                    document.getElementById('summary-rfc').className = 'summary-optional';
                }

                // NUEVO: No. Control en resumen
                const noDistribuidorValue = document.getElementById('no_distribuidor').value;
                if (noDistribuidorValue) {
                    document.getElementById('summary-no_distribuidor').textContent = noDistribuidorValue;
                    document.getElementById('summary-no_distribuidor').className = 'summary-value';
                } else {
                    document.getElementById('summary-no_distribuidor').textContent = 'No proporcionado (opcional)';
                    document.getElementById('summary-no_distribuidor').className = 'summary-optional';
                }

                document.getElementById('summary-telefono').textContent =
                    document.getElementById('telefono').value || 'No proporcionado';

                document.getElementById('summary-direccion').textContent =
                    document.getElementById('direccion').value || 'No proporcionado';

                document.getElementById('summary-nombre_contacto').textContent =
                    document.getElementById('nombre_contacto').value || 'No proporcionado';

                document.getElementById('summary-email_admin').textContent =
                    document.getElementById('email_admin').value || 'No proporcionado';

                // Documentos
                const constanciaFile = document.getElementById('constancia_fiscal').files[0];
                const credencialFile = document.getElementById('credencial_identificacion').files[0];
                const declaracionAceptada = document.getElementById('declaracion_veracidad').checked;

                if (constanciaFile) {
                    document.getElementById('summary-constancia').textContent = constanciaFile.name;
                    document.getElementById('summary-constancia').className = 'summary-value';
                } else {
                    document.getElementById('summary-constancia').textContent = 'No cargado (opcional)';
                    document.getElementById('summary-constancia').className = 'summary-optional';
                }

                if (credencialFile) {
                    document.getElementById('summary-credencial').textContent = credencialFile.name;
                    document.getElementById('summary-credencial').className = 'summary-value';
                } else {
                    document.getElementById('summary-credencial').textContent = '❌ Pendiente (requerido)';
                    document.getElementById('summary-credencial').className = 'summary-error';
                }

                // Declaración de veracidad (SIEMPRE REQUERIDA)
                if (declaracionAceptada) {
                    document.getElementById('summary-declaracion').textContent = '✅ Aceptada';
                    document.getElementById('summary-declaracion').className = 'summary-value';
                } else {
                    document.getElementById('summary-declaracion').textContent = '❌ Pendiente (requerida)';
                    document.getElementById('summary-declaracion').className = 'summary-error';
                }
            }

            // Event Listeners
            nextBtn.addEventListener('click', nextStep);
            prevBtn.addEventListener('click', prevStep);

            // Monitorear cambios en la credencial (OBLIGATORIA)
            if (credencialInput) {
                credencialInput.addEventListener('change', function() {
                    verificarCredencialSubida();
                });
            }

            // Monitorear cambios en la declaración
            if (declaracionCheckbox) {
                declaracionCheckbox.addEventListener('change', function() {
                    if (this.checked) {
                        this.classList.remove('is-invalid');
                        this.classList.add('is-valid');
                    } else {
                        this.classList.remove('is-valid');
                        this.classList.add('is-invalid');
                    }
                });
            }

            // Permitir navegación con Enter en inputs (excepto textarea)
            form.querySelectorAll('input').forEach(input => {
                if (input.type !== 'textarea') {
                    input.addEventListener('keypress', function(e) {
                        if (e.key === 'Enter' && currentStep < totalSteps) {
                            e.preventDefault();
                            nextStep();
                        }
                    });
                }
            });

            // Validación de tamaño de archivos (solo si se suben)
            const fileInputs = document.querySelectorAll('input[type="file"]');
            fileInputs.forEach(input => {
                input.addEventListener('change', function() {
                    if (this.files.length > 0) {
                        const file = this.files[0];
                        const maxSize = 5 * 1024 * 1024; // 5MB

                        if (file.size > maxSize) {
                            alert(`El archivo "${file.name}" excede el tamaño máximo de 5MB`);
                            this.value = '';
                            this.classList.remove('is-valid');
                            if (this.id === 'credencial_identificacion') {
                                verificarCredencialSubida(); // Actualizar estado
                            }
                        } else {
                            this.classList.add('is-valid');
                            if (this.id === 'credencial_identificacion') {
                                verificarCredencialSubida(); // Actualizar estado
                            }
                        }
                    } else {
                        this.classList.remove('is-valid');
                        if (this.id === 'credencial_identificacion') {
                            verificarCredencialSubida(); // Actualizar estado
                        }
                    }
                });
            });

            // Manejar envío del formulario
            form.addEventListener('submit', function(e) {
                e.preventDefault();

                // Verificar credencial subida antes de validar
                const tieneCredencial = verificarCredencialSubida();

                // Validar todos los campos obligatorios
                let allValid = true;
                const allRequiredInputs = form.querySelectorAll('[required]');

                // Limpiar validaciones anteriores
                allRequiredInputs.forEach(input => {
                    input.classList.remove('is-invalid');
                });

                // Validar cada campo obligatorio
                allRequiredInputs.forEach(input => {
                    if (input.id === 'credencial_identificacion' && !tieneCredencial) {
                        input.classList.add('is-invalid');
                        allValid = false;
                        return;
                    }

                    if (input.type === 'checkbox') {
                        if (!input.checked) {
                            input.classList.add('is-invalid');
                            allValid = false;
                        }
                    } else if (!input.value.trim() && input.type !== 'file') {
                        input.classList.add('is-invalid');
                        allValid = false;
                    } else if (input.id === 'email_admin') {
                        const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
                        if (!emailRegex.test(input.value.trim())) {
                            input.classList.add('is-invalid');
                            allValid = false;
                        }
                    }
                });

                if (allValid && document.getElementById('confirmacion_final').checked) {
                    // Mostrar spinner
                    const submitText = document.getElementById('submitText');
                    const submitSpinner = document.getElementById('submitSpinner');

                    submitText.innerHTML = '<i class="bi bi-hourglass-split me-2"></i>Enviando...';
                    submitSpinner.classList.remove('d-none');
                    submitBtn.disabled = true;

                    // Enviar formulario después de 1 segundo (para mostrar el spinner)
                    setTimeout(() => {
                        form.submit();
                    }, 1000);
                } else {
                    if (!document.getElementById('confirmacion_final').checked) {
                        document.getElementById('confirmacion_final').classList.add('is-invalid');
                        document.getElementById('confirmacion_final').scrollIntoView({
                            behavior: 'smooth',
                            block: 'center'
                        });
                    } else {
                        // Mostrar el primer error
                        const firstInvalid = document.querySelector('.is-invalid');
                        if (firstInvalid) {
                            firstInvalid.scrollIntoView({
                                behavior: 'smooth',
                                block: 'center'
                            });
                            firstInvalid.focus();
                        }
                    }
                }
            });

            // Navegación por clic en los pasos (solo para pasos completados)
            stepItems.forEach(item => {
                item.addEventListener('click', function() {
                    const stepNumber = parseInt(this.dataset.step);
                    const clickedItem = document.querySelector(`.step-item[data-step="${stepNumber}"]`);

                    // Solo permitir navegar a pasos completados
                    if (clickedItem.classList.contains('completed') && stepNumber !== currentStep) {
                        // Ocultar paso actual
                        document.getElementById(`step${currentStep}`).classList.remove('active');

                        // Mostrar paso seleccionado
                        currentStep = stepNumber;
                        document.getElementById(`step${currentStep}`).classList.add('active');

                        updateProgress();
                    }
                });
            });

            // Validación en tiempo real para campos obligatorios
            const requiredInputs = form.querySelectorAll('[required]');
            requiredInputs.forEach(input => {
                if (input.type !== 'checkbox' && input.type !== 'file') {
                    input.addEventListener('input', function() {
                        if (this.value.trim()) {
                            this.classList.remove('is-invalid');
                            this.classList.add('is-valid');
                        } else {
                            this.classList.remove('is-valid');
                        }
                    });
                }
            });

            // Inicializar progreso y verificar credencial
            updateProgress();
            verificarCredencialSubida();

            // Mejorar experiencia en móviles
            if (window.innerWidth <= 768) {
                // Ajustar foco automáticamente
                form.querySelectorAll('input, textarea, select').forEach(element => {
                    element.addEventListener('focus', function() {
                        setTimeout(() => {
                            this.scrollIntoView({
                                behavior: 'smooth',
                                block: 'center'
                            });
                        }, 300);
                    });
                });
            }
        });
    </script>
</body>

</html>