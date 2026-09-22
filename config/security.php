<?php
/**
 * Sophía - Configuración de Seguridad
 * "Gestión con sabiduría y cercanía"
 */

// Configuración de seguridad
define('SOPHIA_VERSION', '1.0.0');
define('SOPHIA_MAX_LOGIN_ATTEMPTS', 5);
define('SOPHIA_SESSION_TIMEOUT', 3600); // 1 hora

// Función para validar sesión
function validarSesion() {
    if (!isset($_SESSION['autenticado']) || !$_SESSION['autenticado']) {
        return false;
    }
    
    // Verificar timeout de sesión
    if (isset($_SESSION['last_activity']) && 
        (time() - $_SESSION['last_activity'] > SOPHIA_SESSION_TIMEOUT)) {
        session_destroy();
        return false;
    }
    
    $_SESSION['last_activity'] = time();
    return true;
}

// Función para sanitizar entrada
function sanitizarEntrada($input) {
    if (is_array($input)) {
        return array_map('sanitizarEntrada', $input);
    }
    return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
}

// Función para validar fechas
function validarFecha($fecha) {
    $d = DateTime::createFromFormat('Y-m-d', $fecha);
    return $d && $d->format('Y-m-d') === $fecha;
}

// Función para validar email
function validarEmail($email) {
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

// Función para generar token CSRF
function generarTokenCSRF() {
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

// Función para validar token CSRF
function validarTokenCSRF($token) {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

// Función para prevenir ataques de fuerza bruta
function verificarIntentosLogin($usuario) {
    $archivo_intentos = __DIR__ . '/../logs/login_attempts.json';
    
    if (!file_exists($archivo_intentos)) {
        return true;
    }
    
    $intentos = json_decode(file_get_contents($archivo_intentos), true);
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $key = $ip . '_' . $usuario;
    
    if (isset($intentos[$key])) {
        $ultimo_intento = $intentos[$key]['last_attempt'];
        $contador = $intentos[$key]['count'];
        
        // Si han pasado más de 15 minutos, resetear contador
        if (time() - $ultimo_intento > 900) {
            unset($intentos[$key]);
            file_put_contents($archivo_intentos, json_encode($intentos));
            return true;
        }
        
        // Si excede el límite, bloquear
        if ($contador >= SOPHIA_MAX_LOGIN_ATTEMPTS) {
            return false;
        }
    }
    
    return true;
}

// Función para registrar intento de login
function registrarIntentoLogin($usuario, $exitoso) {
    $archivo_intentos = __DIR__ . '/../logs/login_attempts.json';
    $intentos = [];
    
    if (file_exists($archivo_intentos)) {
        $intentos = json_decode(file_get_contents($archivo_intentos), true) ?: [];
    }
    
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $key = $ip . '_' . $usuario;
    
    if ($exitoso) {
        // Si es exitoso, limpiar intentos
        unset($intentos[$key]);
    } else {
        // Si falla, incrementar contador
        if (!isset($intentos[$key])) {
            $intentos[$key] = ['count' => 0, 'last_attempt' => 0];
        }
        $intentos[$key]['count']++;
        $intentos[$key]['last_attempt'] = time();
    }
    
    file_put_contents($archivo_intentos, json_encode($intentos));
}

// Función para limpiar headers de seguridad
function limpiarHeaders() {
    // Prevenir clickjacking
    header('X-Frame-Options: DENY');
    
    // Prevenir MIME type sniffing
    header('X-Content-Type-Options: nosniff');
    
    // Habilitar XSS protection
    header('X-XSS-Protection: 1; mode=block');
    
    // Referrer Policy
    header('Referrer-Policy: strict-origin-when-cross-origin');
    
    // Content Security Policy básico
    header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline' cdn.jsdelivr.net cdnjs.cloudflare.com code.jquery.com; style-src 'self' 'unsafe-inline' cdn.jsdelivr.net cdnjs.cloudflare.com; img-src 'self' data:; font-src 'self' cdnjs.cloudflare.com;");
}

// Función para validar entrada de formulario
function validarFormulario($campos_requeridos, $datos) {
    $errores = [];
    
    foreach ($campos_requeridos as $campo) {
        if (!isset($datos[$campo]) || empty(trim($datos[$campo]))) {
            $errores[] = "El campo $campo es requerido";
        }
    }
    
    return $errores;
}

// Función para generar contraseña segura
function generarPasswordSegura($longitud = 12) {
    $caracteres = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*';
    return substr(str_shuffle($caracteres), 0, $longitud);
}

// Aplicar headers de seguridad
limpiarHeaders();
?>
