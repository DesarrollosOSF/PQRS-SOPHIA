<?php
$page_title = 'Sophía - Reportes y Análisis';
require_once '../includes/header.php';
require_once '../config/database.php';
require_once '../config/pqrs_helper.php';

// ==========================================================
// 1) LEER Y VALIDAR LOS FILTROS QUE VIENEN DE LA URL (GET)
// ==========================================================

function validarFechaYmd($fecha) {
    $d = DateTime::createFromFormat('Y-m-d', $fecha);
    return $d && $d->format('Y-m-d') === $fecha;
}

// Valores por defecto: últimos 2 meses
$fecha_inicio_default = date('Y-m-d', strtotime('-2 months'));
$fecha_fin_default    = date('Y-m-d');

// Si el usuario aplicó un filtro (viene por GET), se usa ese; si no, el default
$fecha_inicio = isset($_GET['fecha_inicio']) ? trim($_GET['fecha_inicio']) : $fecha_inicio_default;
$fecha_fin    = isset($_GET['fecha_fin'])    ? trim($_GET['fecha_fin'])    : $fecha_fin_default;
$tipo_reporte = isset($_GET['tipo_reporte']) ? trim($_GET['tipo_reporte']) : 'general';

// Validar formato de fechas; si algo viene mal, se vuelve al default (evita errores/SQL raro)
if (!validarFechaYmd($fecha_inicio)) {
    $fecha_inicio = $fecha_inicio_default;
}
if (!validarFechaYmd($fecha_fin)) {
    $fecha_fin = $fecha_fin_default;
}

// Si el usuario invirtió las fechas, las intercambiamos en vez de fallar
if ($fecha_inicio > $fecha_fin) {
    [$fecha_inicio, $fecha_fin] = [$fecha_fin, $fecha_inicio];
}

// Solo permitir tipos de reporte conocidos
$tipos_reporte_validos = ['general', 'tendencia', 'areas', 'usuarios'];
if (!in_array($tipo_reporte, $tipos_reporte_validos, true)) {
    $tipo_reporte = 'general';
}

// ==========================================================
// 2) CONSULTA CON LAS FECHAS YA FILTRADAS
// ==========================================================
$sql_pqrs = "
SELECT
    tp.name,
    CASE 
        WHEN tp.docstatus = 0 THEN 'Borrador'
        WHEN tp.docstatus = 1 THEN 'Validado'
        WHEN tp.docstatus = 2 THEN 'Cancelado'
        ELSE 'Desconocido'
    END AS Estado_Documento,
    DATE(tp.creation) AS Fecha_Creacion,
    DATE_ADD(DATE(tp.creation), INTERVAL 15 DAY) AS Fecha_Maxima_Respuesta,
    DATEDIFF(DATE_ADD(DATE(tp.creation), INTERVAL 15 DAY), CURDATE()) AS Dias_Restantes,
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
ORDER BY 
    CASE 
        WHEN tp.docstatus = 0 THEN 1 
        WHEN tp.docstatus = 1 THEN 2 
        WHEN tp.docstatus = 2 THEN 3 
        ELSE 4 
    END,
    CASE 
        WHEN tp.docstatus = 0 THEN DATEDIFF(DATE_ADD(DATE(tp.creation), INTERVAL 15 DAY), CURDATE())
        ELSE tp.creation 
    END ASC,
    tp.creation DESC
";

// Ejecutar consulta con los parámetros de fecha YA VALIDADOS Y FILTRADOS
$mysqli = conectarDB();
$stmt = $mysqli->prepare($sql_pqrs);
$stmt->bind_param('ss', $fecha_inicio, $fecha_fin);
$stmt->execute();
$result = $stmt->get_result();

$pqrs_data = [];
while ($row = $result->fetch_assoc()) {
    $pqrs_data[] = $row;
}

$stmt->close();
$mysqli->close();

// Procesar PQRS con cálculo de días hábiles
$pqrs_data = procesarPQRSConDiasHabiles($pqrs_data);

// Calcular, para cada PQRS, si REALMENTE estuvo/está vencida:
// - Borrador (pendiente): vencida si la fecha límite ya pasó respecto a HOY.
// - Validado (ya resuelto): vencida si se resolvió DESPUÉS de la fecha límite
//   (se usa la fecha de última modificación como proxy de la fecha de resolución).
// - Cancelado u otro: no cuenta como vencida.
foreach ($pqrs_data as &$pqr) {
    $fecha_maxima_ts = strtotime($pqr['Fecha_Maxima_Respuesta']);

    if ($pqr['Estado_Documento'] === 'Borrador') {
        $pqr['Vencida'] = $fecha_maxima_ts < strtotime(date('Y-m-d'));
    } elseif ($pqr['Estado_Documento'] === 'Validado') {
        $fecha_resolucion_ts = strtotime($pqr['Fecha_Ultima_Modificacion']);
        $pqr['Vencida'] = $fecha_resolucion_ts > $fecha_maxima_ts;
    } else {
        $pqr['Vencida'] = false;
    }
}
unset($pqr);

// Procesar datos para reportes enfocados en prevención de vencimientos
$estados_por_tipo = [];
$dias_por_area = [];
$tendencia_temporal = [];
$canales_distribucion = [];
$carga_usuarios = [];
$pqrs_riesgo = []; // PQRS que están en riesgo de vencer

foreach ($pqrs_data as $pqr) {
    // Estados por tipo de petición
    $tipo = $pqr['tipo_peticion'] ?? 'No especificado';
    $estado = $pqr['Estado_Documento'];
    
    if (!isset($estados_por_tipo[$tipo])) {
        $estados_por_tipo[$tipo] = ['Borrador' => 0, 'Validado' => 0, 'Cancelado' => 0];
    }
    $estados_por_tipo[$tipo][$estado]++;
    
    // Días hábiles restantes por área (solo para PQRS Borrador)
    if ($pqr['Estado_Documento'] == 'Borrador') {
        $area = $pqr['custom_área_responsable'] ?? 'No asignada';
        if (!isset($dias_por_area[$area])) {
            $dias_por_area[$area] = [];
        }
        $dias_por_area[$area][] = $pqr['Dias_Habiles_Restantes'];
        
        // Identificar PQRS en riesgo (≤3 y 8 días hábiles)
        if ($pqr['Dias_Habiles_Restantes'] <= 3 || $pqr['Dias_Habiles_Restantes'] <= 8) {
            $pqrs_riesgo[] = $pqr;
        }
    }
    
    // Tendencia temporal (dentro del rango filtrado)
    $fecha = date('Y-m-d', strtotime($pqr['Fecha_Creacion']));
    if ($fecha >= $fecha_inicio && $fecha <= $fecha_fin) {
        if (!isset($tendencia_temporal[$fecha])) {
            $tendencia_temporal[$fecha] = 0;
        }
        $tendencia_temporal[$fecha]++;
    }
    
    // Canales de distribución
    $canal = $pqr['custom_canal_de_entrada'] ?? 'No especificado';
    $canales_distribucion[$canal] = ($canales_distribucion[$canal] ?? 0) + 1;
    
    // Carga por usuario
    $usuario = $pqr['Creado_Por'] ?? 'No especificado';
    $carga_usuarios[$usuario] = ($carga_usuarios[$usuario] ?? 0) + 1;
}

// Calcular promedios de días por área
$dias_promedio_area = [];
foreach ($dias_por_area as $area => $dias) {
    $dias_promedio_area[$area] = round(array_sum($dias) / count($dias), 1);
}

// Ordenar tendencia temporal
ksort($tendencia_temporal);

// Helper para saber si una sección se debe mostrar según el tipo de reporte elegido
function mostrarSeccion($seccion, $tipo_reporte) {
    if ($tipo_reporte === 'general') {
        return true;
    }
    return $tipo_reporte === $seccion;
}
?>

<div class="row mb-4">
    <div class="col-12">
        <h2 class="mb-0">
            <i class="fas fa-chart-bar text-primary me-2"></i>
            Reportes y Análisis
        </h2>
        <p class="text-muted">
            Análisis detallado y visualizaciones de datos del sistema PQRS
            (<?php echo date('d/m/Y', strtotime($fecha_inicio)); ?> - <?php echo date('d/m/Y', strtotime($fecha_fin)); ?>)
        </p>
    </div>
</div>

<!-- Filtros de Reportes -->
<div class="row mb-4">
    <div class="col-12">
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0">
                    <i class="fas fa-calendar-alt me-2"></i>
                    Parámetros del Reporte
                </h5>
            </div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-3">
                        <label for="fecha_inicio" class="form-label">Fecha Inicio</label>
                        <input type="date" class="form-control" id="fecha_inicio"
                               value="<?php echo htmlspecialchars($fecha_inicio); ?>">
                    </div>
                    <div class="col-md-3">
                        <label for="fecha_fin" class="form-label">Fecha Fin</label>
                        <input type="date" class="form-control" id="fecha_fin"
                               value="<?php echo htmlspecialchars($fecha_fin); ?>">
                    </div>
                    <div class="col-md-3">
                        <label for="tipo_reporte" class="form-label">Tipo de Reporte</label>
                        <select class="form-select" id="tipo_reporte">
                            <option value="general" <?php echo $tipo_reporte === 'general' ? 'selected' : ''; ?>>Reporte General</option>
                            <option value="tendencia" <?php echo $tipo_reporte === 'tendencia' ? 'selected' : ''; ?>>Tendencia Temporal</option>
                            <option value="areas" <?php echo $tipo_reporte === 'areas' ? 'selected' : ''; ?>>Análisis por Áreas</option>
                            <option value="usuarios" <?php echo $tipo_reporte === 'usuarios' ? 'selected' : ''; ?>>Carga de Usuarios</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">&nbsp;</label>
                        <div class="d-grid">
                            <button type="button" class="btn btn-primary" onclick="generarReporte()">
                                <i class="fas fa-chart-line me-2"></i>Generar Reporte
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Alertas de PQRS en Riesgo -->
<?php if (!empty($pqrs_riesgo)): ?>
<div class="row mb-4">
    <div class="col-12">
        <div class="card border-warning">
            <div class="card-header bg-warning text-dark">
                <h5 class="mb-0">
                    <i class="fas fa-exclamation-triangle me-2"></i>
                    PQRS en Riesgo de Vencimiento (≤8 días hábiles)
                </h5>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-sm" id="tablaPqrsRiesgo">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Peticionario</th>
                                <th>Área Responsable</th>
                                <th>Días Hábiles Restantes</th>
                                <th>Prioridad</th>
                                <th>Fecha Creación</th>
                                <th>Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach (array_slice($pqrs_riesgo, 0, 100) as $pqr): ?>
                            <tr class="<?php echo $pqr['Dias_Habiles_Restantes'] < 0 ? 'table-danger' : 'table-warning'; ?>">
                                <td><strong><?php echo htmlspecialchars($pqr['name']); ?></strong></td>
                                <td><?php echo htmlspecialchars($pqr['Nombre_Peticionario']); ?></td>
                                <td><?php echo htmlspecialchars($pqr['custom_área_responsable'] ?? 'No asignada'); ?></td>
                                <td>
                                    <?php echo generarBadgeDiasHabiles($pqr['Dias_Habiles_Restantes'], $pqr['Estado_Documento']); ?>
                                </td>
                                <td>
                                    <span class="badge bg-<?php 
                                        echo $pqr['custom_prioridad'] == 'Alta' ? 'danger' : 
                                            ($pqr['custom_prioridad'] == 'Media' ? 'warning' : 'info'); 
                                    ?>">
                                        <?php echo htmlspecialchars($pqr['custom_prioridad'] ?? 'Media'); ?>
                                    </span>
                                </td>
                                <td><?php echo date('d/m/Y', strtotime($pqr['Fecha_Creacion'])); ?></td>
                                <td>
                                    <button class="btn btn-sm btn-outline-info" onclick="verDetalle('<?php echo $pqr['name']; ?>')" title="Ver Detalle">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Gráfica de Estados por Tipo -->
<?php if (mostrarSeccion('general', $tipo_reporte)): ?>
<div class="row mb-4">
    <div class="col-12">
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0">
                    <i class="fas fa-chart-pie me-2"></i>
                    PQRS por Estado y Tipo de Petición
                </h5>
            </div>
            <div class="card-body">
                <canvas id="estadosTipoChart" height="400"></canvas>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Heatmap de Días Restantes vs Área -->
<?php if (mostrarSeccion('areas', $tipo_reporte)): ?>
<div class="row mb-4">
    <div class="col-12">
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0">
                    <i class="fas fa-th me-2"></i>
                    Heatmap: Días Restantes vs Área Responsable
                </h5>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-bordered" id="heatmapTable">
                        <thead>
                            <tr>
                                <th>Área Responsable</th>
                                <th>Promedio Días Restantes</th>
                                <th>Total PQRS</th>
                                <th>Estado de Cumplimiento</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($dias_promedio_area as $area => $promedio): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($area); ?></td>
                                <td>
                                    <span class="badge bg-<?php 
                                        echo $promedio < 0 ? 'danger' : 
                                            ($promedio < 5 ? 'warning' : 'success'); 
                                    ?>">
                                        <?php echo $promedio; ?> días
                                    </span>
                                </td>
                                <td><?php echo count($dias_por_area[$area]); ?></td>
                                <td>
                                    <div class="progress" style="height: 20px;">
                                        <div class="progress-bar bg-<?php 
                                            echo $promedio < 0 ? 'danger' : 
                                                ($promedio < 5 ? 'warning' : 'success'); 
                                        ?>" style="width: <?php echo min(100, max(0, ($promedio + 15) * 3.33)); ?>%">
                                            <?php echo $promedio < 0 ? 'Vencido' : ($promedio < 5 ? 'Riesgo' : 'Normal'); ?>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Tendencia Temporal -->
<?php if (mostrarSeccion('tendencia', $tipo_reporte)): ?>
<div class="row mb-4">
    <div class="col-12">
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0">
                    <i class="fas fa-chart-line me-2"></i>
                    Tendencia de PQRS Creadas
                    (<?php echo date('d/m/Y', strtotime($fecha_inicio)); ?> - <?php echo date('d/m/Y', strtotime($fecha_fin)); ?>)
                </h5>
            </div>
            <div class="card-body">
                <canvas id="tendenciaChart" height="300"></canvas>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Distribución por Canales y Carga de Usuarios -->
<div class="row mb-4">
    <?php if (mostrarSeccion('general', $tipo_reporte)): ?>
    <div class="col-xl-6 mb-4">
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0">
                    <i class="fas fa-chart-pie me-2"></i>
                    Distribución por Canal de Entrada
                </h5>
            </div>
            <div class="card-body">
                <canvas id="canalesChart" height="300"></canvas>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php if (mostrarSeccion('usuarios', $tipo_reporte)): ?>
    <div class="col-xl-6 mb-4">
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0">
                    <i class="fas fa-users me-2"></i>
                    Carga de Trabajo por Usuario
                </h5>
            </div>
            <div class="card-body">
                <canvas id="usuariosChart" height="300"></canvas>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<!-- Tabla de Resumen Ejecutivo -->
<div class="row">
    <div class="col-12">
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0">
                    <i class="fas fa-table me-2"></i>
                    Resumen Ejecutivo
                </h5>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-6">
                        <h6>Indicadores de Rendimiento</h6>
                        <ul class="list-group list-group-flush">
                            <li class="list-group-item d-flex justify-content-between">
                                <span>Total de PQRS:</span>
                                <strong><?php echo count($pqrs_data); ?></strong>
                            </li>
                            <li class="list-group-item d-flex justify-content-between">
                                <span>PQRS Vencidas:</span>
                                <strong class="text-danger">
                                    <?php 
                                    $vencidas = array_filter($pqrs_data, function($pqr) {
                                        return $pqr['Vencida'];
                                    });
                                    echo count($vencidas);
                                    ?>
                                </strong>
                            </li>
                            <li class="list-group-item d-flex justify-content-between">
                                <span>Tasa de Cumplimiento:</span>
                                <strong class="text-success">
                                    <?php 
                                    $cumplimiento = count($pqrs_data) > 0 ? 
                                        round(((count($pqrs_data) - count($vencidas)) / count($pqrs_data)) * 100, 1) : 0;
                                    echo $cumplimiento . '%';
                                    ?>
                                </strong>
                            </li>
                        </ul>
                    </div>
                    <div class="col-md-6">
                        <h6>Recomendaciones</h6>
                        <div class="alert alert-info">
                            <i class="fas fa-lightbulb me-2"></i>
                            <strong>Áreas que requieren atención:</strong>
                            <ul class="mb-0 mt-2">
                                <?php 
                                $areas_problema = array_filter($dias_promedio_area, function($promedio) {
                                    return $promedio < 5;
                                });
                                foreach (array_slice($areas_problema, 0, 3) as $area => $promedio):
                                ?>
                                <li><?php echo htmlspecialchars($area); ?> (<?php echo $promedio; ?> días promedio)</li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Modal de Detalle -->
<div class="modal fade" id="detalleModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Detalle de PQRS</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="detalleContent">
                <!-- Contenido dinámico -->
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cerrar</button>
            </div>
        </div>
    </div>
</div>

<script>
    // Construye la URL con los filtros y recarga la página con esos parámetros
    function generarReporte() {
        const fechaInicio = document.getElementById('fecha_inicio').value;
        const fechaFin = document.getElementById('fecha_fin').value;
        const tipoReporte = document.getElementById('tipo_reporte').value;

        if (!fechaInicio || !fechaFin) {
            alert('Por favor selecciona ambas fechas.');
            return;
        }
        if (fechaInicio > fechaFin) {
            alert('La fecha de inicio no puede ser posterior a la fecha fin.');
            return;
        }

        // Se preserva cualquier otro parámetro que ya traiga la URL actual
        // (por ejemplo ?page=reportes si esta vista se carga a través de un router)
        // y solo se sobreescriben fecha_inicio, fecha_fin y tipo_reporte.
        const params = new URLSearchParams(window.location.search);
        params.set('fecha_inicio', fechaInicio);
        params.set('fecha_fin', fechaFin);
        params.set('tipo_reporte', tipoReporte);

        window.location.href = window.location.pathname + '?' + params.toString();
    }

    //Funcion para ver el detalle en la tabla de riesgos de vencimiento
    function verDetalle(id) {
    // Buscar los datos de la PQRS en la tabla
    const table = document.getElementById('tablaPqrsRiesgo');
    const rows = table.getElementsByTagName('tbody')[0].getElementsByTagName('tr');

    let pqrsData = null;
    for (let i = 0; i < rows.length; i++) {
        const firstCell = rows[i].getElementsByTagName('td')[0];
        if (firstCell && firstCell.textContent.trim() === id) {
            const cells = rows[i].getElementsByTagName('td');

            const peticionario = cells[1].textContent.trim();
            const areaResponsable = cells[2].textContent.trim();
            const prioridad = cells[4].textContent.trim();

            const diasCelda = cells[3];
            const badgeDias = diasCelda.querySelector('.badge');
            const diasRestantes = badgeDias ? badgeDias.textContent.trim() : diasCelda.textContent.trim();
            
            const fechaCreacion = cells[5].textContent.trim();

            pqrsData = {
                id: id,
                peticionario: peticionario,
                areaResponsable: areaResponsable,
                prioridad: prioridad,
                diasRestantes: diasRestantes,
                fechaCreacion: fechaCreacion
            };
            break;
        }
    }

    if (!pqrsData) {
        alert('No se encontraron datos para la PQRS: ' + id);
        return;
    }

    // Obtener la descripción real desde los datos PHP
    const descripcion = '<?php echo addslashes(json_encode(array_column($pqrs_data, "descripcion_queja", "name"))); ?>';
    const descripciones = JSON.parse(descripcion);
    const descripcionReal = descripciones[id] || 'No hay descripción disponible';

    const detalleContent = `
        <div class="row">
            <div class="col-md-6">
                <h6>Información General</h6>
                <p><strong>ID:</strong> ${pqrsData.id}</p>
                <p><strong>Peticionario:</strong> ${pqrsData.peticionario}</p>
                <p><strong>Area Responsable:</strong> ${pqrsData.areaResponsable}</p>
                <p><strong>Prioridad:</strong> ${pqrsData.prioridad}</p>
            </div>
            <div class="col-md-6">
                <h6>Fechas</h6>
                <p><strong>Fecha Creación:</strong> ${pqrsData.fechaCreacion}</p>
                <p><strong>Días Hábiles Restantes:</strong> ${pqrsData.diasRestantes}</p>
            </div>
        </div>
        <hr>
        <div class="row">
            <div class="col-12">
                <h6>Descripción de la PQRS</h6>
                <div class="alert alert-light">
                    <p class="mb-0">${descripcionReal}</p>
                </div>
            </div>
        </div>
    `;

    document.getElementById('detalleContent').innerHTML = detalleContent;
    new bootstrap.Modal(document.getElementById('detalleModal')).show();
}
// Datos para las gráficas
const estadosPorTipo = <?php echo json_encode($estados_por_tipo); ?>;
const canalesDistribucion = <?php echo json_encode($canales_distribucion); ?>;
const cargaUsuarios = <?php echo json_encode($carga_usuarios); ?>;
const tendenciaTemporal = <?php echo json_encode($tendencia_temporal); ?>;

<?php if (mostrarSeccion('general', $tipo_reporte)): ?>
// Gráfica de Estados por Tipo (Barras Apiladas)
const estadosTipoCtx = document.getElementById('estadosTipoChart').getContext('2d');
const tipos = Object.keys(estadosPorTipo);

new Chart(estadosTipoCtx, {
    type: 'bar',
    data: {
        labels: tipos,
        datasets: [
            {
                label: 'Borrador',
                data: tipos.map(tipo => estadosPorTipo[tipo]['Borrador'] || 0),
                backgroundColor: '#ffc107',
                borderWidth: 1
            },
            {
                label: 'Validado',
                data: tipos.map(tipo => estadosPorTipo[tipo]['Validado'] || 0),
                backgroundColor: '#28a745',
                borderWidth: 1
            },
            {
                label: 'Cancelado',
                data: tipos.map(tipo => estadosPorTipo[tipo]['Cancelado'] || 0),
                backgroundColor: '#dc3545',
                borderWidth: 1
            }
        ]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        scales: {
            x: { stacked: true },
            y: { stacked: true, beginAtZero: true }
        },
        plugins: {
            legend: { position: 'top' }
        }
    }
});
<?php endif; ?>

<?php if (mostrarSeccion('tendencia', $tipo_reporte)): ?>
// Gráfica de Tendencia Temporal
const tendenciaCtx = document.getElementById('tendenciaChart').getContext('2d');
const fechas = Object.keys(tendenciaTemporal).sort();
const valores = fechas.map(fecha => tendenciaTemporal[fecha]);

new Chart(tendenciaCtx, {
    type: 'line',
    data: {
        labels: fechas,
        datasets: [{
            label: 'PQRS Creadas',
            data: valores,
            borderColor: '#667eea',
            backgroundColor: 'rgba(102, 126, 234, 0.1)',
            fill: true,
            tension: 0.4
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        scales: {
            y: { beginAtZero: true }
        }
    }
});
<?php endif; ?>

<?php if (mostrarSeccion('general', $tipo_reporte)): ?>
// Gráfica de Canales (dona con % en cada porción, tooltip y leyenda)
const canalesCtx = document.getElementById('canalesChart').getContext('2d');
const canalesTotal = Object.values(canalesDistribucion).reduce((a, b) => a + b, 0);
const canalesPct = Object.values(canalesDistribucion).map(v => canalesTotal > 0 ? Math.round(v / canalesTotal * 1000) / 10 : 0);
// Plugin inline: pinta el % dentro de cada porción (solo si ocupa suficiente arco)
const slicePctPlugin = {
    id: 'slicePct',
    afterDatasetsDraw(chart) {
        const meta = chart.getDatasetMeta(0);
        if (!meta || !meta.data) return;
        const ctx = chart.ctx;
        ctx.save();
        ctx.textAlign = 'center';
        ctx.textBaseline = 'middle';
        ctx.font = 'bold 12px Segoe UI, sans-serif';
        meta.data.forEach((arc, i) => {
            const pct = canalesPct[i];
            if (!pct || pct < 4) return; // porciones muy pequeñas: solo tooltip/leyenda
            const pos = arc.tooltipPosition();
            ctx.fillStyle = '#fff';
            ctx.shadowColor = 'rgba(0,0,0,.45)';
            ctx.shadowBlur = 4;
            ctx.fillText(pct + '%', pos.x, pos.y);
        });
        ctx.restore();
    }
};
new Chart(canalesCtx, {
    type: 'doughnut',
    data: {
        labels: Object.keys(canalesDistribucion).map((k, i) => k + ' (' + canalesPct[i] + '%)'),
        datasets: [{
            data: Object.values(canalesDistribucion),
            backgroundColor: [
                '#667eea', '#764ba2', '#f093fb', '#f5576c',
                '#4facfe', '#00f2fe', '#43e97b', '#38f9d7'
            ],
            borderWidth: 2,
            borderColor: '#fff'
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: { position: 'bottom' },
            tooltip: {
                callbacks: {
                    label: c => ' ' + Object.keys(canalesDistribucion)[c.dataIndex] + ': ' + c.parsed + ' casos (' + canalesPct[c.dataIndex] + '%)'
                }
            }
        }
    },
    plugins: [slicePctPlugin]
});
<?php endif; ?>

<?php if (mostrarSeccion('usuarios', $tipo_reporte)): ?>
// Gráfica de Usuarios
const usuariosCtx = document.getElementById('usuariosChart').getContext('2d');
const usuarios = Object.keys(cargaUsuarios);
const cargas = Object.values(cargaUsuarios);

new Chart(usuariosCtx, {
    type: 'bar',
    data: {
        labels: usuarios,
        datasets: [{
            label: 'PQRS Creadas',
            data: cargas,
            backgroundColor: '#667eea',
            borderWidth: 1
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        scales: {
            y: { beginAtZero: true }
        }
    }
});
<?php endif; ?>

// Inicializar DataTable para heatmap y PQRS en riesgo
$(document).ready(function() {
    <?php if (mostrarSeccion('areas', $tipo_reporte)): ?>
    $('#heatmapTable').DataTable({
        language: {
            url: '//cdn.datatables.net/plug-ins/1.13.6/i18n/es-ES.json'
        },
        pageLength: 10,
        order: [[1, 'asc']]
    });
    <?php endif; ?>

    if (document.getElementById('tablaPqrsRiesgo')) {
        $('#tablaPqrsRiesgo').DataTable({
            language: {
                url: '//cdn.datatables.net/plug-ins/1.13.6/i18n/es-ES.json'
            },
            pageLength: 10,
            order: [[3, 'asc']], 
            columnDefs: [
                { orderable: false, targets: [4] } 
            ]
        });
    }
});
</script>

<?php require_once '../includes/footer.php'; ?>