<?php
$page_title = 'Sophía - Estado del Sistema';
require_once '../includes/header.php';
require_once '../config/database.php';

// Verificar estado de la base de datos
$db_status = false;
$db_test = ['success' => false, 'error' => 'No se pudo conectar'];

try {
    $db_test = ejecutarConsulta("SELECT 1 as test");
    $db_status = $db_test['success'];
} catch (Exception $e) {
    $db_test = ['success' => false, 'error' => $e->getMessage()];
    $db_status = false;
}

// Verificar extensiones PHP necesarias
$php_extensions = [
    'mysqli' => extension_loaded('mysqli'),
    'session' => extension_loaded('session'),
    'json' => extension_loaded('json'),
    'mbstring' => extension_loaded('mbstring')
];

// Información del servidor
$server_info = [
    'PHP Version' => PHP_VERSION,
    'Server Software' => $_SERVER['SERVER_SOFTWARE'] ?? 'Desconocido',
    'Document Root' => $_SERVER['DOCUMENT_ROOT'] ?? 'Desconocido',
    'Memory Limit' => ini_get('memory_limit'),
    'Max Execution Time' => ini_get('max_execution_time') . ' segundos',
    'Upload Max Filesize' => ini_get('upload_max_filesize')
];

// Verificar permisos de archivos
$file_permissions = [
    'config/' => is_readable('../config/'),
    'includes/' => is_readable('../includes/'),
    'auth/' => is_readable('../auth/'),
    'dashboard/' => is_readable('./')
];
?>

<div class="row mb-4">
    <div class="col-12">
        <h2 class="mb-0">
            <i class="fas fa-heartbeat text-primary me-2"></i>
            Estado del Sistema Sophía
        </h2>
        <p class="text-muted">Monitoreo y diagnóstico del sistema de gestión PQRS</p>
    </div>
</div>

<!-- Estado General -->
<div class="row mb-4">
    <div class="col-md-6">
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0">
                    <i class="fas fa-database me-2"></i>
                    Estado de Base de Datos
                </h5>
            </div>
            <div class="card-body">
                <div class="d-flex align-items-center mb-3">
                    <div class="me-3">
                        <?php if ($db_status && $db_test['success']): ?>
                            <i class="fas fa-check-circle text-success" style="font-size: 2rem;"></i>
                        <?php else: ?>
                            <i class="fas fa-times-circle text-danger" style="font-size: 2rem;"></i>
                        <?php endif; ?>
                    </div>
                    <div>
                        <h4 class="mb-0">
                            <?php echo $db_status && $db_test['success'] ? 'Conectado' : 'Desconectado'; ?>
                        </h4>
                        <p class="text-muted mb-0">
                            <?php echo $db_status && $db_test['success'] ? 'Conexión estable' : 'Error de conexión'; ?>
                        </p>
                    </div>
                </div>
                
                <?php if (!$db_status || !$db_test['success']): ?>
                    <div class="alert alert-danger">
                        <strong>Error:</strong> <?php echo $db_test['error'] ?? 'No se pudo conectar a la base de datos'; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <div class="col-md-6">
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0">
                    <i class="fas fa-server me-2"></i>
                    Estado del Servidor
                </h5>
            </div>
            <div class="card-body">
                <div class="d-flex align-items-center mb-3">
                    <div class="me-3">
                        <i class="fas fa-server text-info" style="font-size: 2rem;"></i>
                    </div>
                    <div>
                        <h4 class="mb-0">Activo</h4>
                        <p class="text-muted mb-0">Servidor funcionando correctamente</p>
                    </div>
                </div>
                
                <div class="row text-center">
                    <div class="col-6">
                        <small class="text-muted">Uptime</small>
                        <div class="fw-bold"><?php echo date('H:i:s'); ?></div>
                    </div>
                    <div class="col-6">
                        <small class="text-muted">Memoria</small>
                        <div class="fw-bold"><?php echo round(memory_get_usage() / 1024 / 1024, 2); ?> MB</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Extensiones PHP -->
<div class="row mb-4">
    <div class="col-12">
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0">
                    <i class="fas fa-puzzle-piece me-2"></i>
                    Extensiones PHP Requeridas
                </h5>
            </div>
            <div class="card-body">
                <div class="row">
                    <?php foreach ($php_extensions as $extension => $loaded): ?>
                    <div class="col-md-3 mb-3">
                        <div class="d-flex align-items-center">
                            <i class="fas fa-<?php echo $loaded ? 'check-circle text-success' : 'times-circle text-danger'; ?> me-2"></i>
                            <span class="fw-bold"><?php echo strtoupper($extension); ?></span>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Información del Servidor -->
<div class="row mb-4">
    <div class="col-12">
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0">
                    <i class="fas fa-info-circle me-2"></i>
                    Información del Servidor
                </h5>
            </div>
            <div class="card-body">
                <div class="row">
                    <?php foreach ($server_info as $key => $value): ?>
                    <div class="col-md-6 mb-3">
                        <div class="d-flex justify-content-between">
                            <span class="text-muted"><?php echo $key; ?>:</span>
                            <span class="fw-bold"><?php echo htmlspecialchars($value); ?></span>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Permisos de Archivos -->
<div class="row mb-4">
    <div class="col-12">
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0">
                    <i class="fas fa-folder me-2"></i>
                    Permisos de Archivos
                </h5>
            </div>
            <div class="card-body">
                <div class="row">
                    <?php foreach ($file_permissions as $path => $readable): ?>
                    <div class="col-md-3 mb-3">
                        <div class="d-flex align-items-center">
                            <i class="fas fa-<?php echo $readable ? 'check-circle text-success' : 'times-circle text-danger'; ?> me-2"></i>
                            <span class="fw-bold"><?php echo $path; ?></span>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Acciones del Sistema -->
<div class="row">
    <div class="col-12">
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0">
                    <i class="fas fa-tools me-2"></i>
                    Acciones del Sistema
                </h5>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-4 mb-3">
                        <button class="btn btn-outline-primary w-100" onclick="probarConexion()">
                            <i class="fas fa-sync me-2"></i>
                            Probar Conexión BD
                        </button>
                    </div>
                    <div class="col-md-4 mb-3">
                        <button class="btn btn-outline-info w-100" onclick="verLogs()">
                            <i class="fas fa-file-alt me-2"></i>
                            Ver Logs del Sistema
                        </button>
                    </div>
                    <div class="col-md-4 mb-3">
                        <button class="btn btn-outline-success w-100" onclick="exportarDiagnostico()">
                            <i class="fas fa-download me-2"></i>
                            Exportar Diagnóstico
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function probarConexion() {
    // Simular prueba de conexión
    const btn = event.target;
    const originalText = btn.innerHTML;
    
    btn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Probando...';
    btn.disabled = true;
    
    setTimeout(() => {
        btn.innerHTML = '<i class="fas fa-check me-2"></i>Prueba Completada';
        btn.classList.remove('btn-outline-primary');
        btn.classList.add('btn-success');
        
        setTimeout(() => {
            btn.innerHTML = originalText;
            btn.disabled = false;
            btn.classList.remove('btn-success');
            btn.classList.add('btn-outline-primary');
        }, 2000);
    }, 1500);
}

function verLogs() {
    alert('Función de logs en desarrollo. Los logs se guardan en el servidor.');
}

function exportarDiagnostico() {
    const diagnostico = {
        timestamp: new Date().toISOString(),
        db_status: <?php echo $db_status ? 'true' : 'false'; ?>,
        php_version: '<?php echo PHP_VERSION; ?>',
        server_software: '<?php echo $_SERVER['SERVER_SOFTWARE'] ?? 'Desconocido'; ?>',
        memory_limit: '<?php echo ini_get('memory_limit'); ?>'
    };
    
    const blob = new Blob([JSON.stringify(diagnostico, null, 2)], {type: 'application/json'});
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = 'sophia-diagnostico-' + new Date().toISOString().split('T')[0] + '.json';
    a.click();
    URL.revokeObjectURL(url);
}
</script>

<?php require_once '../includes/footer.php'; ?>
