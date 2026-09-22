<?php
/**
 * Sophía - Helper para PQRS
 * "Gestión con sabiduría y cercanía"
 * 
 * Funciones auxiliares para el procesamiento de PQRS con días hábiles
 */

require_once __DIR__ . '/festivos_colombia.php';
require_once __DIR__ . '/app.php';

/**
 * Procesa los datos de PQRS agregando cálculos de días hábiles
 * 
 * @param array $pqrs_data Array de PQRS desde la base de datos
 * @return array Array procesado con información de días hábiles
 */
function procesarPQRSConDiasHabiles($pqrs_data) {
    foreach ($pqrs_data as &$pqr) {
        // Calcular fecha límite en días hábiles
        $fecha_limite_habiles = obtenerFechaLimiteHabiles($pqr['Fecha_Creacion']);
        
        // Calcular días hábiles restantes
        $dias_habiles_restantes = calcularDiasHabilesRestantes($pqr['Fecha_Creacion']);
        
        // Agregar campos adicionales
        $pqr['Fecha_Maxima_Respuesta_Habiles'] = $fecha_limite_habiles;
        $pqr['Dias_Habiles_Restantes'] = $dias_habiles_restantes;
        
        // Mantener los cálculos originales por compatibilidad
        if (!isset($pqr['Fecha_Maxima_Respuesta'])) {
            $pqr['Fecha_Maxima_Respuesta'] = date('Y-m-d', strtotime($pqr['Fecha_Creacion'] . ' +15 days'));
        }
        if (!isset($pqr['Dias_Restantes'])) {
            $fecha_limite = $pqr['Fecha_Maxima_Respuesta'];
            $hoy = date('Y-m-d');
            $pqr['Dias_Restantes'] = round((strtotime($fecha_limite) - strtotime($hoy)) / (60 * 60 * 24));
        }
        
        // Determinar si está vencida (usando días hábiles)
        $pqr['Esta_Vencida_Habiles'] = $dias_habiles_restantes < 0;
        $pqr['Esta_En_Riesgo_Habiles'] = $dias_habiles_restantes >= 0 && $dias_habiles_restantes <= 3;
    }
    
    return $pqrs_data;
}

/**
 * Obtiene la consulta SQL para PQRS (sin cálculo de días hábiles en SQL)
 * Los días hábiles se calcularán en PHP después de obtener los datos
 * 
 * @return string SQL query
 */
function obtenerConsultaPQRS() {
    return "
    SELECT
        tp.name,
        CASE 
            WHEN tp.docstatus = 0 THEN 'Borrador'
            WHEN tp.docstatus = 1 THEN 'Validado'
            WHEN tp.docstatus = 2 THEN 'Cancelado'
            ELSE 'Desconocido'
        END AS Estado_Documento,
        DATE(tp.creation) AS Fecha_Creacion,
        tp.tipo_peticion,
        tp.estado,
        tp.custom_prioridad,
        tp.`owner` AS Creado_Por,
        tp.modified_by AS Usuario_Ultima_Modificacion,
        tp.modified AS Fecha_Ultima_Modificacion,
        tp.fecha AS Fecha_Ticket,
        tp.contrato AS Contrato_Prevision,
        tp.contratop AS Contrato_Parque,
        tp.nombre AS Nombre_Peticionario,
        tp.address_line1 AS Direccion_Notificacion,
        tp.email_id AS Email_Notificacion,
        tp.phone AS Telefono_Notificacion,
        tp.custom_canal_de_entrada,
        tp.descripcion_queja,
        tp.custom_área_responsable
    FROM `tabPQRFS` AS tp
    WHERE DATE(tp.creation) BETWEEN ? AND ?
    ";
}

/**
 * Ordena PQRS priorizando las que están próximas a vencer (por días hábiles)
 * 
 * @param array $pqrs_data Array de PQRS procesadas
 * @return array Array ordenado
 */
function ordenarPQRSPorUrgencia($pqrs_data) {
    usort($pqrs_data, function($a, $b) {
        // Prioridad 1: Estado (Borrador > Validado > Cancelado)
        $orden_estado = ['Borrador' => 1, 'Validado' => 2, 'Cancelado' => 3];
        $prioridad_a = $orden_estado[$a['Estado_Documento']] ?? 4;
        $prioridad_b = $orden_estado[$b['Estado_Documento']] ?? 4;
        
        if ($prioridad_a != $prioridad_b) {
            return $prioridad_a - $prioridad_b;
        }
        
        // Prioridad 2: Para Borrador, ordenar por días hábiles restantes (ascendente)
        if ($a['Estado_Documento'] == 'Borrador') {
            return $a['Dias_Habiles_Restantes'] - $b['Dias_Habiles_Restantes'];
        }
        
        // Prioridad 3: Por fecha de creación (descendente)
        return strtotime($b['Fecha_Creacion']) - strtotime($a['Fecha_Creacion']);
    });
    
    return $pqrs_data;
}

/**
 * Calcula estadísticas de PQRS usando días hábiles
 * 
 * @param array $pqrs_data Array de PQRS procesadas
 * @return array Array con estadísticas
 */
function calcularEstadisticasPQRS($pqrs_data) {
    $estadisticas = [
        'total' => count($pqrs_data),
        'vencidas_habiles' => 0,
        'en_riesgo_habiles' => 0,
        'borrador' => 0,
        'validado' => 0,
        'cancelado' => 0,
        'dias_habiles_promedio' => 0,
        'cumplimiento_porcentaje' => 0
    ];
    
    $suma_dias_habiles = 0;
    $count_borrador = 0;
    
    foreach ($pqrs_data as $pqr) {
        // Contar por estado
        if ($pqr['Estado_Documento'] == 'Borrador') {
            $estadisticas['borrador']++;
            $count_borrador++;
            $suma_dias_habiles += $pqr['Dias_Habiles_Restantes'];
            
            // Contar vencidas y en riesgo (solo Borrador)
            if ($pqr['Esta_Vencida_Habiles']) {
                $estadisticas['vencidas_habiles']++;
            } elseif ($pqr['Esta_En_Riesgo_Habiles']) {
                $estadisticas['en_riesgo_habiles']++;
            }
        } elseif ($pqr['Estado_Documento'] == 'Validado') {
            $estadisticas['validado']++;
        } elseif ($pqr['Estado_Documento'] == 'Cancelado') {
            $estadisticas['cancelado']++;
        }
    }
    
    // Calcular promedio de días hábiles restantes (solo para Borrador)
    if ($count_borrador > 0) {
        $estadisticas['dias_habiles_promedio'] = round($suma_dias_habiles / $count_borrador, 1);
    }
    
    // Calcular porcentaje de cumplimiento
    if ($count_borrador > 0) {
        $cumplidas = $count_borrador - $estadisticas['vencidas_habiles'];
        $estadisticas['cumplimiento_porcentaje'] = round(($cumplidas / $count_borrador) * 100, 1);
    } else {
        $estadisticas['cumplimiento_porcentaje'] = 100;
    }
    
    return $estadisticas;
}

/**
 * Filtra PQRS por criterios específicos
 * 
 * @param array $pqrs_data Array de PQRS procesadas
 * @param array $filtros Array de filtros a aplicar
 * @return array Array filtrado
 */
function filtrarPQRS($pqrs_data, $filtros = []) {
    $resultado = $pqrs_data;
    
    // Filtro por estado
    if (!empty($filtros['estado'])) {
        $resultado = array_filter($resultado, function($pqr) use ($filtros) {
            return $pqr['Estado_Documento'] == $filtros['estado'];
        });
    }
    
    // Filtro por prioridad
    if (!empty($filtros['prioridad'])) {
        $resultado = array_filter($resultado, function($pqr) use ($filtros) {
            return ($pqr['custom_prioridad'] ?? '') == $filtros['prioridad'];
        });
    }
    
    // Filtro por área
    if (!empty($filtros['area'])) {
        $resultado = array_filter($resultado, function($pqr) use ($filtros) {
            return ($pqr['custom_área_responsable'] ?? '') == $filtros['area'];
        });
    }
    
    // Filtro por vencidas (usando días hábiles)
    if (!empty($filtros['vencidas']) && $filtros['vencidas'] == 'si') {
        $resultado = array_filter($resultado, function($pqr) {
            return $pqr['Estado_Documento'] == 'Borrador' && $pqr['Esta_Vencida_Habiles'];
        });
    }
    
    // Filtro por en riesgo
    if (!empty($filtros['en_riesgo']) && $filtros['en_riesgo'] == 'si') {
        $resultado = array_filter($resultado, function($pqr) {
            return $pqr['Estado_Documento'] == 'Borrador' && $pqr['Esta_En_Riesgo_Habiles'];
        });
    }
    
    return array_values($resultado); // Reindexar el array
}

/**
 * Genera un badge HTML para días hábiles restantes
 * 
 * @param int $dias_habiles Días hábiles restantes
 * @param string $estado Estado del documento
 * @return string HTML del badge
 */
function generarBadgeDiasHabiles($dias_habiles, $estado) {
    if ($estado != 'Borrador') {
        return '<span class="badge bg-secondary">N/A</span>';
    }
    $clase = 'success';
    if ($dias_habiles < 0) {
        $clase = 'danger';
    } elseif ($dias_habiles <= 3) {
        $clase = 'warning';
    }
    
    $texto = $dias_habiles . ' días hábiles';
    if ($dias_habiles < 0) {
        $texto = abs($dias_habiles) . ' días vencidos';
    }
    
    return '<span class="badge bg-' . $clase . '">' . $texto . '</span>';
}

/**
 * Genera información de tooltip para días hábiles
 * 
 * @param array $pqr Datos de la PQRS
 * @return string HTML del tooltip
 */
function generarTooltipDiasHabiles($pqr) {
    $info = '';
    $info .= 'Fecha Creación: ' . date('d/m/Y', strtotime($pqr['Fecha_Creacion'])) . '\n';
    $info .= 'Fecha Límite (días hábiles): ' . date('d/m/Y', strtotime($pqr['Fecha_Maxima_Respuesta_Habiles'])) . '\n';
    $info .= 'Días hábiles restantes: ' . $pqr['Dias_Habiles_Restantes'] . '\n';
    $info .= '\n(No se cuentan sábados, domingos ni festivos de Colombia)';
    
    return htmlspecialchars($info);
}

/**
 * Exporta información de PQRS para debugging
 * 
 * @param array $pqr Datos de una PQRS
 * @return array Información formateada
 */
function debugPQRS($pqr) {
    return [
        'ID' => $pqr['name'],
        'Estado' => $pqr['Estado_Documento'],
        'Fecha Creación' => $pqr['Fecha_Creacion'],
        'Fecha Límite (calendario)' => $pqr['Fecha_Maxima_Respuesta'],
        'Días Restantes (calendario)' => $pqr['Dias_Restantes'],
        'Fecha Límite (hábiles)' => $pqr['Fecha_Maxima_Respuesta_Habiles'],
        'Días Hábiles Restantes' => $pqr['Dias_Habiles_Restantes'],
        'Vencida (hábiles)' => $pqr['Esta_Vencida_Habiles'] ? 'SÍ' : 'NO',
        'En Riesgo (hábiles)' => $pqr['Esta_En_Riesgo_Habiles'] ? 'SÍ' : 'NO'
    ];
}
?>

