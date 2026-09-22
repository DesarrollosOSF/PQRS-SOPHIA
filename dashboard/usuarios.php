<?php
$page_title = 'Sophía - Gestión de Usuarios';
require_once '../includes/header.php';
require_once '../config/database.php';

// Fechas por defecto: últimos 2 meses
$fecha_inicio_default = date('Y-m-d', strtotime('-2 months'));
$fecha_fin_default = date('Y-m-d');

// Obtener datos de usuarios y su carga de trabajo (últimos 2 meses)
$sql_usuarios = "
SELECT 
    tp.`owner` AS Usuario,
    COUNT(*) AS Total_PQRS,
    SUM(CASE WHEN tp.docstatus = 1 THEN 1 ELSE 0 END) AS PQRS_Validadas,
    SUM(CASE WHEN tp.docstatus = 0 AND DATEDIFF(DATE_ADD(DATE(tp.creation), INTERVAL 15 DAY), CURDATE()) < 0 THEN 1 ELSE 0 END) AS PQRS_Vencidas,
    AVG(CASE WHEN tp.docstatus = 0 THEN DATEDIFF(DATE_ADD(DATE(tp.creation), INTERVAL 15 DAY), CURDATE()) ELSE NULL END) AS Promedio_Dias_Restantes,
    MIN(DATE(tp.creation)) AS Primera_PQRS,
    MAX(DATE(tp.creation)) AS Ultima_PQRS
FROM `tabPQRFS` AS tp
WHERE tp.`owner` IS NOT NULL AND tp.`owner` != '' 
    AND DATE(tp.creation) BETWEEN ? AND ?
GROUP BY tp.`owner`
ORDER BY Total_PQRS DESC
";

// Ejecutar consulta con parámetros de fecha
$resultado = ejecutarConsulta($sql_usuarios, [$fecha_inicio_default, $fecha_fin_default]);

if (!$resultado['success']) {
    $usuarios_data = [];
    $error_bd = $resultado['error'];
} else {
    $usuarios_data = $resultado['data'];
    $error_bd = null;
}

// Calcular estadísticas generales
$total_usuarios = count($usuarios_data);
$total_pqrs = array_sum(array_column($usuarios_data, 'Total_PQRS'));
$promedio_pqrs_usuario = $total_usuarios > 0 ? round($total_pqrs / $total_usuarios, 1) : 0;
?>

<div class="row mb-4">
    <div class="col-12">
        <h2 class="mb-0">
            <i class="fas fa-users text-primary me-2"></i>
            Gestión de Usuarios
        </h2>
        <p class="text-muted">Análisis de carga de trabajo por usuario (últimos 2 meses)</p>
    </div>
</div>

<?php if (isset($error_bd) && $error_bd): ?>
<div class="row mb-4">
    <div class="col-12">
        <?php echo mostrarErrorBD($error_bd); ?>
    </div>
</div>
<?php endif; ?>

<!-- Resumen de Usuarios -->
<div class="row mb-4">
    <div class="col-md-3">
        <div class="kpi-card">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <p class="kpi-number"><?php echo $total_usuarios; ?></p>
                    <p class="kpi-label">Total Usuarios</p>
                </div>
                <div class="kpi-icon">
                    <i class="fas fa-users"></i>
                </div>
            </div>
        </div>
    </div>
    
    <div class="col-md-3">
        <div class="kpi-card">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <p class="kpi-number"><?php echo $total_pqrs; ?></p>
                    <p class="kpi-label">Total PQRS</p>
                </div>
                <div class="kpi-icon">
                    <i class="fas fa-file-alt"></i>
                </div>
            </div>
        </div>
    </div>
    
    <div class="col-md-3">
        <div class="kpi-card">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <p class="kpi-number"><?php echo $promedio_pqrs_usuario; ?></p>
                    <p class="kpi-label">Promedio PQRS/Usuario</p>
                </div>
                <div class="kpi-icon">
                    <i class="fas fa-chart-line"></i>
                </div>
            </div>
        </div>
    </div>
    
    <div class="col-md-3">
        <div class="kpi-card">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <p class="kpi-number text-success">
                        <?php 
                        $usuarios_activos = array_filter($usuarios_data, function($user) {
                            return strtotime($user['Ultima_PQRS']) >= strtotime('-7 days');
                        });
                        echo count($usuarios_activos);
                        ?>
                    </p>
                    <p class="kpi-label">Usuarios Activos (7 días)</p>
                </div>
                <div class="kpi-icon text-success">
                    <i class="fas fa-user-check"></i>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Gráfica de Carga por Usuario -->
<div class="row mb-4">
    <div class="col-12">
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0">
                    <i class="fas fa-chart-bar me-2"></i>
                    Distribución de Carga de Trabajo por Usuario
                </h5>
            </div>
            <div class="card-body">
                <canvas id="cargaUsuariosChart" height="400"></canvas>
            </div>
        </div>
    </div>
</div>

<!-- Tabla de Usuarios -->
<div class="row">
    <div class="col-12">
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0">
                    <i class="fas fa-table me-2"></i>
                    Detalle de Usuarios
                </h5>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover" id="usuariosTable">
                        <thead>
                            <tr>
                                <th>Usuario</th>
                                <th>Total PQRS</th>
                                <th>PQRS Validadas</th>
                                <th>PQRS Vencidas</th>
                                <th>% Cumplimiento</th>
                                <th>Promedio Días Restantes</th>
                                <th>Primera PQRS</th>
                                <th>Última PQRS</th>
                                <th>Estado</th>
                                <th>Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($usuarios_data as $usuario): ?>
                            <?php 
                            $porcentaje_cumplimiento = $usuario['Total_PQRS'] > 0 ? 
                                round(($usuario['PQRS_Validadas'] / $usuario['Total_PQRS']) * 100, 1) : 0;
                            $es_activo = strtotime($usuario['Ultima_PQRS']) >= strtotime('-7 days');
                            ?>
                            <tr>
                                <td>
                                    <div class="d-flex align-items-center">
                                        <div class="user-avatar me-2" style="width: 30px; height: 30px; font-size: 0.8rem;">
                                            <i class="fas fa-user"></i>
                                        </div>
                                        <strong><?php echo htmlspecialchars($usuario['Usuario']); ?></strong>
                                    </div>
                                </td>
                                <td>
                                    <span class="badge bg-primary"><?php echo $usuario['Total_PQRS']; ?></span>
                                </td>
                                <td>
                                    <span class="badge bg-success"><?php echo $usuario['PQRS_Validadas']; ?></span>
                                </td>
                                <td>
                                    <span class="badge bg-<?php echo $usuario['PQRS_Vencidas'] > 0 ? 'danger' : 'success'; ?>">
                                        <?php echo $usuario['PQRS_Vencidas']; ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="progress" style="height: 20px;">
                                        <div class="progress-bar bg-<?php 
                                            echo $porcentaje_cumplimiento >= 80 ? 'success' : 
                                                ($porcentaje_cumplimiento >= 60 ? 'warning' : 'danger'); 
                                        ?>" style="width: <?php echo $porcentaje_cumplimiento; ?>%">
                                            <?php echo $porcentaje_cumplimiento; ?>%
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <?php 
                                    $promedio_dias = $usuario['Promedio_Dias_Restantes'] ?? 0;
                                    $promedio_dias = is_numeric($promedio_dias) ? $promedio_dias : 0;
                                    ?>
                                    <span class="badge bg-<?php 
                                        echo $promedio_dias < 0 ? 'danger' : 
                                            ($promedio_dias < 5 ? 'warning' : 'success'); 
                                    ?>">
                                        <?php echo round($promedio_dias, 1); ?> días
                                    </span>
                                </td>
                                <td><?php echo date('d/m/Y', strtotime($usuario['Primera_PQRS'])); ?></td>
                                <td><?php echo date('d/m/Y', strtotime($usuario['Ultima_PQRS'])); ?></td>
                                <td>
                                    <span class="badge bg-<?php echo $es_activo ? 'success' : 'secondary'; ?>">
                                        <?php echo $es_activo ? 'Activo' : 'Inactivo'; ?>
                                    </span>
                                </td>
                                <td>
                                    <button class="btn btn-sm btn-outline-info" onclick="verDetalleUsuario('<?php echo $usuario['Usuario']; ?>')" title="Ver Detalle">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                    <button class="btn btn-sm btn-outline-primary" onclick="verPQRSUsuario('<?php echo $usuario['Usuario']; ?>')" title="Ver PQRS">
                                        <i class="fas fa-list"></i>
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

<!-- Modal de Detalle de Usuario -->
<div class="modal fade" id="detalleUsuarioModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Detalle de Usuario</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="detalleUsuarioContent">
                <!-- Contenido dinámico -->
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cerrar</button>
            </div>
        </div>
    </div>
</div>

<script>
// Datos para la gráfica
const usuariosData = <?php echo json_encode($usuarios_data); ?>;

// Gráfica de Carga por Usuario
const cargaUsuariosCtx = document.getElementById('cargaUsuariosChart').getContext('2d');
const usuarios = usuariosData.map(user => user.Usuario);
const cargas = usuariosData.map(user => parseInt(user.Total_PQRS));

new Chart(cargaUsuariosCtx, {
    type: 'bar',
    data: {
        labels: usuarios,
        datasets: [{
            label: 'Total PQRS',
            data: cargas,
            backgroundColor: '#667eea',
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
    $('#usuariosTable').DataTable({
        language: {
            url: '//cdn.datatables.net/plug-ins/1.13.6/i18n/es-ES.json'
        },
        pageLength: 25,
        order: [[1, 'desc']],
        columnDefs: [
            { orderable: false, targets: 9 }
        ]
    });
});

function verDetalleUsuario(usuario) {
    const userData = usuariosData.find(user => user.Usuario === usuario);
    
    const detalleContent = `
        <div class="row">
            <div class="col-md-6">
                <h6>Información General</h6>
                <p><strong>Usuario:</strong> ${usuario}</p>
                <p><strong>Total PQRS:</strong> ${userData.Total_PQRS}</p>
                <p><strong>PQRS Validadas:</strong> ${userData.PQRS_Validadas}</p>
                <p><strong>PQRS Vencidas:</strong> ${userData.PQRS_Vencidas}</p>
            </div>
            <div class="col-md-6">
                <h6>Estadísticas</h6>
                <p><strong>% Cumplimiento:</strong> ${((userData.PQRS_Validadas / userData.Total_PQRS) * 100).toFixed(1)}%</p>
                <p><strong>Promedio Días Restantes:</strong> ${(userData.Promedio_Dias_Restantes ? parseFloat(userData.Promedio_Dias_Restantes).toFixed(1) : '0.0')} días</p>
                <p><strong>Primera PQRS:</strong> ${new Date(userData.Primera_PQRS).toLocaleDateString()}</p>
                <p><strong>Última PQRS:</strong> ${new Date(userData.Ultima_PQRS).toLocaleDateString()}</p>
            </div>
        </div>
    `;
    
    document.getElementById('detalleUsuarioContent').innerHTML = detalleContent;
    new bootstrap.Modal(document.getElementById('detalleUsuarioModal')).show();
}

// Función de edición eliminada - Solo consulta

function verPQRSUsuario(usuario) {
    // Redirigir a la página de PQRS con filtro por usuario
    window.location.href = `pqrs.php?usuario=${encodeURIComponent(usuario)}`;

}
</script>

<?php require_once '../includes/footer.php'; ?>
