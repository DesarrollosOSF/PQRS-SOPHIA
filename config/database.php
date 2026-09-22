<?php
/**
 * Sophía - Configuración de Base de Datos
 * "Gestión con sabiduría y cercanía"
 */

// Configuración de base de datos
$host = '35.184.194.170';
$user = 'jorgedb';
$pass = 'V52aK12E4EYEdAX3x1RoV7cu2ALACE';
$db   = 'OSF_PRO';
$port = 3306;

// Variable global para estado de conexión
$db_connection_status = 'unknown';

// Función para conectar a la base de datos con manejo de errores
function conectarDB() {
    global $host, $user, $pass, $db, $port, $db_connection_status;
    
    try {
        $mysqli = mysqli_init();
        if (!$mysqli) {
            throw new Exception("Error al inicializar MySQLi");
        }
        
        if (!mysqli_real_connect($mysqli, $host, $user, $pass, $db, $port)) {
            throw new Exception("Error de conexión: (" . mysqli_connect_errno() . ") " . mysqli_connect_error());
        }
        
        $mysqli->set_charset("utf8");
        $db_connection_status = 'connected';
        return $mysqli;
        
    } catch (Exception $e) {
        $db_connection_status = 'error';
        error_log("Sophía DB Error: " . $e->getMessage());
        return false;
    }
}

// Función para verificar estado de conexión
function verificarConexionDB() {
    global $db_connection_status;
    return $db_connection_status === 'connected';
}

// Función para ejecutar consultas con manejo de errores mejorado
function ejecutarConsulta($sql, $params = []) {
    $mysqli = conectarDB();
    
    if (!$mysqli) {
        return [
            'success' => false,
            'error' => 'No se pudo conectar a la base de datos',
            'data' => []
        ];
    }
    
    try {
        // Verificar que solo sean consultas SELECT
        $sql_trimmed = trim(strtoupper($sql));
        if (!str_starts_with($sql_trimmed, 'SELECT')) {
            throw new Exception("Solo se permiten consultas de lectura (SELECT)");
        }
        
        $stmt = $mysqli->prepare($sql);
        if (!$stmt) {
            throw new Exception("Error al preparar consulta: " . $mysqli->error);
        }
        
        // Bind parameters si existen
        if (!empty($params)) {
            $types = str_repeat('s', count($params));
            $stmt->bind_param($types, ...$params);
        }
        
        if (!$stmt->execute()) {
            throw new Exception("Error al ejecutar consulta: " . $stmt->error);
        }
        
        $result = $stmt->get_result();
        $data = [];
        
        while ($row = $result->fetch_assoc()) {
            $data[] = $row;
        }
        
        $stmt->close();
        mysqli_close($mysqli);
        
        return [
            'success' => true,
            'error' => null,
            'data' => $data
        ];
        
    } catch (Exception $e) {
        if (isset($stmt)) $stmt->close();
        mysqli_close($mysqli);
        error_log("Sophía Query Error: " . $e->getMessage());
        
        return [
            'success' => false,
            'error' => $e->getMessage(),
            'data' => []
        ];
    }
}

// Función para mostrar alerta de error de BD
function mostrarErrorBD($error = 'Error de conexión a la base de datos') {
    return '
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <i class="fas fa-exclamation-triangle me-2"></i>
        <strong>Sophía - Error de Conexión:</strong> ' . htmlspecialchars($error) . '
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>';
}

// Función para validar fechas
function validarFechas($fecha_inicio, $fecha_fin) {
    $fecha_inicio = filter_var($fecha_inicio, FILTER_SANITIZE_STRING);
    $fecha_fin = filter_var($fecha_fin, FILTER_SANITIZE_STRING);
    
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha_inicio) || 
        !preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha_fin)) {
        return false;
    }
    
    $inicio = strtotime($fecha_inicio);
    $fin = strtotime($fecha_fin);
    
    if ($inicio === false || $fin === false || $inicio > $fin) {
        return false;
    }
    
    return true;
}

// Función para sanitizar entrada
function sanitizarEntrada($input) {
    return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
}
?>
