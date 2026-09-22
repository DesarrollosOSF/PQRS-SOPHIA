<?php
$page_title = 'Sophía - Dashboard Principal';
require_once '../includes/header.php';
require_once '../config/database.php';
require_once '../config/pqrs_helper.php';

// Fechas por defecto: últimos 2 meses
$fecha_inicio_default = date('Y-m-d', strtotime('-2 months'));
$fecha_fin_default = date('Y-m-d');

// Consulta principal para obtener datos de PQRS (últimos 2 meses)
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

// Ejecutar consulta con parámetros de fecha
$resultado = ejecutarConsulta($sql_pqrs, [$fecha_inicio_default, $fecha_fin_default]);

if (!$resultado['success']) {
    $pqrs_data = [];
    $error_bd = $resultado['error'];
} else {
    $pqrs_data = $resultado['data'];
    $error_bd = null;
    
    // Procesar PQRS con cálculo de días hábiles
    $pqrs_data = procesarPQRSConDiasHabiles($pqrs_data);
    
    // Ordenar por urgencia usando días hábiles
    $pqrs_data = ordenarPQRSPorUrgencia($pqrs_data);
}

// Calcular KPIs
$total_pqrs = count($pqrs_data);
$pqrs_vencidas = 0;
$dias_restantes_total = 0;
$estados_count = ['Borrador' => 0, 'Validado' => 0, 'Cancelado' => 0];
$prioridades_count = [];
$canales_count = [];
$areas_count = [];

// Filtrar PQRS Validado solo del mes actual
$mes_actual = date('Y-m');
$pqrs_validado_mes = 0;

foreach ($pqrs_data as $pqr) {
    // Solo las PQRS en estado "Borrador" pueden vencer (usando días hábiles)
    if ($pqr['Estado_Documento'] == 'Borrador' && $pqr['Dias_Habiles_Restantes'] < 0) {
        $pqrs_vencidas++;
    }
    
    // Sumar días restantes para promedio (solo Borrador, usando días hábiles)
    if ($pqr['Estado_Documento'] == 'Borrador') {
        $dias_restantes_total += $pqr['Dias_Habiles_Restantes'];
    }
    
    // Contar por estado
    if (isset($estados_count[$pqr['Estado_Documento']])) {
        $estados_count[$pqr['Estado_Documento']]++;
    }
    
    // Contar PQRS Validado del mes actual
    if ($pqr['Estado_Documento'] == 'Validado' && strpos($pqr['Fecha_Creacion'], $mes_actual) === 0) {
        $pqrs_validado_mes++;
    }
    
    // Contar por prioridad
    $prioridad = $pqr['custom_prioridad'] ?? 'No especificada';
    $prioridades_count[$prioridad] = ($prioridades_count[$prioridad] ?? 0) + 1;
    
    // Contar por canal
    $canal = $pqr['custom_canal_de_entrada'] ?? 'No especificado';
    $canales_count[$canal] = ($canales_count[$canal] ?? 0) + 1;
    
    // Contar por área
    $area = $pqr['custom_área_responsable'] ?? 'No especificado';
    $areas_count[$area] = ($areas_count[$area] ?? 0) + 1;
}

// Calcular porcentaje de vencidas solo sobre PQRS Borrador
$total_borrador = $estados_count['Borrador'];
$porcentaje_vencidas = $total_borrador > 0 ? round(($pqrs_vencidas / $total_borrador) * 100, 1) : 0;

// Calcular tiempo promedio solo para PQRS Borrador
$tiempo_promedio = $total_borrador > 0 ? round($dias_restantes_total / $total_borrador, 1) : 0;
?>

<div class="row mb-4">
    <div class="col-12">
        <h2 class="mb-0">
            <i class="fas fa-tachometer-alt text-primary me-2"></i>
            Dashboard Principal
        </h2>
        <p class="text-muted">Resumen ejecutivo del sistema de PQRS (últimos 2 meses) - Cálculo con días hábiles (excluye sábados, domingos y festivos de Colombia)</p>
    </div>
</div>

<?php if ($error_bd): ?>
<div class="row mb-4">
    <div class="col-12">
        <?php echo mostrarErrorBD($error_bd); ?>
    </div>
</div>
<?php endif; ?>

<!-- KPIs Cards -->
<div class="row mb-4">
    <div class="col-xl-3 col-md-6 mb-4">
        <div class="kpi-card">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <p class="kpi-number"><?php echo $total_borrador; ?></p>
                    <p class="kpi-label">PQRS Borrador (Activas)</p>
                </div>
                <div class="kpi-icon">
                    <i class="fas fa-file-alt"></i>
                </div>
            </div>
        </div>
    </div>
    
    <div class="col-xl-3 col-md-6 mb-4">
        <div class="kpi-card">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <p class="kpi-number text-danger"><?php echo $pqrs_vencidas; ?></p>
                    <p class="kpi-label">PQRS Borrador Vencidas</p>
                </div>
                <div class="kpi-icon text-danger">
                    <i class="fas fa-exclamation-triangle"></i>
                </div>
            </div>
        </div>
    </div>
    
    <div class="col-xl-3 col-md-6 mb-4">
        <div class="kpi-card">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <p class="kpi-number text-info"><?php echo $tiempo_promedio; ?></p>
                    <p class="kpi-label">Días Hábiles Promedio Restantes</p>
                </div>
                <div class="kpi-icon text-info">
                    <i class="fas fa-clock"></i>
                </div>
            </div>
        </div>
    </div>
    
    <div class="col-xl-3 col-md-6 mb-4">
        <div class="kpi-card">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <p class="kpi-number text-success"><?php echo $pqrs_validado_mes; ?></p>
                    <p class="kpi-label">PQRS Validadas (Mes Actual)</p>
                </div>
                <div class="kpi-icon text-success">
                    <i class="fas fa-check-circle"></i>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Gráficas -->
<div class="row mb-4">
    <div class="col-xl-6 mb-4">
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0">
                    <i class="fas fa-chart-pie me-2"></i>
                    Distribución por Estado
                </h5>
            </div>
            <div class="card-body">
                <canvas id="estadosChart" height="300"></canvas>
            </div>
        </div>
    </div>
    
    <div class="col-xl-6 mb-4">
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0">
                    <i class="fas fa-chart-bar me-2"></i>
                    PQRS por Prioridad
                </h5>
            </div>
            <div class="card-body">
                <canvas id="prioridadesChart" height="300"></canvas>
            </div>
        </div>
    </div>
</div>

<div class="row mb-4">
    <div class="col-xl-6 mb-4">
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0">
                    <i class="fas fa-chart-line me-2"></i>
                    Canales de Entrada
                </h5>
            </div>
            <div class="card-body">
                <canvas id="canalesChart" height="300"></canvas>
            </div>
        </div>
    </div>
    
    <div class="col-xl-6 mb-4">
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0">
                    <i class="fas fa-users me-2"></i>
                    Áreas Responsables
                </h5>
            </div>
            <div class="card-body">
                <canvas id="areasChart" height="300"></canvas>
            </div>
        </div>
    </div>
</div>

<!-- Tabla de PQRS Recientes -->
<div class="row">
    <div class="col-12">
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0">
                    <i class="fas fa-list me-2"></i>
                    PQRS Recientes
                </h5>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover" id="pqrsTable">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Peticionario</th>
                                <th>Estado</th>
                                <th>Prioridad</th>
                                <th>Días Hábiles Restantes</th>
                                <th>Área Responsable</th>
                                <th>Fecha Creación</th>
                                <th>Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach (array_slice($pqrs_data, 0, 10) as $pqr): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($pqr['name']); ?></td>
                                <td><?php echo htmlspecialchars($pqr['Nombre_Peticionario']); ?></td>
                                <td>
                                    <span class="badge bg-<?php 
                                        echo $pqr['Estado_Documento'] == 'Validado' ? 'success' : 
                                            ($pqr['Estado_Documento'] == 'Borrador' ? 'warning' : 'danger'); 
                                    ?>">
                                        <?php echo htmlspecialchars($pqr['Estado_Documento']); ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="badge bg-<?php 
                                        echo $pqr['custom_prioridad'] == 'Alta' ? 'danger' : 
                                            ($pqr['custom_prioridad'] == 'Media' ? 'warning' : 'info'); 
                                    ?>">
                                        <?php echo htmlspecialchars($pqr['custom_prioridad'] ?? 'Media'); ?>
                                    </span>
                                </td>
                                <td>
                                    <?php echo generarBadgeDiasHabiles($pqr['Dias_Habiles_Restantes'], $pqr['Estado_Documento']); ?>
                                    <small class="text-muted d-block" style="font-size: 0.75rem;">
                                        <i class="fas fa-calendar-alt"></i> Límite: <?php echo date('d/m/Y', strtotime($pqr['Fecha_Maxima_Respuesta_Habiles'])); ?>
                                    </small>
                                </td>
                                <td><?php echo htmlspecialchars($pqr['custom_área_responsable'] ?? 'No asignada'); ?></td>
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
// Datos para las gráficas
const estadosData = <?php echo json_encode($estados_count); ?>;
const prioridadesData = <?php echo json_encode($prioridades_count); ?>;
const canalesData = <?php echo json_encode($canales_count); ?>;
const areasData = <?php echo json_encode($areas_count); ?>;

// Gráfica de Estados
const estadosCtx = document.getElementById('estadosChart').getContext('2d');
new Chart(estadosCtx, {
    type: 'doughnut',
    data: {
        labels: Object.keys(estadosData),
        datasets: [{
            data: Object.values(estadosData),
            backgroundColor: ['#667eea', '#28a745', '#dc3545'],
            borderWidth: 2,
            borderColor: '#fff'
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: {
                position: 'bottom'
            }
        }
    }
});

// Gráfica de Prioridades
const prioridadesCtx = document.getElementById('prioridadesChart').getContext('2d');
const prioridadesLabels = Object.keys(prioridadesData);
const prioridadesValues = Object.values(prioridadesData);

// Generar colores dinámicos para prioridades
const prioridadesColors = prioridadesLabels.map(label => {
    switch(label.toLowerCase()) {
        case 'alta': return '#dc3545';
        case 'media': return '#ffc107';
        case 'baja': return '#28a745';
        default: return '#17a2b8';
    }
});

new Chart(prioridadesCtx, {
    type: 'bar',
    data: {
        labels: prioridadesLabels,
        datasets: [{
            label: 'Cantidad',
            data: prioridadesValues,
            backgroundColor: prioridadesColors,
            borderWidth: 1
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        scales: {
            y: {
                beginAtZero: true
            }
        }
    }
});

// Gráfica de Canales
const canalesCtx = document.getElementById('canalesChart').getContext('2d');
new Chart(canalesCtx, {
    type: 'pie',
    data: {
        labels: Object.keys(canalesData),
        datasets: [{
            data: Object.values(canalesData),
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
            legend: {
                position: 'bottom'
            }
        }
    }
});

// Gráfica de Áreas (Chart.js 4 usa `bar` con `indexAxis: 'y'`)
const areasCtx = document.getElementById('areasChart').getContext('2d');
const areasLabels = Object.keys(areasData);
const areasValues = Object.values(areasData);
const tieneDatosAreas = areasLabels.length > 0;

new Chart(areasCtx, {
    type: 'bar',
    data: {
        labels: tieneDatosAreas ? areasLabels : ['Sin datos'],
        datasets: [{
            label: 'Cantidad',
            data: tieneDatosAreas ? areasValues : [0],
            backgroundColor: '#667eea',
            borderWidth: 1
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        indexAxis: 'y',
        scales: {
            x: {
                beginAtZero: true,
                ticks: {
                    precision: 0
                }
            }
        },
        plugins: {
            legend: {
                display: false
            }
        }
    }
});

// Inicializar DataTable
$(document).ready(function() {
    $('#pqrsTable').DataTable({
        language: {
            url: '//cdn.datatables.net/plug-ins/1.13.6/i18n/es-ES.json'
        },
        pageLength: 10,
        order: [[6, 'desc']]
    });
});

function verDetalle(id) {
    // Buscar los datos de la PQRS en la tabla
    const table = document.getElementById('pqrsTable');
    const rows = table.getElementsByTagName('tbody')[0].getElementsByTagName('tr');

    let pqrsData = null;
    for (let i = 0; i < rows.length; i++) {
        const firstCell = rows[i].getElementsByTagName('td')[0];
        if (firstCell && firstCell.textContent.trim() === id) {
            const cells = rows[i].getElementsByTagName('td');

            const peticionario = cells[1].textContent.trim();
            const estado = cells[2].textContent.trim();
            const prioridad = cells[3].textContent.trim();

            const diasCelda = cells[4];
            const badgeDias = diasCelda.querySelector('.badge');
            const diasRestantes = badgeDias ? badgeDias.textContent.trim() : diasCelda.textContent.trim();
            const fechaLimiteEl = diasCelda.querySelector('small');
            const fechaLimite = fechaLimiteEl ? fechaLimiteEl.textContent.replace(/^.*Límite:\s*/, '').trim() : '';

            const area = cells[5].textContent.trim();
            const fechaCreacion = cells[6].textContent.trim();

            pqrsData = {
                id: id,
                peticionario: peticionario,
                estado: estado,
                prioridad: prioridad,
                diasRestantes: diasRestantes,
                fechaLimite: fechaLimite,
                area: area,
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
                <p><strong>Estado:</strong> ${pqrsData.estado}</p>
                <p><strong>Prioridad:</strong> ${pqrsData.prioridad}</p>
                <p><strong>Área Responsable:</strong> ${pqrsData.area}</p>
            </div>
            <div class="col-md-6">
                <h6>Fechas</h6>
                <p><strong>Fecha Creación:</strong> ${pqrsData.fechaCreacion}</p>
                <p><strong>Fecha Límite:</strong> ${pqrsData.fechaLimite}</p>
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

function updateDashboard() {
    // Recargar la página para actualizar datos
    location.reload();
}
</script>

<?php require_once '../includes/footer.php'; ?>
