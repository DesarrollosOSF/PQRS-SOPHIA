<?php
$page_title = 'Sophía - Festivos de Colombia';
require_once '../includes/header.php';
require_once '../config/festivos_colombia.php';

// Obtener año actual o el solicitado
$anio = isset($_GET['anio']) ? intval($_GET['anio']) : date('Y');

// Validar año
if ($anio < 2020 || $anio > 2030) {
    $anio = date('Y');
}

// Obtener festivos
$festivos_info = obtenerInfoFestivosColombia($anio);

// Ejemplo de cálculo de días hábiles
$fecha_ejemplo = date('Y-m-d');
$dias_habiles_a_sumar = 15;
$fecha_limite_ejemplo = calcularFechaLimiteHabiles($fecha_ejemplo, $dias_habiles_a_sumar);
?>

<div class="row mb-4">
    <div class="col-12">
        <h2 class="mb-0">
            <i class="fas fa-calendar-alt text-primary me-2"></i>
            Festivos de Colombia - Año <?php echo $anio; ?>
        </h2>
        <p class="text-muted">Días festivos según la legislación colombiana (Ley 51 de 1983 - Ley Emiliani, Ley 2578 de 2026 - Virgen de Chiquinquirá)</p>
    </div>
</div>

<!-- Selector de Año -->
<div class="row mb-4">
    <div class="col-md-6">
        <div class="card">
            <div class="card-body">
                <h5 class="card-title">
                    <i class="fas fa-calendar me-2"></i>
                    Seleccionar Año
                </h5>
                <form method="GET" class="row g-3">
                    <div class="col-md-6">
                        <select class="form-select" name="anio" id="anio">
                            <?php for ($y = 2020; $y <= 2030; $y++): ?>
                                <option value="<?php echo $y; ?>" <?php echo $y == $anio ? 'selected' : ''; ?>>
                                    <?php echo $y; ?>
                                </option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-search me-1"></i>Ver Festivos
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <div class="col-md-6">
        <div class="card">
            <div class="card-body">
                <h5 class="card-title">
                    <i class="fas fa-info-circle me-2"></i>
                    Información
                </h5>
                <p class="mb-1"><strong>Total de festivos:</strong> <?php echo count($festivos_info); ?> días</p>
                <p class="mb-0"><strong>Días laborales aproximados:</strong> <?php echo 365 - count($festivos_info) - 104; ?> días</p>
                <small class="text-muted">(365 días - festivos - sábados/domingos)</small>
            </div>
        </div>
    </div>
</div>

<!-- Tabla de Festivos -->
<div class="row mb-4">
    <div class="col-12">
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0">
                    <i class="fas fa-list me-2"></i>
                    Listado de Festivos - <?php echo $anio; ?>
                </h5>
            </div>
            <div class="card-body">
                <?php echo generarReporteFestivos($anio); ?>
            </div>
        </div>
    </div>
</div>

<!-- Calculadora de Días Hábiles -->
<div class="row mb-4">
    <div class="col-12">
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0">
                    <i class="fas fa-calculator me-2"></i>
                    Calculadora de Días Hábiles
                </h5>
            </div>
            <div class="card-body">
                <form id="calculadoraForm" class="row g-3">
                    <div class="col-md-4">
                        <label for="fecha_inicio" class="form-label">Fecha de Inicio</label>
                        <input type="date" class="form-control" id="fecha_inicio" 
                               value="<?php echo date('Y-m-d'); ?>" required>
                    </div>
                    <div class="col-md-4">
                        <label for="dias_a_sumar" class="form-label">Días Hábiles a Sumar</label>
                        <input type="number" class="form-control" id="dias_a_sumar" 
                               value="15" min="1" max="365" required>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">&nbsp;</label>
                        <button type="submit" class="btn btn-primary w-100">
                            <i class="fas fa-calculator me-1"></i>Calcular
                        </button>
                    </div>
                </form>
                
                <div id="resultadoCalculo" class="mt-4" style="display: none;">
                    <div class="alert alert-success">
                        <h6><i class="fas fa-check-circle me-2"></i>Resultado:</h6>
                        <p class="mb-1"><strong>Fecha de Inicio:</strong> <span id="resultado_inicio"></span></p>
                        <p class="mb-1"><strong>Días Hábiles:</strong> <span id="resultado_dias"></span></p>
                        <p class="mb-1"><strong>Fecha Límite:</strong> <span id="resultado_limite"></span></p>
                        <p class="mb-0"><small class="text-muted">
                            <i class="fas fa-info-circle"></i> 
                            No se cuentan sábados, domingos ni festivos de Colombia
                        </small></p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Ejemplo Práctico con PQRS -->
<div class="row">
    <div class="col-12">
        <div class="card">
            <div class="card-header bg-info text-white">
                <h5 class="mb-0">
                    <i class="fas fa-lightbulb me-2"></i>
                    Ejemplo Práctico: Cálculo de Vencimiento de PQRS
                </h5>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-6">
                        <h6 class="text-primary">Fecha de Hoy:</h6>
                        <p class="h4"><?php echo date('d/m/Y'); ?></p>
                        
                        <h6 class="text-primary mt-4">Plazo Legal:</h6>
                        <p><?php echo $dias_habiles_a_sumar; ?> días hábiles</p>
                        
                        <h6 class="text-primary mt-4">Fecha Límite de Respuesta:</h6>
                        <p class="h4 text-success"><?php echo date('d/m/Y', strtotime($fecha_limite_ejemplo)); ?></p>
                        
                        <div class="alert alert-warning mt-3">
                            <i class="fas fa-exclamation-triangle me-2"></i>
                            <strong>Importante:</strong> La fecha límite es calculada considerando únicamente días hábiles 
                            (lunes a viernes), excluyendo sábados, domingos y todos los festivos oficiales de Colombia.
                        </div>
                    </div>
                    
                    <div class="col-md-6">
                        <h6 class="text-primary">Comparación: Días Calendario vs Días Hábiles</h6>
                        <div class="table-responsive">
                            <table class="table table-sm table-bordered">
                                <thead class="table-light">
                                    <tr>
                                        <th>Método</th>
                                        <th>Fecha Límite</th>
                                        <th>Días Totales</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr>
                                        <td><strong>Días Calendario</strong></td>
                                        <td><?php echo date('d/m/Y', strtotime($fecha_ejemplo . ' +15 days')); ?></td>
                                        <td>15 días</td>
                                    </tr>
                                    <tr class="table-success">
                                        <td><strong>Días Hábiles ✓</strong></td>
                                        <td><?php echo date('d/m/Y', strtotime($fecha_limite_ejemplo)); ?></td>
                                        <td>
                                            <?php 
                                            $diferencia = round((strtotime($fecha_limite_ejemplo) - strtotime($fecha_ejemplo)) / (60 * 60 * 24));
                                            echo $diferencia . ' días calendario';
                                            ?>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                        
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle me-2"></i>
                            Como se puede ver, 15 días hábiles equivalen a más días de calendario, 
                            ya que se excluyen fines de semana y festivos.
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
document.getElementById('calculadoraForm').addEventListener('submit', function(e) {
    e.preventDefault();
    
    const fechaInicio = document.getElementById('fecha_inicio').value;
    const diasSumar = parseInt(document.getElementById('dias_a_sumar').value);
    
    // Hacer petición AJAX para calcular (simulado con fetch)
    // En producción, esto debería llamar a un endpoint PHP
    
    // Por ahora, recargar la página con parámetros
    window.location.href = `festivos.php?anio=<?php echo $anio; ?>&calcular=1&fecha=${fechaInicio}&dias=${diasSumar}`;
});

<?php if (isset($_GET['calcular'])): ?>
// Mostrar resultado si viene de cálculo
<?php
$fecha_calc = $_GET['fecha'] ?? date('Y-m-d');
$dias_calc = intval($_GET['dias'] ?? 15);
$fecha_limite_calc = calcularFechaLimiteHabiles($fecha_calc, $dias_calc);
?>
document.getElementById('resultadoCalculo').style.display = 'block';
document.getElementById('resultado_inicio').textContent = '<?php echo date('d/m/Y', strtotime($fecha_calc)); ?>';
document.getElementById('resultado_dias').textContent = '<?php echo $dias_calc; ?> días hábiles';
document.getElementById('resultado_limite').textContent = '<?php echo date('d/m/Y', strtotime($fecha_limite_calc)); ?>';
<?php endif; ?>
</script>

<?php require_once '../includes/footer.php'; ?>

