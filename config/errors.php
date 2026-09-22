<?php
/**
 * Sophía - Configuración de Errores
 * "Gestión con sabiduría y cercanía"
 */

// Configuración de errores para desarrollo
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../logs/sophia_errors.log');

// Crear directorio de logs si no existe
$log_dir = __DIR__ . '/../logs';
if (!is_dir($log_dir)) {
    mkdir($log_dir, 0755, true);
}

// Función personalizada para manejo de errores
function sophia_error_handler($errno, $errstr, $errfile, $errline) {
    $error_message = date('Y-m-d H:i:s') . " - Error [$errno]: $errstr en $errfile línea $errline\n";
    error_log($error_message, 3, __DIR__ . '/../logs/sophia_errors.log');
    
    // En producción, no mostrar errores al usuario
    if (ini_get('display_errors')) {
        echo "<div class='alert alert-warning'>Error del sistema: $errstr</div>";
    }
}

// Función personalizada para manejo de excepciones
function sophia_exception_handler($exception) {
    $error_message = date('Y-m-d H:i:s') . " - Excepción: " . $exception->getMessage() . " en " . $exception->getFile() . " línea " . $exception->getLine() . "\n";
    error_log($error_message, 3, __DIR__ . '/../logs/sophia_errors.log');
    
    // Mostrar error amigable al usuario
    echo '<div class="alert alert-danger">
        <i class="fas fa-exclamation-triangle me-2"></i>
        <strong>Sophía - Error del Sistema:</strong> Ha ocurrido un error inesperado. Por favor, intente nuevamente.
    </div>';
}

// Registrar manejadores de errores
set_error_handler('sophia_error_handler');
set_exception_handler('sophia_exception_handler');

// Función para logging personalizado
function log_sophia($message, $level = 'INFO') {
    $log_message = date('Y-m-d H:i:s') . " - [$level] $message\n";
    error_log($log_message, 3, __DIR__ . '/../logs/sophia_errors.log');
}

// Función para limpiar logs antiguos (ejecutar periódicamente)
function limpiar_logs_antiguos($dias = 30) {
    $log_file = __DIR__ . '/../logs/sophia_errors.log';
    if (file_exists($log_file)) {
        $file_time = filemtime($log_file);
        if (time() - $file_time > ($dias * 24 * 60 * 60)) {
            unlink($log_file);
            log_sophia("Logs antiguos eliminados automáticamente", "CLEANUP");
        }
    }
}
?>
