<?php
include "../../conexion.php";
header("Content-Type: text/html; charset=utf-8");

// Obtener mes y año
$mes = (isset($_GET['mes']) ? intval($_GET['mes']) : (isset($mes) ? intval($mes) : 0));
$anio = (isset($_GET['anio']) ? intval($_GET['anio']) : (isset($anio) ? intval($anio) : 0));

if ($mes < 1 || $mes > 12) $mes = intval(date('n'));
if ($anio < 1900 || $anio > 9999) $anio = intval(date('Y'));

$mesName = date('F', mktime(0,0,0,$mes,1));

// --- Calcular días miércoles (3) y sábado (6)
$days = [];
$days_count = cal_days_in_month(CAL_GREGORIAN, $mes, $anio);
for ($d = 1; $d <= $days_count; $d++) {
    $w = date('N', strtotime("$anio-$mes-$d"));
    if ($w == 3 || $w == 6) $days[] = $d;
}

// --- Traer jugadores activos + eliminados
$jug_res = $conexion->query("
    SELECT id, nombre 
    FROM jugadores
    UNION ALL
    SELECT id, nombre
    FROM jugadores_eliminados
    ORDER BY nombre ASC
");

$jugadores = [];
while ($r = $jug_res->fetch_assoc()) $jugadores[] = $r;
$totalJugadores = count($jugadores);

// =====================================================
// CARGAR DEUDAS SOLO DEL MES ACTUAL → para mostrar puntos ●
// =====================================================
$deudas_mes_actual = [];

$stmtDeu2 = $conexion->prepare("
    SELECT id_jugador, fecha
    FROM deudas_aportes
    WHERE YEAR(fecha) = ? AND MONTH(fecha) = ?
");
$stmtDeu2->bind_param("ii", $anio, $mes);
$stmtDeu2->execute();
$resDeu2 = $stmtDeu2->get_result();

while ($row = $resDeu2->fetch_assoc()) {
    $pid = intval($row['id_jugador']);
    $dia = intval(date('j', strtotime($row['fecha'])));
    $deudas_mes_actual[$pid][$dia] = true;
}
$stmtDeu2->close();


// =====================================================
// TOTAL DE DEUDAS ACUMULADAS HASTA ESTE MES
// =====================================================
$deudas_totales = [];

$stmtTot = $conexion->prepare("
    SELECT id_jugador, COUNT(*) AS total
    FROM deudas_aportes
    WHERE 
        (YEAR(fecha) < ?)
        OR (YEAR(fecha) = ? AND MONTH(fecha) <= ?)
    GROUP BY id_jugador
");
$stmtTot->bind_param("iii", $anio, $anio, $mes);
$stmtTot->execute();
$resT = $stmtTot->get_result();

while ($rT = $resT->fetch_assoc()) {
    $deudas_totales[intval($rT['id_jugador'])] = intval($rT['total']);
}

$stmtTot->close();


// =========================
// FUNCIONES
// =========================

function get_aporte_real($conexion, $id_jugador, $fecha) {
    $stmt = $conexion->prepare("SELECT aporte_principal FROM aportes WHERE id_jugador=? AND fecha=? LIMIT 1");
    $stmt->bind_param("is", $id_jugador, $fecha);
    $stmt->execute();
    $res = $stmt->get_result()->fetch_assoc();
    return $res ? intval($res['aporte_principal']) : 0;
}

function get_otros($conexion, $id_jugador, $mes, $anio) {
    $stmt = $conexion->prepare("
        SELECT tipo, valor
        FROM otros_aportes
        WHERE id_jugador = ? AND mes = ? AND anio = ?
    ");
    $stmt->bind_param("iii", $id_jugador, $mes, $anio);
    $stmt->execute();
    $r = $stmt->get_result();

    $out = [];
    while ($row = $r->fetch_assoc()) $out[] = $row;
    return $out;
}

function get_saldo_acumulado($conexion, $id_jugador, $mes, $anio) {
    $stmt = $conexion->prepare("
        SELECT IFNULL(SUM(aporte_principal - LEAST(aporte_principal,2000)),0) AS excedente
        FROM aportes
        WHERE id_jugador = ?
          AND (
                YEAR(fecha) < ?
             OR (YEAR(fecha) = ? AND MONTH(fecha) <= ?)
          )
    ");
    $stmt->bind_param("iiii", $id_jugador, $anio, $anio, $mes);
    $stmt->execute();
    $res = $stmt->get_result()->fetch_assoc();
    return intval($res['excedente'] ?? 0);
}

function get_obs($conexion, $mes, $anio) {
    $stmt = $conexion->prepare("SELECT texto FROM gastos_observaciones WHERE mes=? AND anio=? LIMIT 1");
    $stmt->bind_param("ii", $mes, $anio);
    $stmt->execute();
    $r = $stmt->get_result()->fetch_assoc();
    return $r["texto"] ?? "";
}


// =========================
// TOTALES POR DÍA
// =========================

$totalesDias = [];
foreach ($days as $d) {
    $fecha = sprintf("%04d-%02d-%02d", $anio, $mes, $d);

    $q = $conexion->query("
        SELECT SUM(LEAST(aporte_principal,2000)) AS total
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
    <th>Jugador</th>
    <?php foreach ($days as $d): ?>
        <th class="right"><?= $d ?></th>
    <?php endforeach; ?>
    <th class="right">Fecha especial</th>
    <th class="right">Otros</th>
    <th class="right">Total jugador</th>
    <th class="right">Saldo</th>
    <th class="right">Deuda</th>
</tr>
</thead>

<tbody>

<?php
$total_mes_global = 0;
$total_saldo_global = 0;
$total_otros_global = 0;

foreach ($jugadores as $jug):

    $totalJugador = 0;
    $totalSaldoJugador = 0;

?>
<tr>
    <td><?= htmlspecialchars($jug['nombre']) ?></td>

    <?php foreach ($days as $d): ?>
    <?php
        $fecha = sprintf("%04d-%02d-%02d", $anio, $mes, $d);
        $real = get_aporte_real($conexion, $jug['id'], $fecha);
        $tope = min($real, 2000);

        $totalJugador += $tope;

        $tieneDeudaDia = isset($deudas_mes_actual[$jug['id']][$d]);
    ?>
        <td class="right">
            <?= $tope ? number_format($tope,0,',','.') : "" ?>
            <?php if ($tieneDeudaDia): ?>
                <br><span style="color:#d00; font-size:9pt;">●</span>
            <?php endif; ?>
        </td>
    <?php endforeach; ?>


    <?php
    // FECHA ESPECIAL (28)
    $fechaEspecialTotal = 0;
    $tieneDeudaEspecial = false;

    foreach ($deudas_mes_actual[$jug['id']] ?? [] as $diaDeuda => $v) {
        $w = date('N', strtotime("$anio-$mes-$diaDeuda"));
        if ($w != 3 && $w != 6) {
            $tieneDeudaEspecial = true;
        }
    }

    for ($d = 1; $d <= $days_count; $d++) {
        $w = date('N', strtotime("$anio-$mes-$d"));
        if ($w != 3 && $w != 6) {
            $fecha = sprintf("%04d-%02d-%02d", $anio, $mes, $d);
            $real = get_aporte_real($conexion, $jug['id'], $fecha);
            $fechaEspecialTotal += min($real, 2000);
        }
    }

    $totalJugador += $fechaEspecialTotal;
    ?>

    <td class="right">
        <?= $fechaEspecialTotal ? number_format($fechaEspecialTotal,0,',','.') : "" ?>
        <?php if ($tieneDeudaEspecial): ?>
            <br><span style="color:#d00; font-size:9pt;">●</span>
        <?php endif; ?>
    </td>

    <?php
    // OTROS APORTES
    $otros = get_otros($conexion, $jug['id'], $mes, $anio);
    $otros_html = [];
    $otros_val = 0;

    foreach ($otros as $o) {
        $otros_html[] = htmlspecialchars($o['tipo']." (".number_format($o['valor'],0,',','.').")");
        $otros_val += intval($o['valor']);
    }
    $totalJugador += $otros_val;
    $total_otros_global += $otros_val;
    ?>

    <td class="small"><?= implode("<br>", $otros_html) ?></td>

    <td class="right"><strong><?= number_format($totalJugador,0,',','.') ?></strong></td>

    <?php
    $saldoAcumulado = get_saldo_acumulado($conexion, $jug['id'], $mes, $anio);
    ?>

    <td class="right"><strong><?= number_format($saldoAcumulado,0,',','.') ?></strong></td>

    <?php
    // TOTAL DEUDAS ACUMULADAS
    $diasDeuda = $deudas_totales[$jug['id']] ?? 0;
    $textoDeuda = $diasDeuda > 0 
        ? "Debe {$diasDeuda} día".($diasDeuda>1?"s":"")
        : "Sin deuda";
    ?>

    <td class="right"><?= $textoDeuda ?></td>

</tr>

<?php endforeach; ?>


</tbody>
</table>

</body>
</html>
