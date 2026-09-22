<?php
$page_title = 'Sophía - Informe Gerencial PQRSF';
require_once '../includes/header.php';
require_once '../config/database.php';
require_once '../config/pqrs_helper.php';
require_once '../config/gerencial_helper.php';

// ============ 1. FILTROS (GET) ============
$preset = $_GET['preset'] ?? 'mes';
$presets_validos = ['dia','semana','mes','trimestre','semestre','ano','personalizado'];
if (!in_array($preset, $presets_validos, true)) $preset = 'mes';

$f_proceso = trim($_GET['proceso'] ?? '');
$f_estado_g = trim($_GET['estado_gerencial'] ?? '');
$f_tipo = trim($_GET['tipo'] ?? '');
$f_categoria = trim($_GET['categoria'] ?? '');
$fi_get = trim($_GET['fecha_inicio'] ?? '');
$ff_get = trim($_GET['fecha_fin'] ?? '');

[$fecha_inicio, $fecha_fin] = gerencialResolverRango($preset, $fi_get, $ff_get);

$estados_gerenciales = ['Abierto','En trámite','Cerrado'];

// ============ 2. CONSULTA PRINCIPAL (solo lectura) ============
$mysqli = conectarDB();
$error_bd = null;
$pqrs_data = [];
$tipos_disponibles = [];
$procesos_disponibles = [];
$categorias_disponibles = []; // ['code'=>, 'nombre'=>, 'tipo'=>]

if (!$mysqli) {
    $error_bd = 'No se pudo conectar a la base de datos';
} else {
    // Tipos para el filtro: catálogo completo (toda la BD + catálogo de categorías),
    // para que tipos con pocos casos (ej. Felicitación) siempre aparezcan aunque el rango no traiga datos
    $r = $mysqli->query("SELECT DISTINCT tp.tipo_peticion AS t FROM `tabPQRFS` tp WHERE tp.tipo_peticion IS NOT NULL AND tp.tipo_peticion != ''
        UNION SELECT DISTINCT c.tipo_peticion AS t FROM `tabCategoria PQRSF` c WHERE c.tipo_peticion IS NOT NULL AND c.tipo_peticion != ''
        ORDER BY t");
    if ($r) { while ($row = $r->fetch_assoc()) { if (!empty($row['t'])) $tipos_disponibles[] = $row['t']; } }

    $procesos_disponibles = gerencialObtenerProcesos($mysqli, $fecha_inicio, $fecha_fin);

    // Categorías PQRSF: catálogo completo (no limitado al rango),
    // para que todas las categorías de cada tipo —incluida Felicitación— estén en el filtro
    $r = $mysqli->query("SELECT c.name AS code, c.nombre_categoria AS nombre, c.tipo_peticion AS tipo
        FROM `tabCategoria PQRSF` c ORDER BY c.tipo_peticion, c.nombre_categoria");
    if ($r) {
        while ($row = $r->fetch_assoc()) {
            $categorias_disponibles[] = ['code' => $row['code'], 'nombre' => $row['nombre'] ?: $row['code'], 'tipo' => $row['tipo'] ?: 'Sin tipo'];
        }
    }

    // Datos base + nombre de categoría (motivo)
    $sql = "
    SELECT
        tp.name,
        DATE(tp.creation) AS Fecha_Creacion,
        tp.creation AS Fecha_Creacion_Full,
        tp.modified AS Fecha_Ultima_Modificacion,
        tp.tipo_peticion,
        tp.estado,
        tp.custom_prioridad,
        tp.custom_canal_de_entrada,
        tp.custom_área_responsable,
        tp.categoria_pqrsf,
        tp.descripcion_queja,
        cat.nombre_categoria AS Motivo,
        cat.tipo_peticion AS Tipo_Categoria
    FROM `tabPQRFS` AS tp
    LEFT JOIN `tabCategoria PQRSF` AS cat ON cat.name = tp.categoria_pqrsf
    WHERE DATE(tp.creation) BETWEEN ? AND ?
    ORDER BY tp.creation DESC
    LIMIT 5000";
    $stmt = $mysqli->prepare($sql);
    $stmt->bind_param('ss', $fecha_inicio, $fecha_fin);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) { $pqrs_data[] = $row; }
    $stmt->close();

    // Días hábiles base (fecha límite ANS 15) + hitos de versión (tiempos reales)
    $pqrs_data = procesarPQRSConDiasHabiles($pqrs_data);
    $nombres = array_column($pqrs_data, 'name');
    $hitos = gerencialObtenerHitosVersion($mysqli, $nombres);
    $pqrs_data = gerencialEnriquecerPQRS($pqrs_data, $hitos);
    $mysqli->close();
}

// ============ 3. APLICAR FILTROS EN PHP (proceso / estado / tipo / categoría) ============
$pqrs_f = array_values(array_filter($pqrs_data, function($p) use ($f_proceso, $f_estado_g, $f_tipo, $f_categoria) {
    if ($f_proceso !== '' && $p['Proceso'] !== $f_proceso) return false;
    if ($f_estado_g !== '' && $p['Estado_Gerencial'] !== $f_estado_g) return false;
    if ($f_tipo !== '' && ($p['tipo_peticion'] ?? '') !== $f_tipo) return false;
    if ($f_categoria !== '' && ($p['categoria_pqrsf'] ?? '') !== $f_categoria) return false;
    return true;
}));

// ============ 4. AGREGADOS GERENCIALES ============
$metricas = gerencialMetricasPorProceso($pqrs_f);

// 4.1 Pareto: distribución por proceso (desc) + % acumulado
$metricas_tot_tmp = array_map(fn($v) => $v['total'], $metricas);
arsort($metricas_tot_tmp);
$pareto_labels = array_keys($metricas_tot_tmp);
$pareto_vals = array_values($metricas_tot_tmp);
$total_f = array_sum($pareto_vals);
$pareto_acum = [];
$ac = 0;
foreach ($pareto_vals as $v) { $ac += $v; $pareto_acum[] = $total_f > 0 ? round($ac / $total_f * 100, 1) : 0; }

// 4.2 Matriz Proceso vs Tipo: % sobre el total de cada proceso (cada fila suma 100%) + detalle por categoría
$tipos_matrix = [];
foreach ($pqrs_f as $p) { $t = $p['tipo_peticion'] ?? 'Sin tipo'; $tipos_matrix[$t] = true; }
$tipos_matrix = array_keys($tipos_matrix);
sort($tipos_matrix);
$matriz = []; $matriz_pct = []; $row_tot = [];
foreach ($metricas as $proc => $_) {
    foreach ($tipos_matrix as $t) $matriz[$proc][$t] = 0;
    $row_tot[$proc] = 0;
}
$detalle_cats = []; // "proc\0tipo" => [motivo => n]
foreach ($pqrs_f as $p) {
    $proc = $p['Proceso']; $t = $p['tipo_peticion'] ?? 'Sin tipo';
    $matriz[$proc][$t]++;
    $row_tot[$proc]++;
    $mot = trim((string)($p['Motivo'] ?? '')) !== '' ? $p['Motivo'] : 'Sin categoría';
    $k = $proc . "\0" . $t;
    if (!isset($detalle_cats[$k])) $detalle_cats[$k] = [];
    $detalle_cats[$k][$mot] = ($detalle_cats[$k][$mot] ?? 0) + 1;
}
foreach ($matriz as $proc => $fila) {
    foreach ($fila as $t => $v) {
        $matriz_pct[$proc][$t] = $row_tot[$proc] > 0 ? round($v / $row_tot[$proc] * 100, 1) : 0;
    }
}
// Mapa JSON para el modal: ["proc||tipo" => [{m, n, pct}]]
$detalle_json = [];
foreach ($detalle_cats as $k => $mapa) {
    [$dp, $dt] = explode("\0", $k);
    $tot = array_sum($mapa);
    arsort($mapa);
    $lista = [];
    foreach ($mapa as $m => $n) $lista[] = ['m' => $m, 'n' => $n, 'pct' => $tot > 0 ? round($n / $tot * 100, 1) : 0];
    $detalle_json[$dp . '||' . $dt] = $lista;
}

// 4.3 Evolución mensual del tiempo de cierre (promedio días hábiles por mes de creación)
$evol = [];
foreach ($pqrs_f as $p) {
    if ($p['Dias_Habiles_Cierre'] === null) continue;
    $mes = substr($p['Fecha_Creacion'], 0, 7);
    if (!isset($evol[$mes])) $evol[$mes] = ['sum' => 0, 'n' => 0];
    $evol[$mes]['sum'] += $p['Dias_Habiles_Cierre'];
    $evol[$mes]['n']++;
}
ksort($evol);
$evol_labels = array_keys($evol);
$evol_vals = array_map(fn($v) => round($v['sum'] / max(1, $v['n']), 1), array_values($evol));

// 4.4 Cumplimiento global ANS
$cerradas = array_filter($pqrs_f, fn($p) => $p['Dias_Habiles_Cierre'] !== null);
$n_cerr = count($cerradas);
$n_ans = count(array_filter($cerradas, fn($p) => !empty($p['Cumple_ANS15'])));
$n_meta8 = count(array_filter($cerradas, fn($p) => !empty($p['Cumple_Meta8'])));
$pct_ans_global = $n_cerr > 0 ? round($n_ans / $n_cerr * 100, 1) : 0;

// KPIs cabecera
$kpi_total = count($pqrs_f);
$kpi_abiertas = count(array_filter($pqrs_f, fn($p) => $p['Estado_Gerencial'] === 'Abierto'));
$kpi_tramite = count(array_filter($pqrs_f, fn($p) => $p['Estado_Gerencial'] === 'En trámite'));
$kpi_cerradas = count(array_filter($pqrs_f, fn($p) => $p['Estado_Gerencial'] === 'Cerrado'));
$avg_primera_global = null; $avg_cierre_global = null;
$sp = array_filter(array_column($pqrs_f, 'Dias_Habiles_Primera'), fn($v) => $v !== null);
$sc = array_filter(array_column($pqrs_f, 'Dias_Habiles_Cierre'), fn($v) => $v !== null);
if ($sp) $avg_primera_global = round(array_sum($sp) / count($sp), 1);
if ($sc) $avg_cierre_global = round(array_sum($sc) / count($sc), 1);

function urlGerencial($over = []) {
    $base = ['preset' => $_GET['preset'] ?? 'mes', 'fecha_inicio' => $_GET['fecha_inicio'] ?? '',
             'fecha_fin' => $_GET['fecha_fin'] ?? '', 'proceso' => $_GET['proceso'] ?? '',
             'estado_gerencial' => $_GET['estado_gerencial'] ?? '', 'tipo' => $_GET['tipo'] ?? '', 'categoria' => $_GET['categoria'] ?? ''];
    $q = array_merge($base, $over);
    return 'gerencial.php?' . http_build_query($q);
}
?>

<div class="row mb-3">
    <div class="col-12">
        <h2 class="mb-1"><i class="fas fa-briefcase text-primary me-2"></i>Informe Gerencial PQRSF</h2>
        <div class="d-flex flex-wrap gap-2 align-items-center mb-2">
            <span class="badge bg-primary fs-6"><i class="fas fa-calendar-alt me-1"></i><?php echo date('d/m/Y', strtotime($fecha_inicio)); ?> al <?php echo date('d/m/Y', strtotime($fecha_fin)); ?></span>
            <span class="badge bg-secondary fs-6"><?php echo ['dia'=>'Vista: por día','semana'=>'Vista: por semana','mes'=>'Vista: por mes','trimestre'=>'Vista: por trimestre','semestre'=>'Vista: por semestre','ano'=>'Vista: por año','personalizado'=>'Vista: personalizada'][$preset] ?? $preset; ?></span>
            <span class="badge bg-info text-dark fs-6"><?php echo $kpi_total; ?> PQRSF · <?php echo $n_cerr; ?> cerradas</span>
            <span class="badge bg-<?php echo $pct_ans_global>=90?'success':($pct_ans_global>=70?'warning':'danger'); ?> fs-6">ANS 15h: <?php echo $pct_ans_global; ?>%</span>
        </div>
        <p class="text-muted mb-0 small">ANS normativo <strong>15</strong> días hábiles · Meta interna <strong>8</strong> días hábiles ·
        Primera respuesta y cierre vía historial <code>tabVersion</code> (Abierto→En trámite→Cerrado). Filtro de sede omitido: no existe columna en BD.</p>
    </div>
</div>

<?php if ($error_bd): ?>
<div class="alert alert-danger"><?php echo htmlspecialchars($error_bd); ?></div>
<?php endif; ?>

<!-- FILTROS -->
<div class="card mb-4">
    <div class="card-header"><h5 class="mb-0"><i class="fas fa-filter me-2"></i>Filtros del informe</h5></div>
    <div class="card-body">
        <form method="GET" class="row g-3">
            <div class="col-md-3">
                <label class="form-label">Rango dinámico</label>
                <select class="form-select" name="preset" id="preset" onchange="toggleCustomDates()">
                    <?php foreach (['dia'=>'Por día','semana'=>'Por semana','mes'=>'Por mes','trimestre'=>'Por trimestre','semestre'=>'Por semestre','ano'=>'Por año','personalizado'=>'Personalizado'] as $k=>$lbl): ?>
                    <option value="<?php echo $k; ?>" <?php echo $preset===$k?'selected':''; ?>><?php echo $lbl; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2 custom-date">
                <label class="form-label">Fecha inicio</label>
                <input type="date" class="form-control" name="fecha_inicio" value="<?php echo htmlspecialchars($preset==='personalizado'?$fecha_inicio:($fi_get?:$fecha_inicio)); ?>">
            </div>
            <div class="col-md-2 custom-date">
                <label class="form-label">Fecha fin</label>
                <input type="date" class="form-control" name="fecha_fin" value="<?php echo htmlspecialchars($preset==='personalizado'?$fecha_fin:($ff_get?:$fecha_fin)); ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label">Proceso responsable</label>
                <select class="form-select" name="proceso">
                    <option value="">Todos</option>
                    <?php foreach ($procesos_disponibles as $pr): ?>
                    <option value="<?php echo htmlspecialchars($pr); ?>" <?php echo $f_proceso===$pr?'selected':''; ?>><?php echo htmlspecialchars($pr); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">Estado</label>
                <select class="form-select" name="estado_gerencial">
                    <option value="">Todos</option>
                    <?php foreach ($estados_gerenciales as $e): ?>
                    <option value="<?php echo $e; ?>" <?php echo $f_estado_g===$e?'selected':''; ?>><?php echo $e; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">Tipo Peticion</label>
                <select class="form-select" name="tipo" id="f_tipo" onchange="filtrarCatsPorTipo()">
                    <option value="">Todos</option>
                    <?php foreach ($tipos_disponibles as $t): ?>
                    <option value="<?php echo htmlspecialchars($t); ?>" <?php echo $f_tipo===$t?'selected':''; ?>><?php echo htmlspecialchars($t); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label">Categoría PQRSF</label>
                <select class="form-select" name="categoria" id="f_categoria">
                    <option value="">Todas</option>
                    <?php
                    $grupo = null;
                    foreach ($categorias_disponibles as $c):
                        if ($c['tipo'] !== $grupo) { if ($grupo !== null) echo '</optgroup>'; echo '<optgroup label="' . htmlspecialchars($c['tipo']) . '">'; $grupo = $c['tipo']; }
                    ?>
                    <option value="<?php echo htmlspecialchars($c['code']); ?>" data-tipo="<?php echo htmlspecialchars($c['tipo']); ?>" <?php echo $f_categoria===$c['code']?'selected':''; ?>><?php echo htmlspecialchars($c['nombre']); ?></option>
                    <?php endforeach; if ($grupo !== null) echo '</optgroup>'; ?>
                </select>
                <small class="text-muted">Cada tipo agrupa sus categorías (origen: <code>categoria_pqrsf</code>).</small>
            </div>
            <div class="col-12">
                <button class="btn btn-primary" type="submit"><i class="fas fa-chart-line me-1"></i>Aplicar</button>
                <a class="btn btn-outline-secondary" href="gerencial.php?preset=mes">Limpiar</a>
                <button type="button" class="btn btn-success" onclick="exportarGerencial()"><i class="fas fa-file-excel me-1"></i>Descargar XLS</button>
                <span class="text-muted ms-2"><i class="fas fa-info-circle"></i> <?php echo $kpi_total; ?> PQRSF en el filtro actual.</span>
            </div>
        </form>
    </div>
</div>

<!-- KPIs -->
<div class="row mb-4">
    <div class="col-xl-2 col-md-4 mb-3"><div class="kpi-card"><p class="kpi-number"><?php echo $kpi_total; ?></p><p class="kpi-label">Total PQRSF</p></div></div>
    <div class="col-xl-2 col-md-4 mb-3"><div class="kpi-card"><p class="kpi-number text-warning"><?php echo $kpi_abiertas; ?></p><p class="kpi-label">Abiertas</p></div></div>
    <div class="col-xl-2 col-md-4 mb-3"><div class="kpi-card"><p class="kpi-number text-info"><?php echo $kpi_tramite; ?></p><p class="kpi-label">En trámite</p></div></div>
    <div class="col-xl-2 col-md-4 mb-3"><div class="kpi-card"><p class="kpi-number text-success"><?php echo $kpi_cerradas; ?></p><p class="kpi-label">Cerradas</p></div></div>
    <div class="col-xl-2 col-md-4 mb-3"><div class="kpi-card"><p class="kpi-number"><?php echo $avg_primera_global ?? '—'; ?></p><p class="kpi-label">Días háb. 1ª respuesta (prom)</p></div></div>
    <div class="col-xl-2 col-md-4 mb-3"><div class="kpi-card"><p class="kpi-number"><?php echo $avg_cierre_global ?? '—'; ?></p><p class="kpi-label">Días háb. cierre (prom)</p></div></div>
</div>

<!-- G1 Pareto (ancho completo) -->
<div class="row mb-4">
    <div class="col-12">
        <div class="card h-100"><div class="card-header d-flex justify-content-between align-items-center"><h5 class="mb-0"><i class="fas fa-chart-bar me-2"></i>1 · Distribución de PQRSF por proceso (Pareto 80/20)</h5><span class="badge bg-light text-dark"><?php echo date('d/m/Y', strtotime($fecha_inicio)); ?> – <?php echo date('d/m/Y', strtotime($fecha_fin)); ?></span></div>
        <div class="card-body">
            <?php if (empty($pareto_vals)): ?><div class="alert alert-info mb-0">Sin datos en este rango.</div>
            <?php else: ?><div style="position:relative;height:360px;"><canvas id="gPareto"></canvas></div>
            <small class="text-muted">Barras = casos por proceso · Línea = % acumulado. El 20% de procesos a la izquierda suele concentrar el 80%.</small><?php endif; ?></div></div>
    </div>
</div>

<!-- G2 ANS vs real -->
<div class="row mb-4">
    <div class="col-12">
        <div class="card"><div class="card-header d-flex justify-content-between align-items-center"><h5 class="mb-0"><i class="fas fa-balance-scale me-2"></i>2 · Tiempo promedio de cierre vs ANS (15 h) y meta (8 h) por proceso</h5><span class="badge bg-light text-dark"><?php echo date('d/m/Y', strtotime($fecha_inicio)); ?> – <?php echo date('d/m/Y', strtotime($fecha_fin)); ?></span></div>
        <div class="card-body">
            <?php if (empty($metricas)): ?><div class="alert alert-info mb-0">Sin datos en este rango de fechas.</div>
            <?php elseif ($n_cerr === 0): ?><div class="alert alert-warning mb-0">Hay <?php echo $kpi_total; ?> PQRSF en este filtro pero <strong>ninguna cerrada</strong>, por eso no hay tiempo de cierre que graficar. Quita el filtro de estado o amplía el rango.</div>
            <?php else: ?><div style="position:relative;height:<?php echo max(300, count($metricas)*42); ?>px;"><canvas id="gAns"></canvas></div>
            <small class="text-muted"><span class="badge bg-danger">Rojo</span> fuera de ANS (&gt;15) · <span class="badge bg-warning text-dark">Amarillo</span> sobre meta (&gt;8) · <span class="badge bg-success">Verde</span> en meta.</small><?php endif; ?></div></div>
    </div>
</div>

<!-- G3 Evolución -->
<div class="row mb-4">
    <div class="col-12">
        <div class="card"><div class="card-header d-flex justify-content-between align-items-center"><h5 class="mb-0"><i class="fas fa-chart-line me-2"></i>3 · Evolución temporal del tiempo de cierre (días hábiles prom. por mes)</h5><span class="badge bg-light text-dark"><?php echo $evol_labels ? (htmlspecialchars($evol_labels[0]).' → '.htmlspecialchars(end($evol_labels))) : 'Sin datos'; ?></span></div>
        <div class="card-body">
            <?php if (empty($evol_labels)): ?><div class="alert alert-info mb-0">Aún no hay cierres suficientes para trazar la evolución en este rango. Amplía a trimestre/semestre.</div>
            <?php else: ?><div style="position:relative;height:280px;"><canvas id="gEvol"></canvas></div>
            <small class="text-muted">Si la línea azul baja tras acciones correctivas, hay mejora real.</small><?php endif; ?></div></div>
    </div>
</div>

<!-- G4 Heatmap (ancho completo) -->
<div class="row mb-4">
    <div class="col-12">
        <div class="card"><div class="card-header d-flex justify-content-between align-items-center"><h5 class="mb-0"><i class="fas fa-th me-2"></i>4 · Matriz Proceso vs Tipo (% por proceso)</h5><span class="badge bg-light text-dark">Clic en un % para ver categorías</span></div>
        <div class="card-body"><div class="table-responsive"><table class="table table-bordered table-sm text-center align-middle">
            <thead><tr><th class="text-start">Proceso</th><?php foreach ($tipos_matrix as $t): ?><th><?php echo htmlspecialchars($t); ?></th><?php endforeach; ?></tr></thead>
            <tbody>
            <?php foreach ($matriz as $proc => $fila): ?>
                <tr><td class="text-start"><strong><?php echo htmlspecialchars($proc); ?></strong><br><small class="text-muted"><?php echo $row_tot[$proc]; ?> casos</small></td>
                <?php foreach ($tipos_matrix as $t):
                    $v = $fila[$t] ?? 0; $pct = $matriz_pct[$proc][$t] ?? 0;
                    $alpha = $pct / 100;
                    $bg = $v===0 ? '#f8f9fa' : 'rgba(102,126,234,'.round(0.10+0.85*$alpha,2).')';
                    $color = ($alpha>0.55)?'#fff':'#212529';
                ?>
                <td style="background:<?php echo $bg; ?>;color:<?php echo $color; ?>;cursor:pointer;" onclick="verCategorias(<?php echo htmlspecialchars(json_encode($proc), ENT_QUOTES); ?>, <?php echo htmlspecialchars(json_encode($t), ENT_QUOTES); ?>)" title="Ver categorías de <?php echo htmlspecialchars($proc . ' · ' . $t); ?>">
                    <strong><?php echo $pct; ?>%</strong><br><small style="opacity:.8;"><?php echo $v; ?> casos</small></td>
                <?php endforeach; ?></tr>
            <?php endforeach; ?>
            </tbody>
        </table></div><small class="text-muted">% = casos de la celda sobre el total del proceso (cada fila suma 100%).</small></div></div>
    </div>
</div>

<!-- G5 Gauge + Cumplimiento por proceso (lado a lado) -->
<div class="row mb-4">
    <div class="col-xl-4 mb-4">
        <div class="card h-100"><div class="card-header d-flex justify-content-between align-items-center"><h5 class="mb-0"><i class="fas fa-tachometer-alt me-2"></i>5 · % dentro del ANS (15 h)</h5><span class="badge bg-light text-dark"><?php echo $n_ans; ?>/<?php echo $n_cerr; ?></span></div>
        <div class="card-body text-center">
            <?php if ($n_cerr === 0): ?><div class="alert alert-info mb-0">Sin cierres en este rango.</div>
            <?php else: ?><div style="position:relative;height:230px;"><canvas id="gGauge"></canvas></div>
        <h3 class="mt-2 mb-0"><?php echo $pct_ans_global; ?>% <small class="text-muted">global</small></h3>
        <p class="text-muted mb-0 small">Meta 8 días hábiles → <strong><?php echo $n_cerr>0?round($n_meta8/$n_cerr*100,1):0; ?>%</strong> la cumple (<?php echo $n_meta8; ?>/<?php echo $n_cerr; ?>).</p><?php endif; ?></div></div>
    </div>
    <div class="col-xl-8 mb-4">
        <div class="card h-100"><div class="card-header"><h5 class="mb-0"><i class="fas fa-check-double me-2"></i>6 · Cumplimiento ANS por proceso</h5></div>
        <div class="card-body"><div class="table-responsive"><table class="table table-sm align-middle" id="tablaCumpl">
            <thead><tr><th>Proceso</th><th>Cerradas</th><th>Prom. cierre</th><th>% ANS 15h</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($metricas as $proc => $mm): ?>
                <tr><td><?php echo htmlspecialchars($proc); ?></td>
                <td><?php echo $mm['cerradas']; ?></td>
                <td><?php echo $mm['avg_cierre'] ?? '—'; ?> d</td>
                <td><strong><?php echo $mm['pct_ans'] ?? '—'; ?><?php echo $mm['pct_ans']!==null?'%':''; ?></strong></td>
                <td style="min-width:120px;"><div class="progress" style="height:16px;">
                    <div class="progress-bar bg-<?php echo ($mm['pct_ans']??0)>=90?'success':(($mm['pct_ans']??0)>=70?'warning':'danger'); ?>" style="width:<?php echo $mm['pct_ans']??0; ?>%"><?php echo $mm['pct_ans']??''; ?></div>
                </div></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table></div></div></div>
    </div>
</div>

<!-- Modal categorías de la celda -->
<div class="modal fade" id="modalCats" tabindex="-1">
    <div class="modal-dialog modal-lg"><div class="modal-content" style="border:none;border-radius:16px;overflow:hidden;">
        <div class="modal-header" style="background:linear-gradient(135deg,#667eea 0%,#764ba2 100%);color:#fff;border:none;">
            <div>
                <div class="small" style="opacity:.85;"><i class="fas fa-th me-1"></i>Matriz Proceso × Tipo · Detalle por categoría</div>
                <h5 class="modal-title mb-0" id="modalCatsTitle">Categorías</h5>
                <div id="modalCatsBadges" class="mt-1 d-flex flex-wrap gap-1"></div>
            </div>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body" id="modalCatsBody" style="background:#f6f7fb;"></div>
        <div class="modal-footer" style="border-top:1px solid #ececf1;"><small class="text-muted me-auto" id="modalCatsFoot"></small><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cerrar</button></div>
    </div></div>
</div>

<script>
const detalleCats = <?php echo json_encode($detalle_json, JSON_UNESCAPED_UNICODE); ?>;
function verCategorias(proc, tipo){
    const lista = detalleCats[proc + '||' + tipo] || [];
    const total = lista.reduce((a,b)=>a+b.n,0);
    document.getElementById('modalCatsTitle').textContent = proc + ' · ' + tipo;
    document.getElementById('modalCatsBadges').innerHTML =
        '<span class="badge" style="background:rgba(255,255,255,.22);">' + total + ' casos</span>' +
        '<span class="badge" style="background:rgba(255,255,255,.22);">' + lista.length + ' categorías</span>';
    document.getElementById('modalCatsFoot').textContent = total ? '% sobre los ' + total + ' casos de la celda · Origen: categoria_pqrsf' : '';
    let html = '';
    if (!lista.length) {
        html = '<div class="alert alert-info mb-0"><i class="fas fa-info-circle me-1"></i>Sin casos en esta celda para el filtro actual.</div>';
    } else {
        const top = lista[0];
        html = '<div class="d-flex align-items-center gap-2 p-3 mb-3" style="background:linear-gradient(135deg,#fff 0%,#eef0ff 100%);border:1px solid #e3e6f5;border-radius:12px;">'
            + '<div style="width:42px;height:42px;border-radius:50%;background:linear-gradient(135deg,#667eea,#764ba2);color:#fff;display:flex;align-items:center;justify-content:center;font-size:1.2rem;"><i class="fas fa-crown"></i></div>'
            + '<div><div class="small text-muted mb-0">Categoría principal</div><div class="fw-bold">' + escapeHtml(top.m) + '</div>'
            + '<small class="text-muted">' + top.n + ' casos · ' + top.pct + '% de la celda</small></div></div>';
        html += '<div class="list-group list-group-flush" style="border-radius:12px;overflow:hidden;">';
        const medals = ['#ffc107', '#adb5bd', '#cd7f32'];
        lista.forEach((c, i) => {
            const rank = i < 3
                ? '<span class="badge rounded-pill me-2" style="background:' + medals[i] + ';color:#212529;">' + (i+1) + '</span>'
                : '<span class="badge rounded-pill me-2 bg-light text-dark border">' + (i+1) + '</span>';
            html += '<div class="list-group-item px-3 py-2">'
                + '<div class="d-flex justify-content-between align-items-center mb-1"><div>' + rank + '<strong>' + escapeHtml(c.m) + '</strong></div>'
                + '<div><span class="badge bg-primary rounded-pill me-1">' + c.n + '</span><span class="badge bg-light text-dark border">' + c.pct + '%</span></div></div>'
                + '<div class="progress" style="height:8px;background:#e9eaf3;"><div class="progress-bar" role="progressbar" style="width:' + c.pct + '%;background:linear-gradient(90deg,#667eea,#764ba2);" aria-valuenow="' + c.pct + '" aria-valuemin="0" aria-valuemax="100"></div></div>'
                + '</div>';
        });
        html += '</div>';
    }
    document.getElementById('modalCatsBody').innerHTML = html;
    new bootstrap.Modal(document.getElementById('modalCats')).show();
}
function escapeHtml(s){ return String(s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])); }
function filtrarCatsPorTipo(){
    const t = document.getElementById('f_tipo').value;
    document.querySelectorAll('#f_categoria option[data-tipo]').forEach(o => {
        o.hidden = (t !== '' && o.dataset.tipo !== t);
    });
    const sel = document.getElementById('f_categoria');
    if (sel.selectedOptions.length && sel.selectedOptions[0].hidden) sel.value = '';
}
filtrarCatsPorTipo();
function toggleCustomDates(){
    const p = document.getElementById('preset').value;
    document.querySelectorAll('.custom-date').forEach(el => el.style.display = (p==='personalizado') ? '' : 'none');
}
toggleCustomDates();

function exportarGerencial(){
    const q = new URLSearchParams(window.location.search);
    // Si aún no aplicó filtros, tomar los valores del formulario
    if (!q.has('preset')) {
        const f = document.querySelector('form[method="GET"]');
        new FormData(f).forEach((v,k) => q.set(k,v));
    }
    window.location.href = 'exportar_gerencial.php?' + q.toString();
}

Chart.defaults.font.family = "'Segoe UI', Tahoma, sans-serif";
Chart.defaults.font.size = 11;
const short = (s, n=22) => (s && s.length > n) ? s.slice(0, n-1) + '…' : (s ?? '');

const paretoLabels = <?php echo json_encode($pareto_labels, JSON_UNESCAPED_UNICODE); ?>;
const paretoVals = <?php echo json_encode($pareto_vals); ?>;
const paretoAcum = <?php echo json_encode($pareto_acum); ?>;

if (document.getElementById('gPareto')) {
new Chart(document.getElementById('gPareto'), {
    type: 'bar',
    data: { labels: paretoLabels.map(l => short(l)), datasets: [
        { label: 'Casos', data: paretoVals, backgroundColor: '#667eea', hoverBackgroundColor: '#5468d4', borderRadius: 6, barThickness: 'flex', maxBarThickness: 34, yAxisID: 'y' },
        { label: '% acumulado', data: paretoAcum, type: 'line', borderColor: '#dc3545', backgroundColor: '#dc3545', pointRadius: 3, tension: 0.3, yAxisID: 'y1' }
    ]},
    options: { responsive: true, maintainAspectRatio: false, indexAxis: 'y',
        interaction: { mode: 'index', intersect: false },
        plugins: { legend: { position: 'top' }, tooltip: { callbacks: { title: items => paretoLabels[items[0].dataIndex] ?? '' } } },
        scales: {
            y: { grid: { display: false }, ticks: { autoSkip: false } },
            x: { beginAtZero: true, precision: 0, grid: { color: '#eef0f4' }, title: { display: true, text: 'Casos' } },
            y1: { display: false, min: 0, max: 100 }
        } }
});
}

// Gauge global con rosca + texto central
const pctAns = <?php echo (float)$pct_ans_global; ?>;
if (document.getElementById('gGauge')) {
const centerText = { id: 'centerText', afterDraw(c){ const {ctx, chartArea} = c; if(!chartArea) return; ctx.save(); ctx.textAlign='center'; ctx.textBaseline='middle'; ctx.font='bold 26px Segoe UI'; ctx.fillStyle='#212529'; ctx.fillText(pctAns + '%', (chartArea.left+chartArea.right)/2, (chartArea.top+chartArea.bottom)/2 - 6); ctx.font='11px Segoe UI'; ctx.fillStyle='#6c757d'; ctx.fillText('cumplimiento', (chartArea.left+chartArea.right)/2, (chartArea.top+chartArea.bottom)/2 + 16); ctx.restore(); } };
new Chart(document.getElementById('gGauge'), {
    type: 'doughnut',
    data: { labels: ['Dentro ANS', 'Fuera ANS'], datasets: [{ data: [pctAns, Math.max(0, 100 - pctAns)], backgroundColor: [pctAns>=90?'#28a745':(pctAns>=70?'#ffc107':'#dc3545'), '#e9ecef'], borderWidth: 0 }]},
    options: { responsive: true, maintainAspectRatio: false, cutout: '74%', plugins: { legend: { position: 'bottom' }, tooltip: { callbacks: { label: c => ' ' + c.label + ': ' + c.parsed + '%' } } } },
    plugins: [centerText]
});
}

// G2: real vs ANS vs meta (horizontal para nombres largos)
const procs = <?php echo json_encode(array_keys($metricas), JSON_UNESCAPED_UNICODE); ?>;
const avgCierre = <?php echo json_encode(array_map(fn($v) => $v['avg_cierre'] ?? 0, array_values($metricas))); ?>;
if (document.getElementById('gAns')) {
new Chart(document.getElementById('gAns'), {
    type: 'bar',
    data: { labels: procs.map(l => short(l, 26)), datasets: [
        { label: 'Prom. cierre real (días hábiles)', data: avgCierre, backgroundColor: avgCierre.map(v => v > 15 ? '#dc3545' : (v > 8 ? '#ffc107' : '#28a745')), borderRadius: 6, maxBarThickness: 26 },
        { label: 'ANS normativo (15)', data: procs.map(() => 15), type: 'line', borderColor: '#dc3545', borderDash: [6,4], pointRadius: 0, borderWidth: 2 },
        { label: 'Meta interna (8)', data: procs.map(() => 8), type: 'line', borderColor: '#17a2b8', borderDash: [6,4], pointRadius: 0, borderWidth: 2 }
    ]},
    options: { responsive: true, maintainAspectRatio: false, indexAxis: 'y',
        interaction: { mode: 'index', intersect: false },
        plugins: { legend: { position: 'top' }, tooltip: { callbacks: { title: items => procs[items[0].dataIndex] ?? '' } } },
        scales: { x: { beginAtZero: true, grid: { color: '#eef0f4' }, title: { display: true, text: 'Días hábiles' } }, y: { grid: { display: false }, ticks: { autoSkip: false } } } }
});
}

// G3 evolución
if (document.getElementById('gEvol')) {
new Chart(document.getElementById('gEvol'), {
    type: 'line',
    data: { labels: <?php echo json_encode($evol_labels); ?>, datasets: [
        { label: 'Prom. cierre (días hábiles)', data: <?php echo json_encode($evol_vals); ?>, borderColor: '#667eea', backgroundColor: 'rgba(102,126,234,.14)', fill: true, tension: 0.35, pointRadius: 4, borderWidth: 2.5 },
        { label: 'ANS (15)', data: <?php echo json_encode(array_fill(0, count($evol_labels), 15)); ?>, borderColor: '#dc3545', borderDash: [6,4], pointRadius: 0, borderWidth: 1.5 },
        { label: 'Meta (8)', data: <?php echo json_encode(array_fill(0, count($evol_labels), 8)); ?>, borderColor: '#17a2b8', borderDash: [6,4], pointRadius: 0, borderWidth: 1.5 }
    ]},
    options: { responsive: true, maintainAspectRatio: false, interaction: { mode: 'index', intersect: false },
        plugins: { legend: { position: 'top' } },
        scales: { y: { beginAtZero: true, grid: { color: '#eef0f4' }, title: { display: true, text: 'Días hábiles' } }, x: { grid: { display: false } } } }
});
}

$(document).ready(function(){
    $('#tablaCumpl').DataTable({ language: { url: '//cdn.datatables.net/plug-ins/1.13.6/i18n/es-ES.json' }, pageLength: 15, order: [[3,'asc']] });
});
</script>

<?php require_once '../includes/footer.php'; ?>
