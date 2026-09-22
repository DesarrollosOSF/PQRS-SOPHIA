<?php
/**
 * Sophía - Helper Informe Gerencial PQRSF
 * "Gestión con sabiduría y cercanía"
 *
 * Soporte para el panel automático gerencial SIN modificar la BD (solo lectura).
 * - Rango de fechas dinámico: dia, semana, mes, trimestre, semestre, ano, personalizado
 * - Estado gerencial: Abierto, En trámite, Cerrado (igual que el origen)
 *   (conforme = cerrada dentro del ANS de 15 días hábiles; no conforme = fuera de término)
 * - Tiempos vía tabVersion (Abierto->En trámite->Cerrado) con fallback a modified
 * - ANS normativo 15 días hábiles / Meta interna 8 días hábiles
 */

require_once __DIR__ . '/festivos_colombia.php';

define('ANS_NORMATIVO_DIAS', 15);
define('META_INTERNA_DIAS', 8);

/**
 * Resuelve un preset de rango a [inicio, fin] en Y-m-d.
 * Presets: dia, semana, mes, trimestre, semestre, ano, personalizado
 */
function gerencialResolverRango($preset, $fi = null, $ff = null) {
    $hoy = date('Y-m-d');
    switch ($preset) {
        case 'dia':
            return [$hoy, $hoy];
        case 'semana':
            // Lunes a domingo de la semana actual
            $lunes = date('Y-m-d', strtotime('monday this week'));
            $domingo = date('Y-m-d', strtotime('sunday this week'));
            return [$lunes, $domingo];
        case 'mes':
            return [date('Y-m-01'), date('Y-m-t')];
        case 'trimestre': {
            $m = (int)date('n');
            $trim = (int)ceil($m / 3);
            $ini_m = ($trim - 1) * 3 + 1;
            $fin_m = $trim * 3;
            $y = date('Y');
            $ini = sprintf('%s-%02d-01', $y, $ini_m);
            $fin = date('Y-m-t', strtotime(sprintf('%s-%02d-01', $y, $fin_m)));
            return [$ini, $fin];
        }
        case 'semestre': {
            $m = (int)date('n');
            $y = date('Y');
            if ($m <= 6) return ["$y-01-01", "$y-06-30"];
            return ["$y-07-01", "$y-12-31"];
        }
        case 'ano':
            return [date('Y-01-01'), date('Y-12-31')];
        case 'personalizado':
        default: {
            $d = DateTime::createFromFormat('Y-m-d', (string)$fi);
            $h = DateTime::createFromFormat('Y-m-d', (string)$ff);
            if ($d && $h) {
                $a = $d->format('Y-m-d'); $b = $h->format('Y-m-d');
                if ($a > $b) { [$a, $b] = [$b, $a]; }
                return [$a, $b];
            }
            // default: últimos 3 meses
            return [date('Y-m-d', strtotime('-3 months')), $hoy];
        }
    }
}

/**
 * Normaliza el nombre del proceso responsable.
 * NO modifica BD: agrupa NULL/vacío como "Sin asignar".
 */
function gerencialNormalizarProceso($valor) {
    $v = trim((string)($valor ?? ''));
    if ($v === '' || strtolower($v) === 'null') return 'Sin asignar';
    return $v;
}

/**
 * Obtiene lista de procesos (lectura) para el filtro.
 * Devuelve array ordenado de nombres únicos.
 */
function gerencialObtenerProcesos($mysqli, $fecha_inicio, $fecha_fin) {
    $sql = "SELECT DISTINCT tp.custom_área_responsable AS p
            FROM `tabPQRFS` tp
            WHERE DATE(tp.creation) BETWEEN ? AND ?
            ORDER BY p";
    $stmt = $mysqli->prepare($sql);
    $stmt->bind_param('ss', $fecha_inicio, $fecha_fin);
    $stmt->execute();
    $res = $stmt->get_result();
    $out = [];
    while ($r = $res->fetch_assoc()) {
        $out[] = gerencialNormalizarProceso($r['p']);
    }
    $stmt->close();
    $out = array_values(array_unique($out));
    sort($out, SORT_STRING | SORT_FLAG_CASE);
    return $out;
}

/**
 * Trae en UNA sola consulta los cambios de estado desde tabVersion
 * para un conjunto de tickets. Retorna [docname => ['en_tramite' => datetime|null, 'cerrado' => datetime|null]]
 */
function gerencialObtenerHitosVersion($mysqli, array $nombres) {
    $hitos = [];
    foreach ($nombres as $n) { $hitos[$n] = ['en_tramite' => null, 'cerrado' => null]; }
    if (empty($nombres)) return $hitos;

    // Procesar en lotes de 500 para no exceder límites
    foreach (array_chunk($nombres, 500) as $lote) {
        $ph = implode(',', array_fill(0, count($lote), '?'));
        $types = str_repeat('s', count($lote));
        $sql = "SELECT docname, creation, data FROM tabVersion
                WHERE ref_doctype = 'PQRFS' AND docname IN ($ph)
                ORDER BY creation ASC";
        $stmt = $mysqli->prepare($sql);
        if (!$stmt) continue;
        $stmt->bind_param($types, ...$lote);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $doc = $row['docname'];
            if (!isset($hitos[$doc])) continue;
            $data = json_decode($row['data'], true);
            if (!is_array($data) || empty($data['changed'])) continue;
            foreach ($data['changed'] as $ch) {
                // formato: ["estado","Abierto","En trámite"] o ["docstatus",0,1]
                if (!is_array($ch) || count($ch) < 3) continue;
                if ($ch[0] !== 'estado') continue;
                $nuevo = (string)$ch[2];
                if ($nuevo === 'En trámite' && $hitos[$doc]['en_tramite'] === null) {
                    $hitos[$doc]['en_tramite'] = $row['creation'];
                }
                if ($nuevo === 'Cerrado' && $hitos[$doc]['cerrado'] === null) {
                    $hitos[$doc]['cerrado'] = $row['creation'];
                }
            }
        }
        $stmt->close();
    }
    return $hitos;
}

/**
 * Enriquece cada PQRS con:
 * - Proceso (normalizado), Estado_Gerencial, Fecha_Primera_Respuesta, Fecha_Cierre,
 *   Dias_Habiles_Primera, Dias_Habiles_Cierre, Cumple_ANS15, Cumple_Meta8, Vencida
 */
function gerencialEnriquecerPQRS(array $pqrs_data, array $hitos) {
    foreach ($pqrs_data as &$pqr) {
        $pqr['Proceso'] = gerencialNormalizarProceso($pqr['custom_área_responsable'] ?? null);
        $creacion = substr((string)($pqr['Fecha_Creacion_Full'] ?? $pqr['Fecha_Creacion']), 0, 19);
        $fecha_creacion_d = substr((string)$pqr['Fecha_Creacion'], 0, 10);
        $estado_origen = (string)($pqr['estado'] ?? '');
        $name = $pqr['name'];

        $hit = $hitos[$name] ?? ['en_tramite' => null, 'cerrado' => null];

        // Primera respuesta: primer paso a En trámite; fallback: si ya no está Abierto, usar modified
        $fpr = $hit['en_tramite'];
        if ($fpr === null && $estado_origen !== '' && $estado_origen !== 'Abierto') {
            $fpr = $pqr['Fecha_Ultima_Modificacion'] ?? null;
        }
        // Cierre: paso a Cerrado; fallback: si estado=Cerrado, usar modified
        $fc = $hit['cerrado'];
        if ($fc === null && $estado_origen === 'Cerrado') {
            $fc = $pqr['Fecha_Ultima_Modificacion'] ?? null;
        }

        $pqr['Fecha_Primera_Respuesta'] = $fpr ? substr((string)$fpr, 0, 19) : null;
        $pqr['Fecha_Cierre'] = $fc ? substr((string)$fc, 0, 19) : null;

        // Días hábiles (conteo inclusivo según festivos_colombia.php)
        if ($pqr['Fecha_Primera_Respuesta']) {
            $pqr['Dias_Habiles_Primera'] = calcularDiasHabilesEntre($fecha_creacion_d, substr($pqr['Fecha_Primera_Respuesta'], 0, 10));
        } else {
            $pqr['Dias_Habiles_Primera'] = null;
        }
        if ($pqr['Fecha_Cierre']) {
            $pqr['Dias_Habiles_Cierre'] = calcularDiasHabilesEntre($fecha_creacion_d, substr($pqr['Fecha_Cierre'], 0, 10));
        } else {
            $pqr['Dias_Habiles_Cierre'] = null;
        }

        // Fecha límite ANS (15 hábiles) y evaluación de vencimiento
        $limite = $pqr['Fecha_Maxima_Respuesta_Habiles'] ?? null;
        if ($pqr['Fecha_Cierre']) {
            $pqr['Vencida'] = (substr($pqr['Fecha_Cierre'], 0, 10) > $limite);
        } else {
            $pqr['Vencida'] = (date('Y-m-d') > $limite);
        }

        // Estado gerencial: Abierto / En trámite / Cerrado (sin conforme/no conforme).
        // La conformidad ANS se mide aparte con Vencida / Cumple_ANS15.
        $e_norm = mb_strtolower(trim($estado_origen));
        if ($e_norm === 'abierto' || $e_norm === 'abierta') {
            $pqr['Estado_Gerencial'] = 'Abierto';
        } elseif ($e_norm === 'en trámite' || $e_norm === 'en tramite') {
            $pqr['Estado_Gerencial'] = 'En trámite';
        } else {
            $pqr['Estado_Gerencial'] = 'Cerrado';
        }

        $pqr['Cumple_ANS15'] = ($pqr['Dias_Habiles_Cierre'] !== null) ? ($pqr['Dias_Habiles_Cierre'] <= ANS_NORMATIVO_DIAS) : null;
        $pqr['Cumple_Meta8'] = ($pqr['Dias_Habiles_Cierre'] !== null) ? ($pqr['Dias_Habiles_Cierre'] <= META_INTERNA_DIAS) : null;
    }
    unset($pqr);
    return $pqrs_data;
}

/**
 * Métricas agregadas por proceso:
 * [proceso => ['total'=>, 'cerradas'=>, 'avg_primera'=>, 'avg_cierre'=>, 'cumple_ans'=>, 'pct_ans'=>, 'cumple_meta8'=>, 'pct_meta8'=>]]
 */
function gerencialMetricasPorProceso(array $pqrs) {
    $m = [];
    foreach ($pqrs as $p) {
        $proc = $p['Proceso'];
        if (!isset($m[$proc])) {
            $m[$proc] = ['total' => 0, 'cerradas' => 0, 'sum_primera' => 0, 'n_primera' => 0,
                         'sum_cierre' => 0, 'n_cierre' => 0, 'cumple_ans' => 0, 'cumple_meta8' => 0];
        }
        $m[$proc]['total']++;
        if ($p['Dias_Habiles_Primera'] !== null) { $m[$proc]['sum_primera'] += $p['Dias_Habiles_Primera']; $m[$proc]['n_primera']++; }
        if ($p['Dias_Habiles_Cierre'] !== null) {
            $m[$proc]['cerradas']++;
            $m[$proc]['sum_cierre'] += $p['Dias_Habiles_Cierre']; $m[$proc]['n_cierre']++;
            if (!empty($p['Cumple_ANS15'])) $m[$proc]['cumple_ans']++;
            if (!empty($p['Cumple_Meta8'])) $m[$proc]['cumple_meta8']++;
        }
    }
    foreach ($m as $k => &$v) {
        $v['avg_primera'] = $v['n_primera'] > 0 ? round($v['sum_primera'] / $v['n_primera'], 1) : null;
        $v['avg_cierre'] = $v['n_cierre'] > 0 ? round($v['sum_cierre'] / $v['n_cierre'], 1) : null;
        $v['pct_ans'] = $v['cerradas'] > 0 ? round($v['cumple_ans'] / $v['cerradas'] * 100, 1) : null;
        $v['pct_meta8'] = $v['cerradas'] > 0 ? round($v['cumple_meta8'] / $v['cerradas'] * 100, 1) : null;
    }
    unset($v);
    return $m;
}
