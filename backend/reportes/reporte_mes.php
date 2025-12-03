<?php
include "../../conexion.php";
header("Content-Type: text/html; charset=utf-8");

// Obtener mes y año
$mes = (isset($_GET['mes']) ? intval($_GET['mes']) : (isset($mes) ? intval($mes) : 0));
$anio = (isset($_GET['anio']) ? intval($_GET['anio']) : (isset($anio) ? intval($anio) : 0));

if ($mes < 1 || $mes > 12) {
    $mes = intval(date('n'));
}
if ($anio < 1900 || $anio > 9999) {
    $anio = intval(date('Y'));
}

$mesName = date('F', mktime(0,0,0,$mes,1));

// Días miércoles (3) y sábados (6)
$days = [];
$days_count = cal_days_in_month(CAL_GREGORIAN, $mes, $anio);
for ($d = 1; $d <= $days_count; $d++) {
    $w = date('N', strtotime("$anio-$mes-$d"));
    if ($w == 3 || $w == 6) {
        $days[] = $d;
    }
}

// Traer jugadores activos y eliminados
$jug_res = $conexion->query("
    SELECT DISTINCT nombre, id 
    FROM (
        SELECT id, nombre FROM jugadores
        UNION ALL
        SELECT id, nombre FROM jugadores_eliminados
    ) AS t
    ORDER BY nombre ASC
");

$jugadores = [];
while ($r = $jug_res->fetch_assoc()) {
    $jugadores[] = $r;
}

/*  >>>>>>>>>>>>>>>>>>  ESTA LÍNEA ERA LA QUE FALTABA <<<<<<<<<<<<<<<<<<< */
$totalJugadores = count($jugadores);
/*  >>>>>>>>>>>>>>>>>>  SIN ESTO DABA ERROR <<<<<<<<<<<<<<<<<<< */

// --- FUNCIONES NECESARIAS ---
function get_aporte($conexion, $id_jugador, $fecha) {
    $stmt = $conexion->prepare("SELECT aporte_principal FROM aportes WHERE id_jugador=? AND fecha=?");
    $stmt->bind_param("is", $id_jugador, $fecha);
    $stmt->execute();
    $res = $stmt->get_result()->fetch_assoc();
    return $res ? intval($res['aporte_principal']) : 0;
}

function get_otros($conexion, $id_jugador, $mes, $anio) {
    $stmt = $conexion->prepare("
        SELECT tipo, valor 
        FROM otros_aportes 
        WHERE id_jugador=? AND mes=? AND anio=?
    ");
    $stmt->bind_param("iii", $id_jugador, $mes, $anio);
    $stmt->execute();
    $r = $stmt->get_result();
    $data = [];
    while ($row = $r->fetch_assoc()) {
        $data[] = $row;
    }
    return $data;
}

function get_obs($conexion, $mes, $anio) {
    $stmt = $conexion->prepare("SELECT texto FROM gastos_observaciones WHERE mes=? AND anio=? LIMIT 1");
    $stmt->bind_param("ii", $mes, $anio);
    $stmt->execute();
    $r = $stmt->get_result()->fetch_assoc();
    return $r["texto"] ?? "";
}

// Totales globales
$total_mes_global = 0;
$total_otros_global = 0;

// Totales por día (miércoles y sábado)
$totalesDias = [];
foreach ($days as $d) {
    $fecha = sprintf("%04d-%02d-%02d", $anio, $mes, $d);
    $q = $conexion->query("
        SELECT SUM(aporte_principal) AS total
        FROM aportes 
        WHERE fecha = '$fecha'
    ");
    $totalesDias[$d] = intval($q->fetch_assoc()['total'] ?? 0);
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Reporte mensual - <?= $mesName . " " . $anio ?></title>
</head>

<?php
$clase = "";
if ($totalJugadores > 30) $clase = "many-rows";
if ($totalJugadores > 40) $clase = "too-many-rows";
?>

<body class="<?= $clase ?>">

<br><br>

<div class="section-title">
    <strong>Aportantes y Aportes Diarios</strong>
</div>

<table>
    <thead>
        <tr>
            <th style="text-align:center;">Jugador</th>
            <?php foreach ($days as $d): ?>
                <th class="right"><?= $d ?></th>
            <?php endforeach; ?>
            <th class="right">Fecha especial</th>
            <th class="right">Otros (tipo / valor)</th>
            <th class="right">Total jugador</th>
        </tr>
    </thead>
    <tbody>
<?php
// Recorremos cada jugador
foreach ($jugadores as $jug) : 
?>
<tr>
    <td style="font-weight:lighter; font-size:12px;"><?= htmlspecialchars($jug['nombre']) ?></td>
    <?php
    $totalJugador = 0;

    // Aportes de días miércoles y sábado
    foreach ($days as $d) {
        $fecha = sprintf("%04d-%02d-%02d", $anio, $mes, $d);
        $ap = get_aporte($conexion, $jug['id'], $fecha);
        $totalJugador += $ap;
        echo "<td class='right-'>" . ($ap ? number_format($ap,0,',','.') : "") . "</td>";
    }

    // --- FECHA ESPECIAL (NO miércoles ni sábado) ---
    $totalEspecial = 0;
    for ($d = 1; $d <= $days_count; $d++) {
        $w = date('N', strtotime("$anio-$mes-$d"));
        if ($w != 3 && $w != 6) {
            $fecha = sprintf("%04d-%02d-%02d", $anio, $mes, $d);
            $ap = get_aporte($conexion, $jug['id'], $fecha);
            $totalEspecial += $ap;
        }
    }
    $totalJugador += $totalEspecial;
    echo "<td class='right-'>" . ($totalEspecial ? number_format($totalEspecial,0,',','.') : "") . "</td>";

    // Otros aportes
    $otros = get_otros($conexion, $jug['id'], $mes, $anio);
    $otros_html = [];
    $otros_val = 0;
    foreach ($otros as $o) {
        $otros_html[] = htmlspecialchars($o['tipo']) . " / " . number_format($o['valor'],0,',','.');
        $otros_val += intval($o['valor']);
    }
    $totalJugador += $otros_val;
    $total_mes_global += $totalJugador;
    $total_otros_global += $otros_val;
    ?>
    <td class="small"><?= $otros_html ? implode("<br>", $otros_html) : "" ?></td>
    <td style="text-align:center;"><strong><?= number_format($totalJugador,0,',','.') ?></strong></td>
</tr>
<?php endforeach; ?>

<tr style="background:#b4e7c7; color:black; font-weight:bold;">
    <td style="text-align:center;">Total por día</td>
    <?php foreach ($days as $d): ?>
        <td class="right"><?= number_format($totalesDias[$d],0,',','.') ?></td>
    <?php endforeach; ?>

    <?php
    // Total de fecha especial por todos
    $totalEspecialMes = 0;
    for ($d = 1; $d <= $days_count; $d++) {
        $w = date('N', strtotime("$anio-$mes-$d"));
        if ($w != 3 && $w != 6) {
            $q = $conexion->query("
                SELECT SUM(aporte_principal) AS s 
                FROM aportes 
                WHERE fecha = '".sprintf("%04d-%02d-%02d",$anio,$mes,$d)."'
            ");
            $totalEspecialMes += intval($q->fetch_assoc()['s'] ?? 0);
        }
    }
    ?>
    <td class="right"><strong><?= number_format($totalEspecialMes,0,',','.') ?></strong></td>
    <td></td>
    <td class="right"><strong><?= number_format($total_mes_global - $total_otros_global,0,',','.') ?></strong></td>
</tr>
</tbody>
</table>

<div class="page-break page-spacer"></div>

<div class="section-title"><strong>Otros aportes (resumen)</strong></div>

<!-- resto del archivo SIN CAMBIOS... -->

<table>
    <thead>
        <tr>
            <th>Jugador</th>
            <th>Tipo</th>
            <th class="right">Valor</th>
        </tr>
    </thead>
    <tbody>
        <?php
        $stmt = $conexion->prepare("
            SELECT j.nombre, o.tipo, SUM(o.valor) AS valor 
            FROM otros_aportes o 
            JOIN jugadores j ON j.id = o.id_jugador 
            WHERE o.mes=? AND o.anio=? 
            GROUP BY j.nombre, o.tipo 
            ORDER BY j.nombre
        ");
        $stmt->bind_param("ii", $mes, $anio);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            echo "<tr>
                    <td style='font-weight:lighter; font-size:13px;'>".htmlspecialchars($row['nombre'])."</td>
                    <td style='font-weight:lighter; font-size:13px;'>".htmlspecialchars($row['tipo'])."</td>
                    <td class='right'>".number_format($row['valor'],0,',','.')."</td>
                  </tr>";
        }
        ?>
    </tbody>
    <tfoot>
        <tr>
            <td colspan="2" class="right"><strong>Total otros:</strong></td>
            <td class="right"><strong><?= number_format($total_otros_global,0,',','.') ?></strong></td>
        </tr>
    </tfoot>
</table>

<div class="observaciones">
    <div class="observaciones-title"><strong>Observaciones / Gastos del mes</strong></div>
    <div class="obs"><?= nl2br(htmlspecialchars(get_obs($conexion,$mes,$anio))) ?></div>
</div>

<div class="total-box">
    <strong>Total ingresado en <?= htmlspecialchars($mesName . " " . $anio) ?>:</strong>
    <span style="float:right;"><strong><?= number_format($total_mes_global,0,',','.') ?></strong></span>
</div>

<?php
// --- TOTAL DEL AÑO HASTA LA FECHA ---
$totalAnual = 0;

// Determinar si el reporte es del mes actual
$mes_actual = intval(date("n"));
$anio_actual = intval(date("Y"));

$meses_a_considerar = ($anio == $anio_actual && $mes == $mes_actual) ? range(1,$mes) : range(1,12);

// Sumar todos los aportes principales + otros hasta el mes indicado
foreach ($meses_a_considerar as $m) {
    // Días miércoles y sábado
    $dias_mes = cal_days_in_month(CAL_GREGORIAN, $m, $anio);
    for ($d = 1; $d <= $dias_mes; $d++) {
        $fecha = sprintf("%04d-%02d-%02d", $anio, $m, $d);
        $q = $conexion->query("SELECT SUM(aporte_principal) AS s FROM aportes WHERE fecha='$fecha'");
        $totalAnual += intval($q->fetch_assoc()['s'] ?? 0);
    }
    // Otros aportes
    $q2 = $conexion->query("SELECT SUM(valor) AS s FROM otros_aportes WHERE mes=$m AND anio=$anio");
    $totalAnual += intval($q2->fetch_assoc()['s'] ?? 0);
}
?>
<div class="total-box" style="background:#f7d08a; padding:8px; margin-top:10px; border-radius:4px;">
    <strong>Total del año <?= $anio ?> hasta <?= date("d/m/Y") ?>:</strong>
    <span style="float:right;"><strong><?= number_format($totalAnual,0,',','.') ?></strong></span>
</div>

</body>
</html>
