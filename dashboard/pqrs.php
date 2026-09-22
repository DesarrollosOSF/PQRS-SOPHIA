<?php
$page_title = 'Sophía - Gestión de PQRS';
require_once '../includes/header.php';
require_once '../config/database.php';
require_once '../config/pqrs_helper.php';

// Obtener filtros
$filtro_estado = $_GET['estado'] ?? '';
$filtro_prioridad = $_GET['prioridad'] ?? '';
$filtro_area = $_GET['area'] ?? '';
$filtro_vencidas = $_GET['vencidas'] ?? '';
$filtro_usuario = $_GET['usuario'] ?? '';
$filtro_fecha_inicio = $_GET['fecha_inicio'] ?? '';
$filtro_fecha_fin = $_GET['fecha_fin'] ?? '';

// Fechas por defecto: últimos 2 meses
$fecha_inicio_default = date('Y-m-d', strtotime('-2 months'));
$fecha_fin_default = date('Y-m-d');

// Usar fechas del filtro o las por defecto
$fecha_inicio = $filtro_fecha_inicio ?: $fecha_inicio_default;
$fecha_fin = $filtro_fecha_fin ?: $fecha_fin_default;

// Construir consulta con filtros
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
    tp.descripcion_queja
FROM `tabPQRFS` AS tp
WHERE DATE(tp.creation) BETWEEN ? AND ?
";

$params = [$fecha_inicio, $fecha_fin];

if ($filtro_estado) {
    $estado_condition = $filtro_estado == 'Borrador' ? 'tp.docstatus = 0' : ($filtro_estado == 'Validado' ? 'tp.docstatus = 1' : 'tp.docstatus = 2');
    $sql_pqrs .= " AND $estado_condition";
}

if ($filtro_prioridad) {
    $sql_pqrs .= " AND tp.custom_prioridad = ?";
    $params[] = $filtro_prioridad;
}

if ($filtro_vencidas == 'si') {
    $sql_pqrs .= " AND DATEDIFF(DATE_ADD(DATE(tp.creation), INTERVAL 15 DAY), CURDATE()) < 0";
}

if ($filtro_usuario) {
    $sql_pqrs .= " AND tp.`owner` = ?";
    $params[] = $filtro_usuario;
}

$sql_pqrs .= " ORDER BY 
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
    tp.creation DESC";

// Ejecutar consulta
$mysqli = conectarDB();
$stmt = $mysqli->prepare($sql_pqrs);
$types = str_repeat('s', count($params));
$stmt->bind_param($types, ...$params);
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

// Ordenar por urgencia usando días hábiles
$pqrs_data = ordenarPQRSPorUrgencia($pqrs_data);

// Obtener opciones para filtros (últimos 2 meses)
$sql_prioridades = "SELECT DISTINCT custom_prioridad FROM tabPQRFS WHERE custom_prioridad IS NOT NULL AND custom_prioridad != '' AND DATE(creation) BETWEEN ? AND ?";
$mysqli = conectarDB();
$stmt = $mysqli->prepare($sql_prioridades);
$stmt->bind_param('ss', $fecha_inicio, $fecha_fin);
$stmt->execute();
$result = $stmt->get_result();

$prioridades = [];
while ($row = $result->fetch_assoc()) {
    $prioridades[] = $row;
}

$stmt->close();
$mysqli->close();
?>

<div class="row mb-4">
    <div class="col-12">
        <h2 class="mb-0">
            <i class="fas fa-list-alt text-primary me-2"></i>
            Gestión de PQRS
        </h2>
        <p class="text-muted">Seguimiento de PQRS con días hábiles (excluye sábados, domingos y festivos de Colombia)</p>
    </div>
</div>

<!-- Filtros -->
<div class="row mb-4">
    <div class="col-12">
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0">
                    <i class="fas fa-filter me-2"></i>
                    Filtros de Búsqueda
                </h5>
            </div>
            <div class="card-body">
                <form method="GET" class="row g-3">
                    <input type="hidden" name="usuario" value="<?php echo htmlspecialchars($filtro_usuario); ?>">
                    <div class="col-md-2">
                        <label for="fecha_inicio" class="form-label">Fecha Inicio</label>
                        <input type="date" class="form-control" id="fecha_inicio" name="fecha_inicio"
                            value="<?php echo $fecha_inicio; ?>">
                    </div>

                    <div class="col-md-2">
                        <label for="fecha_fin" class="form-label">Fecha Fin</label>
                        <input type="date" class="form-control" id="fecha_fin" name="fecha_fin"
                            value="<?php echo $fecha_fin; ?>">
                    </div>

                    <div class="col-md-2">
                        <label for="estado" class="form-label">Estado</label>
                        <select class="form-select" id="estado" name="estado">
                            <option value="">Todos</option>
                            <option value="Borrador" <?php echo $filtro_estado == 'Borrador' ? 'selected' : ''; ?>>Borrador</option>
                            <option value="Validado" <?php echo $filtro_estado == 'Validado' ? 'selected' : ''; ?>>Validado</option>
                            <option value="Cancelado" <?php echo $filtro_estado == 'Cancelado' ? 'selected' : ''; ?>>Cancelado</option>
                        </select>
                    </div>

                    <div class="col-md-2">
                        <label for="prioridad" class="form-label">Prioridad</label>
                        <select class="form-select" id="prioridad" name="prioridad">
                            <option value="">Todas</option>
                            <?php foreach ($prioridades as $prioridad): ?>
                                <option value="<?php echo htmlspecialchars($prioridad['custom_prioridad']); ?>"
                                    <?php echo $filtro_prioridad == $prioridad['custom_prioridad'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($prioridad['custom_prioridad']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-md-2">
                        <label for="vencidas" class="form-label">Vencimiento</label>
                        <select class="form-select" id="vencidas" name="vencidas">
                            <option value="">Todas</option>
                            <option value="si" <?php echo $filtro_vencidas == 'si' ? 'selected' : ''; ?>>Solo vencidas</option>
                        </select>
                    </div>

                    <div class="col-md-2">
                        <label class="form-label">&nbsp;</label>
                        <div class="d-grid">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-search me-1"></i>Filtrar
                            </button>
                        </div>
                    </div>

                    <div class="col-12">
                        <a href="pqrs.php" class="btn btn-outline-secondary">
                            <i class="fas fa-times me-2"></i>Limpiar Filtros
                        </a>
                        <button type="button" class="btn btn-success" onclick="exportarExcel()">
                            <i class="fas fa-file-excel me-2"></i>Exportar Excel
                        </button>
                        <!--<small class="text-muted ms-3">
                            <i class="fas fa-info-circle me-1"></i>
                            Mostrando datos del <?php echo date('d/m/Y', strtotime($fecha_inicio)); ?> al <?php echo date('d/m/Y', strtotime($fecha_fin)); ?>
                        </small> -->
                        <small class="text-muted ms-3">
                            <i class="fas fa-info-circle me-1"></i>
                            Mostrando datos del <?php echo date('d/m/Y', strtotime($fecha_inicio)); ?> al <?php echo date('d/m/Y', strtotime($fecha_fin)); ?>
                            <?php if ($filtro_usuario): ?>
                                —- USUARIO: <strong><?php echo htmlspecialchars($filtro_usuario); ?></strong>
                                <a href="pqrs.php" class="ms-1"><i class="fas fa-times-circle" title="Quitar filtro de usuario"></i></a>
                            <?php endif; ?>
                        </small>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- Resumen de Resultados -->
<div class="row mb-4">
    <div class="col-md-3">
        <div class="card text-center">
            <div class="card-body">
                <h5 class="card-title text-primary"><?php echo count($pqrs_data); ?></h5>
                <p class="card-text">Total PQRS (2 meses)</p>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card text-center">
            <div class="card-body">
                <h5 class="card-title text-danger">
                    <?php
                    $vencidas = array_filter($pqrs_data, function ($pqr) {
                        return $pqr['Estado_Documento'] == 'Borrador' && $pqr['Dias_Habiles_Restantes'] < 0;
                    });
                    echo count($vencidas);
                    ?>
                </h5>
                <p class="card-text">Borrador Vencidas (días hábiles)</p>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card text-center">
            <div class="card-body">
                <h5 class="card-title text-warning">
                    <?php
                    $por_vencer = array_filter($pqrs_data, function ($pqr) {
                        return $pqr['Estado_Documento'] == 'Borrador' && $pqr['Dias_Habiles_Restantes'] >= 0 && $pqr['Dias_Habiles_Restantes'] <= 3;
                    });
                    echo count($por_vencer);
                    ?>
                </h5>
                <p class="card-text">En Riesgo (≤3 días hábiles)</p>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card text-center">
            <div class="card-body">
                <h5 class="card-title text-success">
                    <?php
                    $borrador = array_filter($pqrs_data, function ($pqr) {
                        return $pqr['Estado_Documento'] == 'Borrador';
                    });
                    echo count($borrador);
                    ?>
                </h5>
                <p class="card-text">PQRS Borrador Activas</p>
            </div>
        </div>
    </div>
</div>

<!-- Tabla de PQRS -->
<div class="row">
    <div class="col-12">
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0">
                    <i class="fas fa-table me-2"></i>
                    Listado de PQRS
                </h5>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover" id="pqrsTable">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Estado</th>
                                <th>Fecha Creación</th>
                                <th>Fecha Máxima (Hábiles)</th>
                                <th>Días Hábiles Restantes</th>
                                <th>Tipo</th>
                                <th>Prioridad</th>
                                <th>Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($pqrs_data as $pqr): ?>
                                <tr class="<?php echo ($pqr['Estado_Documento'] == 'Borrador' && $pqr['Dias_Habiles_Restantes'] < 0) ? 'table-danger' : ($pqr['Estado_Documento'] == 'Borrador' && $pqr['Dias_Habiles_Restantes'] <= 3 ? 'table-warning' : ''); ?>">
                                    <td>
                                        <strong><?php echo htmlspecialchars($pqr['name']); ?></strong>
                                    </td>
                                    <td>
                                        <span class="badge bg-<?php
                                                                echo $pqr['Estado_Documento'] == 'Validado' ? 'success' : ($pqr['Estado_Documento'] == 'Borrador' ? 'warning' : 'danger');
                                                                ?>">
                                            <?php echo htmlspecialchars($pqr['Estado_Documento']); ?>
                                        </span>
                                    </td>
                                    <td><?php echo date('d/m/Y', strtotime($pqr['Fecha_Creacion'])); ?></td>
                                    <td><?php echo date('d/m/Y', strtotime($pqr['Fecha_Maxima_Respuesta_Habiles'])); ?></td>
                                    <td>
                                        <?php echo generarBadgeDiasHabiles($pqr['Dias_Habiles_Restantes'], $pqr['Estado_Documento']); ?>
                                    </td>
                                    <td>
                                        <span class="badge bg-info">
                                            <?php echo htmlspecialchars($pqr['tipo_peticion']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="badge bg-<?php
                                                                echo $pqr['custom_prioridad'] == 'Alta' ? 'danger' : ($pqr['custom_prioridad'] == 'Media' ? 'warning' : 'info');
                                                                ?>">
                                            <?php echo htmlspecialchars($pqr['custom_prioridad'] ?? 'Media'); ?>
                                        </span>
                                    </td>
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
    $(document).ready(function() {
        $('#pqrsTable').DataTable({
            language: {
                url: '//cdn.datatables.net/plug-ins/1.13.6/i18n/es-ES.json'
            },
            pageLength: 25,
            order: [
                [2, 'desc']
            ], // Ordenar por fecha de creación
            columnDefs: [{
                    orderable: false,
                    targets: 7
                } // Columna de acciones
            ]
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
                // Extraer datos de la fila
                const cells = rows[i].getElementsByTagName('td');
                const estado = cells[1].textContent.trim();
                const fechaCreacion = cells[2].textContent.trim();
                const fechaMaxima = cells[3].textContent.trim();
                const diasRestantes = cells[4].textContent.trim();
                const tipo = cells[5].textContent.trim();
                const prioridad = cells[6].textContent.trim();

                pqrsData = {
                    id: id,
                    estado: estado,
                    fechaCreacion: fechaCreacion,
                    fechaMaxima: fechaMaxima,
                    diasRestantes: diasRestantes,
                    tipo: tipo,
                    prioridad: prioridad
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
                <p><strong>Estado:</strong> ${pqrsData.estado}</p>
                <p><strong>Prioridad:</strong> ${pqrsData.prioridad}</p>
                <p><strong>Tipo:</strong> ${pqrsData.tipo}</p>
            </div>
            <div class="col-md-6">
                <h6>Fechas</h6>
                <p><strong>Fecha Creación:</strong> ${pqrsData.fechaCreacion}</p>
                <p><strong>Fecha Máxima:</strong> ${pqrsData.fechaMaxima}</p>
                <p><strong>Días Restantes:</strong> ${pqrsData.diasRestantes}</p>
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

    // Funciones de edición eliminadas - Solo consulta

    function exportarExcel() {

    const params = new URLSearchParams();
    const fechaInicio = document.getElementById('fecha_inicio').value;
    const fechaFin = document.getElementById('fecha_fin').value;
    const estado = document.getElementById('estado').value;
    const prioridad = document.getElementById('prioridad').value;
    const vencidas = document.getElementById('vencidas').value;

    params.append('fecha_inicio', fechaInicio);
    params.append('fecha_fin', fechaFin);
    params.append('estado', estado);
    params.append('prioridad', prioridad);
    params.append('vencidas', vencidas);


    <?php if ($filtro_usuario): ?>
        params.append(
            'usuario',
            '<?php echo htmlspecialchars(
                $filtro_usuario,
                ENT_QUOTES
            ); ?>'
        );

    <?php endif; ?>

    window.location.href = 'exportar_pqrs.php?' + params.toString();
}
</script>

<?php require_once '../includes/footer.php'; ?>