<?php
session_start();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login Distribuidor - Libertyfin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <style>
        body {
            background: linear-gradient(135deg, #27ae60 0%, #219653 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        .login-card {
            border: none;
            border-radius: 15px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.2);
        }
        .login-header {
            background: #27ae60;
            color: white;
            border-radius: 15px 15px 0 0;
            padding: 20px;
            text-align: center;
        }
        .login-body {
            padding: 30px;
        }
        .btn-login {
            background: #27ae60;
            color: white;
            font-weight: 600;
            padding: 12px;
            transition: all 0.3s;
        }
        .btn-login:hover {
            background: #219653;
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(39,174,96,0.3);
        }
        .logo {
            max-width: 200px;
            margin-bottom: 20px;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-md-6 col-lg-5">
                <div class="text-center mb-4">
                    <img src="../images/LibertyfinBlanco.webp" alt="LibertyFin" class="logo">
                </div>
                
                <div class="card login-card">
                    <div class="login-header">
                        <h3 class="mb-0">Acceso Distribuidores</h3>
                    </div>
                    <div class="login-body">
                        
                        <?php if (isset($_SESSION['error_login'])): ?>
                            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                                <?php echo $_SESSION['error_login']; ?>
                                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                            </div>
                            <?php unset($_SESSION['error_login']); ?>
                        <?php endif; ?>
                        
                        <form action="validar-login-distribuidor.php" method="POST">
                            <div class="mb-3">
                                <label for="numero_control" class="form-label">Número de Control</label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="bi bi-qr-code"></i></span>
                                    <input type="text" class="form-control" id="numero_control" name="numero_control" 
                                           placeholder="Ej: LI00001" required>
                                </div>
                            </div>
                            
                            <div class="mb-4">
                                <label for="password" class="form-label">Contraseña</label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="bi bi-lock"></i></span>
                                    <input type="password" class="form-control" id="password" name="password" 
                                           placeholder="Ingresa tu contraseña" required>
                                </div>
                            </div>
                            
                            <button type="submit" class="btn btn-login w-100">
                                <i class="bi bi-box-arrow-in-right me-2"></i>Iniciar Sesión
                            </button>
                        </form>
                        
                        <hr class="my-4">
                        
                        <div class="text-center">
                            <p class="mb-2">¿No tienes cuenta?</p>
                            <a href="registro-distribuidor.php" class="btn btn-outline-success">
                                <i class="bi bi-person-plus me-2"></i>Registrarme como Distribuidor
                            </a>
                        </div>
                        
                        <div class="text-center mt-3">
                            <a href="#" class="text-decoration-none small">¿Olvidaste tu contraseña?</a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>