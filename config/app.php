<?php
/**
 * Sophía - Configuración de la Aplicación
 * "Gestión con sabiduría y cercanía"
 */

// Zona horaria para plazos, DateTime y date()/strtotime() en esta petición (Colombia, UTC-5 fijo)
if (!defined('APP_TIMEZONE')) {
    define('APP_TIMEZONE', 'America/Bogota');
}
date_default_timezone_set(APP_TIMEZONE);

// Configuración general de la aplicación
define('APP_NAME', 'Sophía');
define('APP_SLOGAN', 'Gestión con sabiduría y cercanía');
define('APP_VERSION', '1.0.0');
define('APP_DESCRIPTION', 'Sistema de Gestión PQRS');

// Configuración de fechas
define('DEFAULT_DATE_RANGE_MONTHS', 2);
define('PQRS_RESPONSE_DAYS', 15);
/** Después de esta hora (APP_TIMEZONE) el “día actual” para plazos pasa al siguiente calendario */
define('PQRS_REFERENCIA_CORTE_HORA', '23:59:59');

// Configuración de paginación
define('DEFAULT_PAGE_SIZE', 25);
define('MAX_PAGE_SIZE', 100);

// Configuración de alertas
define('ALERT_DAYS_CRITICAL', 3);
define('ALERT_DAYS_WARNING', 7);

// Configuración de archivos
define('UPLOAD_MAX_SIZE', 5 * 1024 * 1024); // 5MB
define('ALLOWED_EXTENSIONS', ['pdf', 'doc', 'docx', 'jpg', 'jpeg', 'png']);

// Configuración de cache
define('CACHE_ENABLED', true);
define('CACHE_DURATION', 300); // 5 minutos

// Función para obtener configuración
function getConfig($key, $default = null) {
    $config = [
        'app_name' => APP_NAME,
        'app_slogan' => APP_SLOGAN,
        'app_version' => APP_VERSION,
        'app_description' => APP_DESCRIPTION,
        'default_date_range_months' => DEFAULT_DATE_RANGE_MONTHS,
        'pqrs_response_days' => PQRS_RESPONSE_DAYS,
        'default_page_size' => DEFAULT_PAGE_SIZE,
        'max_page_size' => MAX_PAGE_SIZE,
        'alert_days_critical' => ALERT_DAYS_CRITICAL,
        'alert_days_warning' => ALERT_DAYS_WARNING,
        'upload_max_size' => UPLOAD_MAX_SIZE,
        'allowed_extensions' => ALLOWED_EXTENSIONS,
        'cache_enabled' => CACHE_ENABLED,
        'cache_duration' => CACHE_DURATION
    ];
    
    return $config[$key] ?? $default;
}

// Función para obtener fechas por defecto
function getDefaultDateRange() {
    $months = getConfig('default_date_range_months', 2);
    return [
        'inicio' => date('Y-m-d', strtotime("-{$months} months")),
        'fin' => date('Y-m-d')
    ];
}

// Función para formatear fechas
function formatearFecha($fecha, $formato = 'd/m/Y') {
    if (empty($fecha)) return '';
    
    $timestamp = is_string($fecha) ? strtotime($fecha) : $fecha;
    return date($formato, $timestamp);
}

// Función para calcular días restantes (MÉTODO ANTIGUO - Días calendario)
function calcularDiasRestantes($fecha_creacion) {
    $fecha_limite = date('Y-m-d', strtotime($fecha_creacion . ' +' . PQRS_RESPONSE_DAYS . ' days'));
    $dias_restantes = (strtotime($fecha_limite) - strtotime(date('Y-m-d'))) / (60 * 60 * 24);
    return round($dias_restantes);
}

// Función para calcular días hábiles restantes (MÉTODO NUEVO - Días hábiles)
function obtenerFechaReferenciaActual() {
    $tz = new DateTimeZone(APP_TIMEZONE);
    $ahora = new DateTime('now', $tz);
    $umbral = new DateTime('today ' . PQRS_REFERENCIA_CORTE_HORA, $tz);

    if ($ahora >= $umbral) {
        $ahora->modify('+1 day');
    }
    return $ahora->format('Y-m-d');
}

// Función para calcular días hábiles restantes (MÉTODO NUEVO - Días hábiles)
function calcularDiasHabilesRestantes($fecha_creacion, $dias_habiles_plazo = null) {
    require_once __DIR__ . '/festivos_colombia.php';
    
    if ($dias_habiles_plazo === null) {
        $dias_habiles_plazo = PQRS_RESPONSE_DAYS;
    }
    // Calcular fecha límite en días hábiles
    $fecha_limite = calcularFechaLimiteHabiles($fecha_creacion, $dias_habiles_plazo);
    // Fecha de referencia: hoy en Colombia, salvo después del corte (PQRS_REFERENCIA_CORTE_HORA) → día siguiente
    $fecha_referencia = obtenerFechaReferenciaActual();

    if (strtotime($fecha_referencia) > strtotime($fecha_limite)) {
        // Atraso: solo días hábiles estrictamente posteriores al límite hasta la referencia (inclusive).
        // Si se contara [límite, referencia] ambos inclusive, el día límite sumaría un “vencido” de más (p. ej. -2 en vez de -1).
        $primer_dia_tras_limite = date('Y-m-d', strtotime($fecha_limite . ' +1 day'));
        return -calcularDiasHabilesEntre($primer_dia_tras_limite, $fecha_referencia);
    }

    // En plazo o mismo día calendario que el límite: días hábiles entre referencia y límite (inclusive)
    return calcularDiasHabilesEntre($fecha_referencia, $fecha_limite);
}

// Función para obtener fecha límite de respuesta en días hábiles
function obtenerFechaLimiteHabiles($fecha_creacion, $dias_habiles_plazo = null) {
    require_once __DIR__ . '/festivos_colombia.php';
    
    if ($dias_habiles_plazo === null) {
        $dias_habiles_plazo = PQRS_RESPONSE_DAYS;
    }
    
    return calcularFechaLimiteHabiles($fecha_creacion, $dias_habiles_plazo);
}

// Función para obtener clase de alerta
function getClaseAlerta($dias_restantes) {
    if ($dias_restantes < 0) return 'danger'; // Vencida
    if ($dias_restantes <= ALERT_DAYS_CRITICAL) return 'danger'; // Crítica
    if ($dias_restantes <= ALERT_DAYS_WARNING) return 'warning'; // Advertencia
    return 'success'; // Normal
}

// Función para obtener icono de estado
function getIconoEstado($estado) {
    $iconos = [
        'Borrador' => 'fas fa-edit',
        'Validado' => 'fas fa-check-circle',
        'Cancelado' => 'fas fa-times-circle',
        'Desconocido' => 'fas fa-question-circle'
    ];
    
    return $iconos[$estado] ?? 'fas fa-question-circle';
}

// Función para obtener color de estado
function getColorEstado($estado) {
    $colores = [
        'Borrador' => 'warning',
        'Validado' => 'success',
        'Cancelado' => 'danger',
        'Desconocido' => 'secondary'
    ];
    
    return $colores[$estado] ?? 'secondary';
}

// Función para validar rango de fechas
function validarRangoFechas($fecha_inicio, $fecha_fin) {
    if (empty($fecha_inicio) || empty($fecha_fin)) {
        return false;
    }
    
    $inicio = strtotime($fecha_inicio);
    $fin = strtotime($fecha_fin);
    
    if ($inicio === false || $fin === false) {
        return false;
    }
    
    if ($inicio > $fin) {
        return false;
    }
    
    // Verificar que no exceda 1 año
    $diferencia = $fin - $inicio;
    $max_diferencia = 365 * 24 * 60 * 60; // 1 año en segundos
    
    return $diferencia <= $max_diferencia;
}

// Función para generar breadcrumb
function generarBreadcrumb($pagina_actual) {
    $breadcrumbs = [
        'index.php' => 'Dashboard',
        'pqrs.php' => 'Gestión de PQRS',
        'reportes.php' => 'Reportes y Análisis',
        'usuarios.php' => 'Gestión de Usuarios',
        'estado.php' => 'Estado del Sistema'
    ];
    
    $breadcrumb = '<nav aria-label="breadcrumb">
        <ol class="breadcrumb">';
    
    $breadcrumb .= '<li class="breadcrumb-item"><a href="index.php"><i class="fas fa-home"></i> Inicio</a></li>';
    
    if (isset($breadcrumbs[$pagina_actual])) {
        $breadcrumb .= '<li class="breadcrumb-item active" aria-current="page">' . $breadcrumbs[$pagina_actual] . '</li>';
    }
    
    $breadcrumb .= '</ol></nav>';
    
    return $breadcrumb;
}

// Función para generar metadatos de página
function generarMetadatos($titulo, $descripcion = '') {
    $metadatos = [
        'title' => $titulo . ' - ' . APP_NAME,
        'description' => $descripcion ?: APP_DESCRIPTION,
        'keywords' => 'PQRS, gestión, peticiones, quejas, reclamos, sugerencias, ' . APP_NAME,
        'author' => APP_NAME,
        'robots' => 'index, follow'
    ];
    
    return $metadatos;
}
?>
