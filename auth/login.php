<?php
session_start();

// Incluir configuración de seguridad
require_once '../config/security.php';
require_once '../config/errors.php';

// Credenciales genéricas (sin base de datos)
$usuario_valido = 'admin';
$clave_valida = 'pqrs2024';

$error = '';

if ($_POST) {
    $usuario = sanitizarEntrada($_POST['usuario'] ?? '');
    $clave = $_POST['clave'] ?? '';
    
    // Verificar intentos de login
    if (!verificarIntentosLogin($usuario)) {
        $error = 'Demasiados intentos fallidos. Intente nuevamente en 15 minutos.';
        log_sophia("Intento de login bloqueado para usuario: $usuario", "SECURITY");
    } else {
        if ($usuario === $usuario_valido && $clave === $clave_valida) {
            $_SESSION['usuario'] = $usuario;
            $_SESSION['autenticado'] = true;
            $_SESSION['last_activity'] = time();
            
            // Registrar login exitoso
            registrarIntentoLogin($usuario, true);
            log_sophia("Login exitoso para usuario: $usuario", "ACCESS");
            
            header('Location: ../dashboard/');
            exit;
        } else {
            $error = 'Usuario o contraseña incorrectos';
            
            // Registrar intento fallido
            registrarIntentoLogin($usuario, false);
            log_sophia("Intento de login fallido para usuario: $usuario", "SECURITY");
        }
    }
}

// Si ya está autenticado, redirigir al dashboard
if (isset($_SESSION['autenticado']) && $_SESSION['autenticado']) {
    header('Location: ../dashboard/');
    exit;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Sophía - Sistema de Gestión PQRS. Gestión con sabiduría y cercanía para peticiones, quejas, reclamos y sugerencias.">
    <title>Login - Sophía PQRS</title>
    
    <!-- Favicon -->
    <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>📖</text></svg>">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .login-card {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border-radius: 20px;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.1);
            padding: 2rem;
            width: 100%;
            max-width: 400px;
        }
        .login-header {
            text-align: center;
            margin-bottom: 2rem;
        }
        .login-header i {
            font-size: 3rem;
            color: #667eea;
            margin-bottom: 1rem;
        }
        .form-control {
            border-radius: 10px;
            border: 2px solid #e9ecef;
            padding: 0.75rem 1rem;
            transition: all 0.3s ease;
        }
        .form-control:focus {
            border-color: #667eea;
            box-shadow: 0 0 0 0.2rem rgba(102, 126, 234, 0.25);
        }
        .btn-login {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border: none;
            border-radius: 10px;
            padding: 0.75rem 2rem;
            font-weight: 600;
            transition: all 0.3s ease;
        }
        .btn-login:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 20px rgba(102, 126, 234, 0.3);
        }
        .alert {
            border-radius: 10px;
            border: none;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-md-6 col-lg-4">
                <div class="login-card">
                    <div class="login-header">
                        <i class="fas fa-book"></i>
                        <h3 class="mb-0">Sophía</h3>
                        <p class="text-muted">Sistema de Gestión PQRS</p>
                        <small class="text-light">"Gestión con sabiduría y cercanía"</small>
                    </div>
                    
                    <?php if ($error): ?>
                        <div class="alert alert-danger" role="alert">
                            <i class="fas fa-exclamation-triangle me-2"></i>
                            <?php echo htmlspecialchars($error); ?>
                        </div>
                    <?php endif; ?>
                    
                    <form method="POST">
                        <div class="mb-3">
                            <label for="usuario" class="form-label">
                                <i class="fas fa-user me-2"></i>Usuario
                            </label>
                            <input type="text" class="form-control" id="usuario" name="usuario" 
                                   value="<?php echo htmlspecialchars($_POST['usuario'] ?? ''); ?>" required>
                        </div>
                        
                        <div class="mb-4">
                            <label for="clave" class="form-label">
                                <i class="fas fa-lock me-2"></i>Contraseña
                            </label>
                            <input type="password" class="form-control" id="clave" name="clave" required>
                        </div>
                        
                        <div class="d-grid">
                            <button type="submit" class="btn btn-primary btn-login">
                                <i class="fas fa-sign-in-alt me-2"></i>Iniciar Sesión
                            </button>
                        </div>
                    </form>
                    
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
