<?php
/**
 * Sophía - Exportar Informe Gerencial a XLS (solo lectura, sin modificar BD)
 * Respeta los mismos filtros de gerencial.php:
 * preset, fecha_inicio, fecha_fin, proceso, estado_gerencial, tipo, categoria
 */
require_once '../config/database.php';
require_once '../config/pqrs_helper.php';
require_once '../config/gerencial_helper.php';

// ---- Filtros (igual que gerencial.php) ----
$preset = $_GET['preset'] ?? 'mes';
if (!in_array($preset, ['dia','semana','mes','trimestre','semestre','ano','personalizado'], true)) $preset = 'mes';
$f_proceso = trim($_GET['proceso'] ?? '');
$f_estado_g = trim($_GET['estado_gerencial'] ?? '');
$f_tipo = trim($_GET['tipo'] ?? '');
$f_categoria = trim($_GET['categoria'] ?? '');
[$fecha_inicio, $fecha_fin] = gerencialResolverRango($preset, trim($_GET['fecha_inicio'] ?? ''), trim($_GET['fecha_fin'] ?? ''));

$mysqli = conectarDB();
if (!$mysqli) die('Error de conexión a la base de datos');

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
    cat.nombre_categoria AS Motivo
FROM `tabPQRFS` AS tp
LEFT JOIN `tabCategoria PQRSF` AS cat ON cat.name = tp.categoria_pqrsf
WHERE DATE(tp.creation) BETWEEN ? AND ?
ORDER BY tp.creation DESC
LIMIT 5000";
$stmt = $mysqli->prepare($sql);
$stmt->bind_param('ss', $fecha_inicio, $fecha_fin);
$stmt->execute();
$res = $stmt->get_result();
$pqrs_data = [];
while ($row = $res->fetch_assoc()) $pqrs_data[] = $row;
$stmt->close();

$pqrs_data = procesarPQRSConDiasHabiles($pqrs_data);
$hitos = gerencialObtenerHitosVersion($mysqli, array_column($pqrs_data, 'name'));
$pqrs_data = gerencialEnriquecerPQRS($pqrs_data, $hitos);
$mysqli->close();

$pqrs_f = array_values(array_filter($pqrs_data, function($p) use ($f_proceso, $f_estado_g, $f_tipo, $f_categoria) {
    if ($f_proceso !== '' && $p['Proceso'] !== $f_proceso) return false;
    if ($f_estado_g !== '' && $p['Estado_Gerencial'] !== $f_estado_g) return false;
    if ($f_tipo !== '' && ($p['tipo_peticion'] ?? '') !== $f_tipo) return false;
    if ($f_categoria !== '' && ($p['categoria_pqrsf'] ?? '') !== $f_categoria) return false;
    return true;
}));

$metricas = gerencialMetricasPorProceso($pqrs_f);
$cerradas = array_filter($pqrs_f, fn($p) => $p['Dias_Habiles_Cierre'] !== null);
$n_cerr = count($cerradas);
$n_ans = count(array_filter($cerradas, fn($p) => !empty($p['Cumple_ANS15'])));
$pct = $n_cerr > 0 ? round($n_ans / $n_cerr * 100, 1) : 0;

$nombre_archivo = 'Informe_Gerencial_PQRSF_' . $fecha_inicio . '_a_' . $fecha_fin . '.xls';
header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $nombre_archivo . '"');
header('Pragma: no-cache');
header('Expires: 0');
echo "\xEF\xBB\xBF";
?>
<html><head><meta charset="UTF-8">
<style>
.titulo{font-size:18px;font-weight:bold;text-align:center;background:#1f4e78;color:#fff;padding:10px;}
.sub{font-size:12px;text-align:center;padding:6px;}
.sec{font-weight:bold;background:#d9eaf7;}
.enc{background:#1f4e78;color:#fff;font-weight:bold;text-align:center;}
table{border-collapse:collapse;width:100%;}th,td{border:1px solid #999;padding:5px;font-size:11px;}
</style></head><body>
<table><tr><td colspan="12" class="titulo">INFORME GERENCIAL PQRSF — SOPHIA</td></tr>
<tr><td colspan="12" class="sub">Rango del reporte: <?php echo date('d/m/Y', strtotime($fecha_inicio)); ?> al <?php echo date('d/m/Y', strtotime($fecha_fin)); ?> (vista: <?php echo htmlspecialchars($preset); ?>) · ANS 15 días hábiles · Meta 8 días hábiles</td></tr></table><br>
<table><tr><th colspan="8" class="sec">FILTROS APLICADOS</th></tr>
<tr><td><strong>Proceso</strong></td><td><?php echo htmlspecialchars($f_proceso ?: 'Todos'); ?></td>
<td><strong>Estado</strong></td><td><?php echo htmlspecialchars($f_estado_g ?: 'Todos'); ?></td>
<td><strong>Tipo</strong></td><td><?php echo htmlspecialchars($f_tipo ?: 'Todos'); ?></td>
<td><strong>Categoría</strong></td><td><?php echo htmlspecialchars($f_categoria ?: 'Todas'); ?></td></tr></table><br>
<table><tr><th colspan="5" class="sec">INDICADORES GLOBALES</th></tr>
<tr class="enc"><th>Total PQRSF</th><th>Cerradas</th><th>Dentro ANS 15h</th><th>% Cumplimiento</th><th>Fecha generación</th></tr>
<tr><td style="text-align:center"><?php echo count($pqrs_f); ?></td><td style="text-align:center"><?php echo $n_cerr; ?></td>
<td style="text-align:center"><?php echo $n_ans; ?></td><td style="text-align:center"><?php echo $pct; ?>%</td>
<td style="text-align:center"><?php echo date('d/m/Y H:i'); ?></td></tr></table><br>
<table><tr><th colspan="7" class="sec">MÉTRICAS POR PROCESO</th></tr>
<tr class="enc"><th>Proceso</th><th>Total</th><th>Cerradas</th><th>Prom. 1ª respuesta (días háb.)</th><th>Prom. cierre (días háb.)</th><th>Dentro ANS</th><th>% ANS</th></tr>
<?php foreach ($metricas as $proc => $m): ?>
<tr><td><?php echo htmlspecialchars($proc); ?></td><td style="text-align:center"><?php echo $m['total']; ?></td>
<td style="text-align:center"><?php echo $m['cerradas']; ?></td><td style="text-align:center"><?php echo $m['avg_primera'] ?? '—'; ?></td>
<td style="text-align:center"><?php echo $m['avg_cierre'] ?? '—'; ?></td><td style="text-align:center"><?php echo $m['cumple_ans']; ?></td>
<td style="text-align:center"><?php echo $m['pct_ans'] !== null ? $m['pct_ans'] . '%' : '—'; ?></td></tr>
<?php endforeach; ?></table><br>
<table><tr><th colspan="11" class="sec">DETALLE PQRSF</th></tr>
<tr class="enc"><th>ID</th><th>Fecha creación</th><th>Proceso</th><th>Tipo</th><th>Motivo</th><th>Estado origen</th><th>Estado gerencial</th><th>1ª respuesta</th><th>Días háb. 1ª</th><th>Cierre</th><th>Días háb. cierre</th></tr>
<?php foreach ($pqrs_f as $p): ?>
<tr><td><?php echo htmlspecialchars($p['name']); ?></td><td><?php echo htmlspecialchars($p['Fecha_Creacion']); ?></td>
<td><?php echo htmlspecialchars($p['Proceso']); ?></td><td><?php echo htmlspecialchars($p['tipo_peticion'] ?? ''); ?></td>
<td><?php echo htmlspecialchars($p['Motivo'] ?? $p['categoria_pqrsf'] ?? ''); ?></td><td><?php echo htmlspecialchars($p['estado'] ?? ''); ?></td>
<td><?php echo htmlspecialchars($p['Estado_Gerencial']); ?></td><td><?php echo htmlspecialchars($p['Fecha_Primera_Respuesta'] ?? '—'); ?></td>
<td style="text-align:center"><?php echo $p['Dias_Habiles_Primera'] ?? '—'; ?></td><td><?php echo htmlspecialchars($p['Fecha_Cierre'] ?? '—'); ?></td>
<td style="text-align:center"><?php echo $p['Dias_Habiles_Cierre'] ?? '—'; ?></td></tr>
<?php endforeach; ?></table>
</body></html>
<?php exit; ?>
