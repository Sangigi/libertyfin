<?php
session_start();
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0">
    <title>Registro Distribuidor - Libertyfin</title>
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <!-- Select2 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <link href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css" rel="stylesheet" />
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
            cursor: pointer;
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

        .select2-container--bootstrap-5 .select2-selection {
            border: 1.5px solid #dee2e6;
            border-radius: 8px;
            min-height: 48px;
            padding: 0.5rem;
        }

        .select2-container--bootstrap-5 .select2-selection--single .select2-selection__rendered {
            line-height: 1.5;
            padding-left: 0.5rem;
        }

        .select2-container--bootstrap-5 .select2-selection--single .select2-selection__arrow {
            height: 46px;
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

        .spinner-border {
            width: 1rem;
            height: 1rem;
            margin-right: 0.5rem;
        }

        .is-invalid {
            border-color: #e74c3c !important;
        }

        .is-valid {
            border-color: var(--primary-color) !important;
        }

        .required-field {
            border-left: 4px solid #e74c3c !important;
            padding-left: 1rem;
        }

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

        .loading-spinner {
            display: inline-block;
            width: 1rem;
            height: 1rem;
            border: 2px solid rgba(0, 0, 0, .1);
            border-left-color: var(--primary-color);
            border-radius: 50%;
            animation: spinner 0.6s linear infinite;
        }

        @keyframes spinner {
            to {
                transform: rotate(360deg);
            }
        }

        #clabeHelp {
            transition: all 0.3s ease;
            font-size: 0.85rem;
            margin-top: 0.25rem;
        }

        #clabeHelp.text-success {
            color: #27ae60 !important;
        }

        #clabeHelp.text-danger {
            color: #e74c3c !important;
        }

        .digit-counter {
            display: inline-block;
            background-color: #e9ecef;
            padding: 0.2rem 0.5rem;
            border-radius: 4px;
            font-size: 0.75rem;
            margin-left: 0.5rem;
        }

        .is-valid {
            background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 8 8'%3e%3cpath fill='%2327ae60' d='M2.3 6.73L.6 4.53c-.4-1.04.46-1.4 1.1-.8l1.1 1.4 3.4-3.8c.6-.63 1.6-.27 1.2.7l-4 4.6c-.43.5-.8.4-1.1.1z'/%3e%3c/svg%3e");
            background-repeat: no-repeat;
            background-position: right calc(0.375em + 0.1875rem) center;
            background-size: calc(0.75em + 0.375rem) calc(0.75em + 0.375rem);
        }

        .is-invalid {
            background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 12 12' width='12' height='12' fill='none' stroke='%23e74c3c'%3e%3ccircle cx='6' cy='6' r='4.5'/%3e%3cpath stroke-linejoin='round' d='M5.8 3.6h.4L6 6.5z'/%3e%3ccircle cx='6' cy='8.2' r='.6' fill='%23e74c3c' stroke='none'/%3e%3c/svg%3e");
            background-repeat: no-repeat;
            background-position: right calc(0.375em + 0.1875rem) center;
            background-size: calc(0.75em + 0.375rem) calc(0.75em + 0.375rem);
        }

        .form-text {
            transition: all 0.3s ease;
        }

        .documentos-lista {
            list-style: none;
            padding-left: 0;
        }
        .documentos-lista li {
            margin-bottom: 0.5rem;
            padding: 0.5rem;
            background-color: #f8f9fa;
            border-radius: 6px;
        }
        .documentos-lista li i {
            color: var(--primary-color);
            margin-right: 0.5rem;
        }

        .alert-success {
            background-color: #d4edda;
            border-color: #c3e6cb;
            color: #155724;
        }

        .numero-control-box {
            background: linear-gradient(135deg, #27ae60, #219653);
            color: white;
            padding: 20px;
            border-radius: 10px;
            text-align: center;
            margin: 20px 0;
            box-shadow: 0 4px 15px rgba(0,0,0,0.2);
        }

        .numero-control-box h2 {
            font-size: 32px;
            letter-spacing: 2px;
            margin: 10px 0;
            font-weight: bold;
        }

        .credentials-box {
            background: #f8f9fa;
            border: 2px solid #27ae60;
            border-radius: 10px;
            padding: 20px;
            margin: 20px 0;
        }
    </style>
</head>

<body>
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-12">
                <!-- Logo -->
                <div class="logo-container text-center mb-4">
                    <img src="../images/LibertyfinBlanco.webp" alt="Logo LibertyFin"
                        class="logo-horizontal img-fluid">
                </div>

                <?php
                // Mostrar mensajes de sesión si existen
                if (isset($_SESSION['mensaje'])) {
                    $mensaje_tipo = $_SESSION['mensaje_tipo'] ?? 'info';
                    $mensaje = $_SESSION['mensaje'];
                    ?>
                    <div class="alert alert-<?php echo $mensaje_tipo; ?> alert-dismissible fade show" role="alert">
                        <div class="d-flex align-items-center">
                            <div class="me-3">
                                <i class="bi <?php echo $mensaje_tipo == 'success' ? 'bi-check-circle-fill' : 'bi-exclamation-triangle-fill'; ?> fs-4"></i>
                            </div>
                            <div class="flex-grow-1">
                                <?php echo $mensaje; ?>
                            </div>
                            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Cerrar"></button>
                        </div>
                    </div>
                    <?php
                    // Limpiar mensajes de sesión
                    unset($_SESSION['mensaje']);
                    unset($_SESSION['mensaje_tipo']);
                }
                ?>

                <!-- Indicador de Pasos -->
                <div class="step-container">
                    <div class="step-progress">
                        <div class="step-progress-line" id="progressLine"></div>
                        <div class="step-item active" data-step="1">
                            <div class="step-circle">1</div>
                            <div class="step-label">Distribuidor</div>
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
                        <h3 class="mb-0 fw-bold" id="formTitle">Registro de Distribuidor - Paso 1 de 3</h3>
                        <p class="mb-0 mt-2 opacity-75" id="formSubtitle">Información del Distribuidor</p>
                    </div>

                    <div class="card-body p-lg-4">
                        <form id="registroForm" class="needs-validation" novalidate method="POST" action="procesar-registro-distribuidor.php" enctype="multipart/form-data">

                            <!-- Paso 1: Información del Distribuidor -->
                            <div class="form-step active" id="step1">
                                <h4 class="section-title">Información del Distribuidor</h4>

                                <div class="alert alert-info mb-4">
                                    <i class="bi bi-info-circle"></i> <strong>Todos los campos marcados con <span class="required"></span> son obligatorios.</strong>
                                </div>

                                <div class="row g-3">
                                    <div class="col-12">
                                        <div class="mb-3">
                                            <label for="nombre_distribuidor" class="form-label required">Nombre Completo</label>
                                            <input type="text" class="form-control" id="nombre_distribuidor" name="nombre_distribuidor"
                                                value="<?php echo isset($_POST['nombre_distribuidor']) ? htmlspecialchars($_POST['nombre_distribuidor']) : ''; ?>"
                                                required placeholder="Nombre completo">
                                            <div class="invalid-feedback">
                                                Por favor, ingrese el nombre completo.
                                            </div>
                                        </div>
                                    </div>

                                    <div class="col-12 col-md-6">
                                        <div class="mb-3">
                                            <label for="telefono" class="form-label required">Teléfono</label>
                                            <input type="tel" class="form-control" id="telefono" name="telefono"
                                                value="<?php echo isset($_POST['telefono']) ? htmlspecialchars($_POST['telefono']) : ''; ?>"
                                                required placeholder="Ej: 5512345678">
                                            <div class="invalid-feedback">
                                                Ingrese un número de teléfono válido.
                                            </div>
                                        </div>
                                    </div>

                                    <div class="col-12 col-md-6">
                                        <div class="mb-3">
                                            <label for="email" class="form-label required">Correo Electrónico</label>
                                            <input type="email" class="form-control" id="email" name="email"
                                                value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''; ?>"
                                                required placeholder="correo@distribuidor.com">
                                            <div class="invalid-feedback">
                                                Por favor, ingrese un correo válido.
                                            </div>
                                        </div>
                                    </div>

                                    <div class="col-12 col-md-6">
                                        <div class="mb-3">
                                            <label for="rfc" class="form-label required">RFC</label>
                                            <input type="text" class="form-control" id="rfc" name="rfc"
                                                placeholder="Ej: ABC123456XYZ" maxlength="13"
                                                value="<?php echo isset($_POST['rfc']) ? htmlspecialchars($_POST['rfc']) : ''; ?>"
                                                required>
                                            <div class="invalid-feedback" id="rfcError">
                                                Por favor, ingrese su RFC (12 o 13 caracteres).
                                            </div>
                                            <div class="form-text" id="rfcHelp">
                                                RFC con 12 o 13 caracteres (persona física o moral)
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Paso 2: Documentos -->
                            <div class="form-step" id="step2">
                                <h4 class="section-title">Documentos Obligatorios</h4>

                                <div class="alert alert-info mb-4">
                                    <i class="bi bi-info-circle"></i> <strong>Todos los documentos son OBLIGATORIOS</strong> para completar el registro.
                                </div>

                                <div class="alert alert-warning mb-4">
                                    <div class="d-flex">
                                        <div class="me-3">
                                            <i class="bi bi-exclamation-triangle fs-4"></i>
                                        </div>
                                        <div>
                                            <h6 class="alert-heading">Documentos Requeridos</h6>
                                            <p class="mb-0">Los siguientes documentos son <strong class="file-required">OBLIGATORIOS</strong>:</p>
                                            <ul class="documentos-lista mb-0 mt-2">
                                                <li><i class="bi bi-check-circle-fill"></i> Constancia de Situación Fiscal</li>
                                                <li><i class="bi bi-check-circle-fill"></i> Credencial de Identificación (INE/IFE)</li>
                                                <li><i class="bi bi-check-circle-fill"></i> Declaración de Veracidad</li>
                                            </ul>
                                        </div>
                                    </div>
                                </div>

                                <div class="row g-3">
                                    <div class="col-12">
                                        <div class="mb-3 required-field">
                                            <label for="constancia_fiscal" class="form-label required">
                                                Constancia de Situación Fiscal <span class="required-badge">Obligatorio</span>
                                            </label>
                                            <input type="file" class="form-control" id="constancia_fiscal" name="constancia_fiscal"
                                                accept=".pdf,.jpg,.jpeg,.png" required>
                                            <small class="file-info file-required">
                                                📄 Este documento es obligatorio para completar el registro
                                            </small>
                                            <div class="invalid-feedback">
                                                Por favor, suba su Constancia de Situación Fiscal.
                                            </div>
                                        </div>
                                    </div>

                                    <div class="col-12">
                                        <div class="mb-3 required-field">
                                            <label for="credencial_identificacion" class="form-label required">
                                                Credencial de Identificación (INE/IFE) <span class="required-badge">Obligatorio</span>
                                            </label>
                                            <input type="file" class="form-control" id="credencial_identificacion" name="credencial_identificacion"
                                                accept=".pdf,.jpg,.jpeg,.png" required>
                                            <small class="file-info file-required">
                                                📄 Este documento es obligatorio para completar el registro
                                            </small>
                                            <div class="invalid-feedback">
                                                Por favor, suba su credencial de identificación.
                                            </div>
                                        </div>
                                    </div>

                                    <div class="col-12">
                                        <div id="declaracionContainer" class="declaracion-condicional declaracion-requerida">
                                            <div class="form-check">
                                                <input class="form-check-input" type="checkbox" id="declaracion_veracidad" name="declaracion_veracidad" required>
                                                <label class="form-check-label declaracion-texto" for="declaracion_veracidad">
                                                    <strong>Declaración de Veracidad:</strong><br>
                                                    Declaro bajo protesta de decir verdad que la información proporcionada es verídica
                                                    y que los documentos anexados son legítimos.
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

                                        <div class="summary-item">
                                            <div class="summary-label">Información del Distribuidor</div>
                                            <div class="summary-value">
                                                <div><strong>Nombre Completo:</strong> <span id="summary-nombre_distribuidor"></span></div>
                                                <div><strong>Teléfono:</strong> <span id="summary-telefono"></span></div>
                                                <div><strong>Email:</strong> <span id="summary-email"></span></div>
                                                <div><strong>RFC:</strong> <span id="summary-rfc" class="summary-value"></span></div>
                                            </div>
                                        </div>

                                        <div class="summary-item">
                                            <div class="summary-label">Documentos</div>
                                            <div class="summary-value">
                                                <div>
                                                    <strong>Constancia Fiscal:</strong>
                                                    <span id="summary-constancia" class="summary-error">
                                                        ❌ Pendiente (requerido)
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
                    <a href="login-distribuidor.php" class="text-white fw-bold text-decoration-underline fs-5">
                        <i class="bi bi-box-arrow-in-right me-2"></i>Iniciar sesión aquí
                    </a>
                </div>
            </div>
        </div>
    </div>

    <!-- jQuery -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <!-- Bootstrap JS Bundle -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <!-- Select2 JS -->
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>

    <script>
        // Variables globales
        let currentStep = 1;
        const totalSteps = 3;

        document.addEventListener('DOMContentLoaded', function() {
            const form = document.getElementById('registroForm');
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
            const rfcInput = document.getElementById('rfc');
            const rfcHelp = document.getElementById('rfcHelp');
            const rfcError = document.getElementById('rfcError');

            function verificarDocumentosObligatorios() {
                const constanciaInput = document.getElementById('constancia_fiscal');
                const credencialInput = document.getElementById('credencial_identificacion');
                
                const tieneConstancia = constanciaInput.files.length > 0;
                const tieneCredencial = credencialInput.files.length > 0;
                
                if (!tieneConstancia) {
                    constanciaInput.classList.add('is-invalid');
                } else {
                    constanciaInput.classList.remove('is-invalid');
                    constanciaInput.classList.add('is-valid');
                }

                if (!tieneCredencial) {
                    credencialInput.classList.add('is-invalid');
                } else {
                    credencialInput.classList.remove('is-invalid');
                    credencialInput.classList.add('is-valid');
                }

                return tieneConstancia && tieneCredencial;
            }

            if (rfcInput) {
                rfcInput.addEventListener('input', function(e) {
                    let valor = this.value.toUpperCase().replace(/[^A-Z0-9]/g, '');
                    this.value = valor;
                    
                    const rfcPattern = /^[A-Z0-9]{12,13}$/;
                    
                    if (valor.length === 0) {
                        this.classList.remove('is-valid', 'is-invalid');
                        if (rfcError) rfcError.style.display = 'none';
                        if (rfcHelp) {
                            rfcHelp.innerHTML = 'RFC con 12 o 13 caracteres (persona física o moral)';
                            rfcHelp.style.color = '#6c757d';
                        }
                    } 
                    else if (valor.length < 12) {
                        this.classList.add('is-invalid');
                        this.classList.remove('is-valid');
                        if (rfcError) {
                            rfcError.style.display = 'block';
                            rfcError.innerHTML = `❌ El RFC debe tener 12 o 13 caracteres (actual: ${valor.length})`;
                        }
                        if (rfcHelp) {
                            rfcHelp.innerHTML = `Faltan ${12 - valor.length} caracteres`;
                            rfcHelp.style.color = '#e74c3c';
                        }
                    } 
                    else if (valor.length === 12 || valor.length === 13) {
                        this.classList.add('is-valid');
                        this.classList.remove('is-invalid');
                        if (rfcError) rfcError.style.display = 'none';
                        if (rfcHelp) {
                            rfcHelp.innerHTML = '✅ RFC válido';
                            rfcHelp.style.color = '#27ae60';
                        }
                    }
                    else if (valor.length > 13) {
                        this.value = valor.substring(0, 13);
                        this.classList.add('is-valid');
                        this.classList.remove('is-invalid');
                        if (rfcError) rfcError.style.display = 'none';
                        if (rfcHelp) {
                            rfcHelp.innerHTML = '✅ RFC válido (truncado a 13 caracteres)';
                            rfcHelp.style.color = '#27ae60';
                        }
                    }
                });

                rfcInput.addEventListener('blur', function() {
                    const valor = this.value;
                    if (valor.length > 0 && (valor.length < 12 || valor.length > 13)) {
                        this.classList.add('is-invalid');
                        this.classList.remove('is-valid');
                    }
                });
            }

            function updateProgress() {
                const progress = ((currentStep - 1) / (totalSteps - 1)) * 100;
                progressLine.style.width = `${progress}%`;

                stepItems.forEach((item, index) => {
                    const stepNumber = parseInt(item.dataset.step);
                    item.classList.remove('active', 'completed');

                    if (stepNumber < currentStep) {
                        item.classList.add('completed');
                    } else if (stepNumber === currentStep) {
                        item.classList.add('active');
                    }
                });

                const titles = [
                    'Registro de Distribuidor - Paso 1 de 3',
                    'Registro de Distribuidor - Paso 2 de 3',
                    'Registro de Distribuidor - Paso 3 de 3'
                ];

                const subtitles = [
                    'Información del Distribuidor',
                    'Documentos (Todos Obligatorios)',
                    'Confirmación de Datos'
                ];

                formTitle.textContent = titles[currentStep - 1];
                formSubtitle.textContent = subtitles[currentStep - 1];

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

            function validateStep(step) {
                let isValid = true;
                const currentStepElement = document.getElementById(`step${step}`);

                if (step === 2) {
                    const constanciaInput = document.getElementById('constancia_fiscal');
                    const credencialInput = document.getElementById('credencial_identificacion');
                    
                    const tieneConstancia = constanciaInput.files.length > 0;
                    const tieneCredencial = credencialInput.files.length > 0;
                    
                    if (!tieneConstancia) {
                        constanciaInput.classList.add('is-invalid');
                        isValid = false;
                    } else {
                        constanciaInput.classList.remove('is-invalid');
                        constanciaInput.classList.add('is-valid');
                    }

                    if (!tieneCredencial) {
                        credencialInput.classList.add('is-invalid');
                        isValid = false;
                    } else {
                        credencialInput.classList.remove('is-invalid');
                        credencialInput.classList.add('is-valid');
                    }

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

                const requiredInputs = currentStepElement.querySelectorAll('[required]');

                requiredInputs.forEach(input => {
                    if (input.type !== 'checkbox' && input.type !== 'file') {
                        input.classList.remove('is-invalid', 'is-valid');
                    }
                });

                requiredInputs.forEach(input => {
                    if (input.id === 'declaracion_veracidad' || input.id === 'credencial_identificacion' || input.id === 'constancia_fiscal') {
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
                    } else {
                        if (!input.value.trim()) {
                            input.classList.add('is-invalid');
                            isValid = false;
                        } else if (input.id === 'email') {
                            const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
                            if (!emailRegex.test(input.value.trim())) {
                                input.classList.add('is-invalid');
                                isValid = false;
                            } else {
                                input.classList.add('is-valid');
                            }
                        } else if (input.id === 'rfc') {
                            const rfc = input.value.trim().toUpperCase().replace(/[^A-Z0-9]/g, '');
                            const rfcPattern = /^[A-Z0-9]{12,13}$/;
                            if (!rfcPattern.test(rfc)) {
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

            function nextStep() {
                if (validateStep(currentStep)) {
                    if (currentStep < totalSteps) {
                        document.getElementById(`step${currentStep}`).classList.remove('active');
                        currentStep++;
                        document.getElementById(`step${currentStep}`).classList.add('active');
                        updateProgress();

                        if (window.innerWidth <= 768) {
                            window.scrollTo({
                                top: 0,
                                behavior: 'smooth'
                            });
                        }
                    }
                } else {
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

            function prevStep() {
                if (currentStep > 1) {
                    document.getElementById(`step${currentStep}`).classList.remove('active');
                    currentStep--;
                    document.getElementById(`step${currentStep}`).classList.add('active');
                    updateProgress();

                    if (window.innerWidth <= 768) {
                        window.scrollTo({
                            top: 0,
                            behavior: 'smooth'
                        });
                    }
                }
            }

            function updateSummary() {
                document.getElementById('summary-nombre_distribuidor').textContent =
                    document.getElementById('nombre_distribuidor').value || 'No proporcionado';

                document.getElementById('summary-telefono').textContent =
                    document.getElementById('telefono').value || 'No proporcionado';

                document.getElementById('summary-email').textContent =
                    document.getElementById('email').value || 'No proporcionado';

                const rfcValue = document.getElementById('rfc').value;
                const rfcSpan = document.getElementById('summary-rfc');
                if (rfcValue) {
                    if (/^[A-Z0-9]{12,13}$/.test(rfcValue)) {
                        rfcSpan.textContent = rfcValue;
                        rfcSpan.className = 'summary-value';
                    } else {
                        rfcSpan.textContent = `${rfcValue} (formato incorrecto)`;
                        rfcSpan.className = 'summary-error';
                    }
                } else {
                    rfcSpan.textContent = '❌ Pendiente (requerido)';
                    rfcSpan.className = 'summary-error';
                }

                const constanciaFile = document.getElementById('constancia_fiscal').files[0];
                const credencialFile = document.getElementById('credencial_identificacion').files[0];
                const declaracionAceptada = document.getElementById('declaracion_veracidad').checked;

                if (constanciaFile) {
                    document.getElementById('summary-constancia').textContent = constanciaFile.name;
                    document.getElementById('summary-constancia').className = 'summary-value';
                } else {
                    document.getElementById('summary-constancia').textContent = '❌ Pendiente (requerido)';
                    document.getElementById('summary-constancia').className = 'summary-error';
                }

                if (credencialFile) {
                    document.getElementById('summary-credencial').textContent = credencialFile.name;
                    document.getElementById('summary-credencial').className = 'summary-value';
                } else {
                    document.getElementById('summary-credencial').textContent = '❌ Pendiente (requerido)';
                    document.getElementById('summary-credencial').className = 'summary-error';
                }

                if (declaracionAceptada) {
                    document.getElementById('summary-declaracion').textContent = '✅ Aceptada';
                    document.getElementById('summary-declaracion').className = 'summary-value';
                } else {
                    document.getElementById('summary-declaracion').textContent = '❌ Pendiente (requerida)';
                    document.getElementById('summary-declaracion').className = 'summary-error';
                }
            }

            nextBtn.addEventListener('click', nextStep);
            prevBtn.addEventListener('click', prevStep);

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

            form.querySelectorAll('input').forEach(input => {
                if (input.type !== 'textarea' && input.type !== 'file') {
                    input.addEventListener('keypress', function(e) {
                        if (e.key === 'Enter' && currentStep < totalSteps) {
                            e.preventDefault();
                            nextStep();
                        }
                    });
                }
            });

            const fileInputs = document.querySelectorAll('input[type="file"]');
            fileInputs.forEach(input => {
                input.addEventListener('change', function() {
                    if (this.files.length > 0) {
                        const file = this.files[0];
                        const maxSize = 5 * 1024 * 1024;

                        if (file.size > maxSize) {
                            alert(`El archivo "${file.name}" excede el tamaño máximo de 5MB`);
                            this.value = '';
                            this.classList.remove('is-valid');
                            verificarDocumentosObligatorios();
                        } else {
                            this.classList.add('is-valid');
                            verificarDocumentosObligatorios();
                        }
                    } else {
                        this.classList.remove('is-valid');
                        verificarDocumentosObligatorios();
                    }
                });
            });

            form.addEventListener('submit', function(e) {
                e.preventDefault();

                const documentosOk = verificarDocumentosObligatorios();

                let allValid = true;
                const allRequiredInputs = form.querySelectorAll('[required]');

                allRequiredInputs.forEach(input => {
                    input.classList.remove('is-invalid');
                });

                allRequiredInputs.forEach(input => {
                    if (input.id === 'constancia_fiscal' || input.id === 'credencial_identificacion') {
                        if (!documentosOk) {
                            input.classList.add('is-invalid');
                            allValid = false;
                        }
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
                    } else if (input.id === 'email') {
                        const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
                        if (!emailRegex.test(input.value.trim())) {
                            input.classList.add('is-invalid');
                            allValid = false;
                        }
                    } else if (input.id === 'rfc') {
                        const rfc = input.value.trim().toUpperCase().replace(/[^A-Z0-9]/g, '');
                        const rfcPattern = /^[A-Z0-9]{12,13}$/;
                        if (!rfcPattern.test(rfc)) {
                            input.classList.add('is-invalid');
                            allValid = false;
                        }
                    }
                });

                if (allValid && documentosOk && document.getElementById('confirmacion_final').checked) {
                    const submitText = document.getElementById('submitText');
                    const submitSpinner = document.getElementById('submitSpinner');

                    submitText.innerHTML = '<i class="bi bi-hourglass-split me-2"></i>Enviando...';
                    submitSpinner.classList.remove('d-none');
                    submitBtn.disabled = true;

                    setTimeout(() => {
                        form.submit();
                    }, 500);
                } else {
                    if (!document.getElementById('confirmacion_final').checked) {
                        document.getElementById('confirmacion_final').classList.add('is-invalid');
                        document.getElementById('confirmacion_final').scrollIntoView({
                            behavior: 'smooth',
                            block: 'center'
                        });
                    } else {
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

            stepItems.forEach(item => {
                item.addEventListener('click', function() {
                    const stepNumber = parseInt(this.dataset.step);
                    const clickedItem = document.querySelector(`.step-item[data-step="${stepNumber}"]`);

                    if (clickedItem.classList.contains('completed') && stepNumber !== currentStep) {
                        document.getElementById(`step${currentStep}`).classList.remove('active');
                        currentStep = stepNumber;
                        document.getElementById(`step${currentStep}`).classList.add('active');
                        updateProgress();
                    }
                });
            });

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

            updateProgress();
            verificarDocumentosObligatorios();

            if (window.innerWidth <= 768) {
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