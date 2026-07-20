<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Registro de Empresa - Sistema Caja</title>
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body {
            background: linear-gradient(135deg, #27ae60 0%, #2ecc71 100%);
            min-height: 100vh;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        
        .card {
            border: none;
            border-radius: 10px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
        }
        
        .card-header {
            background: #27ae60;
            color: white;
            border-bottom: none;
            padding: 1.5rem;
        }
        
        .btn-success {
            background-color: #27ae60;
            border-color: #27ae60;
            padding: 0.75rem 1.5rem;
        }
        
        .btn-success:hover {
            background-color: #219653;
            border-color: #219653;
        }
        
        .form-control:focus {
            border-color: #27ae60;
            box-shadow: 0 0 0 0.2rem rgba(39, 174, 96, 0.25);
        }
        
        .required:after {
            content: " *";
            color: #e74c3c;
        }
    </style>
</head>
<body>
    <?php
    include 'registro_empresa.php';
    ?>
    
    <div class="container py-5">
        <div class="row justify-content-center">
            <div class="col-12 col-md-8 col-lg-6">
                <!-- Encabezado -->
                <div class="text-center mb-4">
                    <h1 class="text-white mb-2">Sistema Caja</h1>
                    <p class="text-white mb-0">Registro de Empresa</p>
                </div>

                <!-- Formulario -->
                <div class="card">
                    <div class="card-header text-center">
                        <h3 class="mb-0">Registro</h3>
                    </div>
                    <div class="card-body p-4">
                        <?php if (!empty($mensaje)): ?>
                            <div class="alert alert-<?php echo $tipo_mensaje; ?> alert-dismissible fade show mb-4" role="alert">
                                <?php echo $mensaje; ?>
                                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                            </div>
                        <?php endif; ?>

                        <form id="registroForm" class="needs-validation" novalidate method="POST" action="">
                            
                            <!-- Campos de Empresa -->
                            <div class="mb-3">
                                <label for="nombre_empresa" class="form-label required">Nombre de la Empresa</label>
                                <input type="text" class="form-control" id="nombre_empresa" name="nombre_empresa" 
                                       value="<?php echo isset($_POST['nombre_empresa']) ? htmlspecialchars($_POST['nombre_empresa']) : ''; ?>" 
                                       required placeholder="Nombre comercial">
                                <div class="invalid-feedback">
                                    Ingrese el nombre de la empresa.
                                </div>
                            </div>

                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="rfc" class="form-label">RFC</label>
                                        <input type="text" class="form-control" id="rfc" name="rfc" 
                                               value="<?php echo isset($_POST['rfc']) ? htmlspecialchars($_POST['rfc']) : ''; ?>" 
                                               placeholder="Opcional">
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="telefono" class="form-label">Teléfono</label>
                                        <input type="tel" class="form-control" id="telefono" name="telefono" 
                                               value="<?php echo isset($_POST['telefono']) ? htmlspecialchars($_POST['telefono']) : ''; ?>"
                                               placeholder="(55) 1234-5678">
                                    </div>
                                </div>
                            </div>

                            <div class="mb-3">
                                <label for="email" class="form-label required">Email</label>
                                <input type="email" class="form-control" id="email" name="email" 
                                       value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''; ?>"
                                       required placeholder="email@empresa.com">
                                <div class="invalid-feedback">
                                    Ingrese un email válido.
                                </div>
                            </div>

                            <div class="mb-3">
                                <label for="direccion" class="form-label">Dirección</label>
                                <textarea class="form-control" id="direccion" name="direccion" rows="2" 
                                          placeholder="Dirección de la empresa"><?php echo isset($_POST['direccion']) ? htmlspecialchars($_POST['direccion']) : ''; ?></textarea>
                            </div>

                            <!-- Campos de Usuario -->
                            <div class="mb-3">
                                <label for="nombre_contacto" class="form-label required">Persona de Contacto</label>
                                <input type="text" class="form-control" id="nombre_contacto" name="nombre_contacto" 
                                       value="<?php echo isset($_POST['nombre_contacto']) ? htmlspecialchars($_POST['nombre_contacto']) : ''; ?>" 
                                       required placeholder="Nombre completo">
                                <div class="invalid-feedback">
                                    Ingrese el nombre del contacto.
                                </div>
                            </div>

                            <div class="mb-3">
                                <label for="usuario_admin" class="form-label required">Usuario</label>
                                <input type="text" class="form-control" id="usuario_admin" name="usuario_admin" 
                                       value="<?php echo isset($_POST['usuario_admin']) ? htmlspecialchars($_POST['usuario_admin']) : ''; ?>" 
                                       required placeholder="Nombre de usuario">
                                <div class="invalid-feedback">
                                    Ingrese un nombre de usuario.
                                </div>
                            </div>

                            <div class="mb-3">
                                <label for="password_admin" class="form-label required">Contraseña</label>
                                <input type="password" class="form-control" id="password_admin" name="password_admin" 
                                       required minlength="6" placeholder="Mínimo 6 caracteres">
                                <div class="invalid-feedback">
                                    La contraseña debe tener al menos 6 caracteres.
                                </div>
                            </div>

                            <!-- Botón de envío -->
                            <div class="d-grid">
                                <button type="submit" class="btn btn-success btn-lg">
                                    Registrar Empresa
                                </button>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Enlace a login -->
                <div class="text-center mt-4">
                    <p class="text-white mb-0">
                        ¿Ya tienes cuenta? 
                        <a href="login.php" class="text-white fw-bold">Iniciar sesión</a>
                    </p>
                </div>
            </div>
        </div>
    </div>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Validación básica del formulario
        (function() {
            'use strict';
            window.addEventListener('load', function() {
                var forms = document.getElementsByClassName('needs-validation');
                var validation = Array.prototype.filter.call(forms, function(form) {
                    form.addEventListener('submit', function(event) {
                        if (form.checkValidity() === false) {
                            event.preventDefault();
                            event.stopPropagation();
                        }
                        form.classList.add('was-validated');
                    }, false);
                });
            }, false);
        })();

        // Limpiar formulario si el registro fue exitoso
        document.addEventListener('DOMContentLoaded', function() {
            if (sessionStorage.getItem('formularioLimpio') === 'true') {
                document.getElementById('registroForm').reset();
                sessionStorage.removeItem('formularioLimpio');
            }
        });
    </script>
</body>
</html>