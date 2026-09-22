<?php
/**
 * Sophía - Festivos de Colombia
 * "Gestión con sabiduría y cercanía"
 * 
 * Este archivo contiene la lista de días festivos en Colombia
 * según la Ley 51 de 1983 (Ley Emiliani) y la Ley 2578 de 2026
 * (Virgen del Rosario de Chiquinquirá, 9 de julio).
 */

/** Año desde el cual rige el festivo de la Virgen del Rosario de Chiquinquirá (Ley 2578 de 2026) */
define('ANIO_INICIO_FESTIVO_CHIQUINQUIRA', 2026);

/**
 * Obtiene todos los festivos de Colombia para un año específico
 * 
 * @param int $anio Año para el cual calcular los festivos
 * @return array Array de fechas en formato 'Y-m-d'
 */
function obtenerFestivosColombia($anio = null) {
    if ($anio === null) {
        $anio = date('Y');
    }
    
    $festivos = [];
    
    // 1. Festivos de fecha fija (no se mueven al lunes)
    $festivos[] = "$anio-01-01"; // Año Nuevo
    $festivos[] = "$anio-05-01"; // Día del Trabajo
    $festivos[] = "$anio-07-20"; // Día de la Independencia
    $festivos[] = "$anio-08-07"; // Batalla de Boyacá
    $festivos[] = "$anio-12-08"; // Día de la Inmaculada Concepción
    $festivos[] = "$anio-12-25"; // Navidad
    
    // 2. Calcular Semana Santa (festivos móviles basados en el Domingo de Resurrección)
    $domingo_resurreccion = easter_date($anio);
    
    // Jueves Santo (3 días antes del Domingo de Resurrección)
    $festivos[] = date('Y-m-d', strtotime('-3 days', $domingo_resurreccion));
    
    // Viernes Santo (2 días antes del Domingo de Resurrección)
    $festivos[] = date('Y-m-d', strtotime('-2 days', $domingo_resurreccion));
    
    // 3. Festivos que se trasladan al lunes siguiente (Ley Emiliani)
    $festivos_trasladables = [
        "$anio-01-06", // Reyes Magos
        "$anio-03-19", // San José
        "$anio-06-29", // San Pedro y San Pablo
        "$anio-08-15", // Asunción de la Virgen
        "$anio-10-12", // Día de la Raza
        "$anio-11-01", // Todos los Santos
        "$anio-11-11", // Independencia de Cartagena
    ];

    // Ley 2578 de 2026: 9 de julio, trasladable al lunes siguiente (Ley Emiliani)
    if ($anio >= ANIO_INICIO_FESTIVO_CHIQUINQUIRA) {
        $festivos_trasladables[] = "$anio-07-09";
    }
    
    foreach ($festivos_trasladables as $fecha) {
        $festivos[] = trasladarALunesSiguiente($fecha);
    }
    
    // Festivos basados en Domingo de Resurrección que se trasladan al lunes
    // Ascensión del Señor (39 días después del Domingo de Resurrección, trasladado al lunes)
    $ascension = date('Y-m-d', strtotime('+39 days', $domingo_resurreccion));
    $festivos[] = trasladarALunesSiguiente($ascension);
    
    // Corpus Christi (60 días después del Domingo de Resurrección, trasladado al lunes)
    $corpus = date('Y-m-d', strtotime('+60 days', $domingo_resurreccion));
    $festivos[] = trasladarALunesSiguiente($corpus);
    
    // Sagrado Corazón (68 días después del Domingo de Resurrección, trasladado al lunes)
    $sagrado_corazon = date('Y-m-d', strtotime('+68 days', $domingo_resurreccion));
    $festivos[] = trasladarALunesSiguiente($sagrado_corazon);
    
    // Ordenar fechas
    sort($festivos);
    
    return $festivos;
}

/**
 * Traslada una fecha al lunes siguiente si no cae en lunes
 * 
 * @param string $fecha Fecha en formato 'Y-m-d'
 * @return string Fecha trasladada en formato 'Y-m-d'
 */
function trasladarALunesSiguiente($fecha) {
    $timestamp = strtotime($fecha);
    $dia_semana = date('N', $timestamp); // 1 = Lunes, 7 = Domingo
    
    if ($dia_semana == 1) {
        // Ya es lunes, no se mueve
        return $fecha;
    } else {
        // Calcular días hasta el próximo lunes
        $dias_hasta_lunes = (8 - $dia_semana);
        return date('Y-m-d', strtotime("+{$dias_hasta_lunes} days", $timestamp));
    }
}

/**
 * Verifica si una fecha es festivo en Colombia
 * 
 * @param string $fecha Fecha en formato 'Y-m-d'
 * @param array $festivos_cache Cache de festivos (opcional)
 * @return bool True si es festivo, False si no
 */
function esFestivoColombia($fecha, $festivos_cache = null) {
    $anio = date('Y', strtotime($fecha));
    
    if ($festivos_cache === null) {
        $festivos_cache = obtenerFestivosColombia($anio);
    }
    
    return in_array($fecha, $festivos_cache);
}

/**
 * Verifica si una fecha es día hábil (lunes a viernes, excluyendo festivos)
 * 
 * @param string $fecha Fecha en formato 'Y-m-d'
 * @param array $festivos_cache Cache de festivos (opcional)
 * @return bool True si es día hábil, False si no
 */
function esDiaHabil($fecha, $festivos_cache = null) {
    $timestamp = strtotime($fecha);
    $dia_semana = date('N', $timestamp); // 1 = Lunes, 7 = Domingo
    
    // Verificar si es sábado (6) o domingo (7)
    if ($dia_semana >= 6) {
        return false;
    }
    
    // Verificar si es festivo
    if (esFestivoColombia($fecha, $festivos_cache)) {
        return false;
    }
    
    return true;
}

/**
 * Calcula la fecha límite sumando días hábiles a una fecha de inicio
 * 
 * @param string $fecha_inicio Fecha de inicio en formato 'Y-m-d'
 * @param int $dias_habiles Cantidad de días hábiles a sumar
 * @return string Fecha límite en formato 'Y-m-d'
 */
function calcularFechaLimiteHabiles($fecha_inicio, $dias_habiles = 15) {
    $fecha_actual = strtotime($fecha_inicio);
    $dias_contados = 0;
    
    // Obtener festivos del año actual y siguiente (por si cruza años)
    $anio_actual = date('Y', $fecha_actual);
    $festivos = array_merge(
        obtenerFestivosColombia($anio_actual),
        obtenerFestivosColombia($anio_actual + 1)
    );
    
    // Avanzar día por día hasta completar los días hábiles
    while ($dias_contados < $dias_habiles) {
        // Avanzar un día
        $fecha_actual = strtotime('+1 day', $fecha_actual);
        $fecha_str = date('Y-m-d', $fecha_actual);
        
        // Verificar si es día hábil
        if (esDiaHabil($fecha_str, $festivos)) {
            $dias_contados++;
        }
    }
    
    return date('Y-m-d', $fecha_actual);
}

/**
 * Calcula los días hábiles restantes entre dos fechas
 * 
 * @param string $fecha_inicio Fecha de inicio en formato 'Y-m-d'
 * @param string $fecha_fin Fecha de fin en formato 'Y-m-d'
 * @return int Cantidad de días hábiles entre las fechas
 */
function calcularDiasHabilesEntre($fecha_inicio, $fecha_fin) {
    $inicio = strtotime($fecha_inicio);
    $fin = strtotime($fecha_fin);
    
    // Si la fecha fin es anterior a la fecha inicio, retornar negativo
    if ($fin < $inicio) {
        return -calcularDiasHabilesEntre($fecha_fin, $fecha_inicio);
    }
    
    $dias_habiles = 0;
    
    // Obtener festivos de los años involucrados
    $anio_inicio = date('Y', $inicio);
    $anio_fin = date('Y', $fin);
    $festivos = [];
    
    for ($anio = $anio_inicio; $anio <= $anio_fin; $anio++) {
        $festivos = array_merge($festivos, obtenerFestivosColombia($anio));
    }
    
    // Contar días hábiles
    $fecha_actual = $inicio;
    while ($fecha_actual <= $fin) {
        $fecha_str = date('Y-m-d', $fecha_actual);
        
        if (esDiaHabil($fecha_str, $festivos)) {
            $dias_habiles++;
        }
        
        $fecha_actual = strtotime('+1 day', $fecha_actual);
    }
    
    return $dias_habiles;
}

/**
 * Obtiene información detallada de los festivos de un año
 * 
 * @param int $anio Año para consultar
 * @return array Array asociativo con información de cada festivo
 */
function obtenerInfoFestivosColombia($anio = null) {
    if ($anio === null) {
        $anio = date('Y');
    }
    
    $festivos_info = [];
    $festivos = obtenerFestivosColombia($anio);
    
    $nombres = [
        '01-01' => 'Año Nuevo',
        '01-06' => 'Día de los Reyes Magos',
        '03-19' => 'Día de San José',
        '05-01' => 'Día del Trabajo',
        '06-29' => 'San Pedro y San Pablo',
        '07-09' => 'Virgen del Rosario de Chiquinquirá',
        '07-20' => 'Día de la Independencia',
        '08-07' => 'Batalla de Boyacá',
        '08-15' => 'Asunción de la Virgen',
        '10-12' => 'Día de la Raza',
        '11-01' => 'Todos los Santos',
        '11-11' => 'Independencia de Cartagena',
        '12-08' => 'Inmaculada Concepción',
        '12-25' => 'Navidad'
    ];
    
    $fecha_chiquinquira = $anio >= ANIO_INICIO_FESTIVO_CHIQUINQUIRA
        ? trasladarALunesSiguiente("$anio-07-09")
        : null;

    foreach ($festivos as $fecha) {
        $mes_dia = date('m-d', strtotime($fecha));
        $nombre = $nombres[$mes_dia] ?? 'Festivo Móvil';
        
        if ($fecha_chiquinquira && $fecha === $fecha_chiquinquira) {
            $nombre = 'Virgen del Rosario de Chiquinquirá';
        }
        
        // Identificar festivos de Semana Santa
        $domingo_resurreccion = easter_date($anio);
        $jueves_santo = date('Y-m-d', strtotime('-3 days', $domingo_resurreccion));
        $viernes_santo = date('Y-m-d', strtotime('-2 days', $domingo_resurreccion));
        
        if ($fecha == $jueves_santo) $nombre = 'Jueves Santo';
        if ($fecha == $viernes_santo) $nombre = 'Viernes Santo';
        
        $festivos_info[] = [
            'fecha' => $fecha,
            'nombre' => $nombre,
            'dia_semana' => date('l', strtotime($fecha)),
            'dia_semana_es' => obtenerDiaSemanaEspanol($fecha)
        ];
    }
    
    return $festivos_info;
}

/**
 * Obtiene el nombre del día de la semana en español
 * 
 * @param string $fecha Fecha en formato 'Y-m-d'
 * @return string Nombre del día en español
 */
function obtenerDiaSemanaEspanol($fecha) {
    $dias = [
        'Monday' => 'Lunes',
        'Tuesday' => 'Martes',
        'Wednesday' => 'Miércoles',
        'Thursday' => 'Jueves',
        'Friday' => 'Viernes',
        'Saturday' => 'Sábado',
        'Sunday' => 'Domingo'
    ];
    
    $dia_ingles = date('l', strtotime($fecha));
    return $dias[$dia_ingles] ?? $dia_ingles;
}

/**
 * Genera un reporte de festivos para mostrar en el sistema
 * 
 * @param int $anio Año para el reporte
 * @return string HTML del reporte
 */
function generarReporteFestivos($anio = null) {
    if ($anio === null) {
        $anio = date('Y');
    }
    
    $festivos_info = obtenerInfoFestivosColombia($anio);
    
    $html = '<div class="table-responsive">';
    $html .= '<table class="table table-sm table-bordered">';
    $html .= '<thead class="table-light">';
    $html .= '<tr>';
    $html .= '<th>#</th>';
    $html .= '<th>Fecha</th>';
    $html .= '<th>Día</th>';
    $html .= '<th>Nombre</th>';
    $html .= '</tr>';
    $html .= '</thead>';
    $html .= '<tbody>';
    
    $contador = 1;
    foreach ($festivos_info as $festivo) {
        $html .= '<tr>';
        $html .= '<td>' . $contador++ . '</td>';
        $html .= '<td>' . date('d/m/Y', strtotime($festivo['fecha'])) . '</td>';
        $html .= '<td>' . $festivo['dia_semana_es'] . '</td>';
        $html .= '<td>' . $festivo['nombre'] . '</td>';
        $html .= '</tr>';
    }
    
    $html .= '</tbody>';
    $html .= '</table>';
    $html .= '</div>';
    $html .= '<p class="text-muted"><small><i class="fas fa-info-circle"></i> Total de festivos en ' . $anio . ': ' . count($festivos_info) . '</small></p>';
    
    return $html;
}
?>

