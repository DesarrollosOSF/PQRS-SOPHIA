<?php

require_once '../config/database.php';
require_once '../config/pqrs_helper.php';

// RECIBIR FILTROS

$filtro_estado = $_GET['estado'] ?? '';
$filtro_prioridad = $_GET['prioridad'] ?? '';
$filtro_vencidas = $_GET['vencidas'] ?? '';
$filtro_usuario = $_GET['usuario'] ?? '';
$filtro_fecha_inicio = $_GET['fecha_inicio'] ?? '';
$filtro_fecha_fin = $_GET['fecha_fin'] ?? '';

// FECHAS POR DEFECTO

$fecha_inicio_default = date('Y-m-d', strtotime('-2 months'));
$fecha_fin_default = date('Y-m-d');

$fecha_inicio = $filtro_fecha_inicio ?: $fecha_inicio_default;
$fecha_fin = $filtro_fecha_fin ?: $fecha_fin_default;

// CONSULTA PRINCIPAL

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

    DATE_ADD(
        DATE(tp.creation),
        INTERVAL 15 DAY
    ) AS Fecha_Maxima_Respuesta,

    DATEDIFF(
        DATE_ADD(DATE(tp.creation), INTERVAL 15 DAY),
        CURDATE()
    ) AS Dias_Restantes,

    tp.tipo_peticion,
    tp.estado,
    tp.custom_prioridad,
    tp.descripcion_queja,
    tp.owner AS Creado_Por,
    tp.modified_by AS Usuario_Ultima_Modificacion,
    tp.modified AS Fecha_Ultima_Modificacion

FROM `tabPQRFS` AS tp

WHERE DATE(tp.creation) BETWEEN ? AND ?
";

// PARÁMETROS

$params = [
    $fecha_inicio,
    $fecha_fin
];

// FILTRO ESTADO

if ($filtro_estado) {

    if ($filtro_estado === 'Borrador') {
        $sql_pqrs .= " AND tp.docstatus = 0";
    } elseif ($filtro_estado === 'Validado') {
        $sql_pqrs .= " AND tp.docstatus = 1";
    } elseif ($filtro_estado === 'Cancelado') {
        $sql_pqrs .= " AND tp.docstatus = 2";
    }
}

// FILTRO PRIORIDAD

if ($filtro_prioridad) {
    $sql_pqrs .= " AND tp.custom_prioridad = ?";
    $params[] = $filtro_prioridad;
}

// FILTRO VENCIDAS

if ($filtro_vencidas === 'si') {
    $sql_pqrs .= "
        AND DATEDIFF(
            DATE_ADD(DATE(tp.creation), INTERVAL 15 DAY),
            CURDATE()
        ) < 0
    ";
}

// FILTRO USUARIO

if ($filtro_usuario) {
    $sql_pqrs .= " AND tp.`owner` = ?";
    $params[] = $filtro_usuario;
}

// ORDENAMIENTO

$sql_pqrs .= "
ORDER BY 
    CASE 
        WHEN tp.docstatus = 0 THEN 1
        WHEN tp.docstatus = 1 THEN 2
        WHEN tp.docstatus = 2 THEN 3
        ELSE 4
    END,
    CASE 
        WHEN tp.docstatus = 0 THEN
            DATEDIFF(
                DATE_ADD(DATE(tp.creation), INTERVAL 15 DAY),
                CURDATE()
            )
        ELSE tp.creation
    END ASC,
    tp.creation DESC
";

// EJECUTAR CONSULTA

$mysqli = conectarDB();
$stmt = $mysqli->prepare($sql_pqrs);

if (!$stmt) {
    die('Error preparando la consulta: ' . $mysqli->error);
}

$types = str_repeat('s', count($params));
$stmt->bind_param($types, ...$params);
$stmt->execute();
$result = $stmt->get_result();

// GUARDAR DATOS

$pqrs_data = [];
while ($row = $result->fetch_assoc()) {

    $pqrs_data[] = $row;
}

$stmt->close();
$mysqli->close();

// PROCESAR DÍAS HÁBILES

$pqrs_data = procesarPQRSConDiasHabiles($pqrs_data);
$pqrs_data = ordenarPQRSPorUrgencia($pqrs_data);

// CALCULAR INDICADORES

$total_pqrs = count($pqrs_data);
$vencidas = array_filter($pqrs_data, function ($pqr) {
    return
        $pqr['Estado_Documento'] === 'Borrador' &&
        $pqr['Dias_Habiles_Restantes'] < 0;
});

$por_vencer = array_filter($pqrs_data, function ($pqr) {
    return
        $pqr['Estado_Documento'] === 'Borrador' &&
        $pqr['Dias_Habiles_Restantes'] >= 0 &&
        $pqr['Dias_Habiles_Restantes'] <= 3;
});

$borrador = array_filter($pqrs_data, function ($pqr) {
    return $pqr['Estado_Documento'] === 'Borrador';
});

$total_vencidas = count($vencidas);
$total_por_vencer = count($por_vencer);
$total_borrador = count($borrador);

// NOMBRE DEL ARCHIVO

$nombre_archivo = 'Reporte_PQRS_' . date('Y-m-d_H-i-s') . '.xls';

// CABECERAS PARA DESCARGAR EXCEL

header('Content-Type: application/vnd.ms-excel; charset=UTF-8');

header('Content-Disposition: attachment; filename="' . $nombre_archivo . '"');

header('Pragma: no-cache');
header('Expires: 0');

// INICIO DEL ARCHIVO EXCEL

echo "\xEF\xBB\xBF";

?>

<html>

<head>
    <meta charset="UTF-8">
    <style>
        body {
            font-family: Arial, sans-serif;
        }

        .titulo {
            font-size: 20px;
            font-weight: bold;
            text-align: center;
            background-color: #1f4e78;
            color: white;
            padding: 10px;
        }

        .subtitulo {
            font-size: 13px;
            text-align: center;
            padding: 8px;
        }

        .indicadores {
            font-weight: bold;
            background-color: #d9eaf7;
        }

        .encabezado {
            background-color: #1f4e78;
            color: white;
            font-weight: bold;
            text-align: center;
        }

        .vencida {
            background-color: #f4cccc;
        }

        .riesgo {
            background-color: #fff2cc;
        }

        .normal {
            background-color: white;
        }

        table {
            border-collapse: collapse;
            width: 100%;
        }

        th,
        td {
            border: 1px solid #999999;
            padding: 6px;
        }

        .descripcion {
            width: 500px;
        }
    </style>

</head>

<body>

    <!-- TÍTULO -->

    <table>

        <tr>
            <td colspan="12" class="titulo">
                REPORTE DE GESTIÓN DE PQRS
            </td>
        </tr>

        <tr>
            <td colspan="12" class="subtitulo">
                Período:
                <?php echo date('d/m/Y', strtotime($fecha_inicio)); ?>
                al
                <?php echo date('d/m/Y', strtotime($fecha_fin)); ?>
            </td>
        </tr>
    </table>

    <br>

    <!-- FILTROS -->

    <table>
        <tr>
            <th colspan="12" class="indicadores">
                FILTROS APLICADOS
            </th>
        </tr>

        <tr>
            <td>
                <strong>Estado:</strong>
            </td>

            <td>
                <?php echo htmlspecialchars($filtro_estado ?: 'Todos'); ?>
            </td>

            <td>
                <strong>Prioridad:</strong>
            </td>

            <td>
                <?php echo htmlspecialchars($filtro_prioridad ?: 'Todas'); ?>
            </td>

            <td>
                <strong>Vencimiento:</strong>
            </td>

            <td>
                <?php
                echo $filtro_vencidas === 'si' ? 'Solo vencidas' : 'Todas';
                ?>
            </td>

            <td>
                <strong>Usuario:</strong>
            </td>

            <td colspan="3">
                <?php echo htmlspecialchars($filtro_usuario ?: 'Todos'); ?>
            </td>

        </tr>

    </table>

    <br>

    <!-- INDICADORES -->

    <table>

        <tr>
            <th colspan="4" class="indicadores">
                INDICADORES DE GESTIÓN
            </th>
        </tr>

        <tr class="encabezado">

            <th>Total PQRS</th>
            <th>PQRS Vencidas</th>
            <th>En Riesgo</th>
            <th>Borradores Activas</th>

        </tr>

        <tr>

            <td style="text-align:center;">
                <?php echo $total_pqrs; ?>
            </td>
            <td style="text-align:center;">
                <?php echo $total_vencidas; ?>
            </td>
            <td style="text-align:center;">
                <?php echo $total_por_vencer; ?>
            </td>
            <td style="text-align:center;">
                <?php echo $total_borrador; ?>
            </td>

        </tr>

    </table>


    <br>

    <!-- DETALLE -->

    <table>

        <tr>

            <th colspan="12" class="indicadores">
                DETALLE DE PQRS
            </th>

        </tr>

        <tr class="encabezado">
            <th>ID</th>
            <th>Estado Documento</th>
            <th>Fecha Creación</th>
            <th>Fecha Máxima Respuesta</th>
            <th>Días Hábiles Restantes</th>
            <th>Tipo de Petición</th>
            <th>Prioridad</th>
            <th class="descripcion">
                Descripción
            </th>
            <th>Creado Por</th>
            <th>Usuario Última Modificación</th>
            <th>Última Modificación</th>
            <th>Estado PQRS</th>
        </tr>


        <?php foreach ($pqrs_data as $pqr): ?>
            <?php
            $clase = 'normal';
            if ($pqr['Estado_Documento'] === 'Borrador' && $pqr['Dias_Habiles_Restantes'] < 0) {
                $clase = 'vencida';
            } elseif ($pqr['Estado_Documento'] === 'Borrador' && $pqr['Dias_Habiles_Restantes'] <= 3) {
                $clase = 'riesgo';
            }
            ?>

            <tr class="<?php echo $clase; ?>">
                <td>
                    <?php
                    echo htmlspecialchars($pqr['name']);
                    ?>
                </td>
                <td>
                    <?php
                    echo htmlspecialchars($pqr['Estado_Documento']);
                    ?>
                </td>
                <td>
                    <?php
                    echo date('d/m/Y', strtotime($pqr['Fecha_Creacion']));
                    ?>
                </td>
                <td>
                    <?php
                    echo date('d/m/Y', strtotime($pqr['Fecha_Maxima_Respuesta_Habiles']));
                    ?>
                </td>
                <td style="text-align:center;">
                    <?php
                    echo $pqr['Dias_Habiles_Restantes'];
                    ?>
                </td>
                <td>
                    <?php
                    echo htmlspecialchars($pqr['tipo_peticion'] ?? '');
                    ?>
                </td>
                <td>
                    <?php
                    echo htmlspecialchars($pqr['custom_prioridad'] ?? 'Media');
                    ?>
                </td>
                <td>
                    <?php echo htmlspecialchars($pqr['descripcion_queja'] ?? '');
                    ?>
                </td>
                <td>
                    <?php
                    echo htmlspecialchars( $pqr['Creado_Por'] ?? '' );
                    ?>
                </td>

                <td>
                    <?php
                    echo htmlspecialchars( $pqr['Usuario_Ultima_Modificacion'] ?? '' );
                    ?>
                </td>

                <td>
                    <?php
                    if (!empty($pqr['Fecha_Ultima_Modificacion'])) {

                        echo date( 'd/m/Y H:i', strtotime( $pqr['Fecha_Ultima_Modificacion']));
                    } else {
                        echo 'Sin modificaciones';
                    }
                    ?>
                </td>
                <td>

                    <?php
                    echo htmlspecialchars( $pqr['estado'] ?? '' );
                    ?>

                </td>
            </tr>
        <?php endforeach; ?>
    </table>

    <br>

    <table>

        <tr>
            <td colspan="12">
                <strong> Fecha de generación: </strong>
                <?php
                echo date('d/m/Y H:i:s');
                ?>
            </td>
        </tr>
    </table>

</body>

</html>

<?php

exit;
