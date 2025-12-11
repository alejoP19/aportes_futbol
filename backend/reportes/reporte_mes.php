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

// =========================
// MAPA DE DEUDAS (tabla deudas)
// =========================
$deudas_map = []; // $deudas_map[id_jugador][dia] = true

$stmtDeu = $conexion->query("
    SELECT id_jugador, fecha
    FROM deudas_aportes
");

while ($row = $stmtDeu->fetch_assoc()) {
    $pid = intval($row['id_jugador']);
    $dia = intval(date('j', strtotime($row['fecha'])));
    $deudas_map[$pid][$dia] = true;
}

$stmtDeu->close();

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

// ======================================================
// SALDO ACUMULADO HASTA ESTE MES / AÑO
// ======================================================
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
    $stmt->close();

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
// TOTALES POR DÍA (APORTES TOPE 2000)
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
    <th class="right">Otros (tipo / valor)</th>
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

    <?php
    // DÍAS NORMALES (tope 2000)
    foreach ($days as $d):
        $fecha = sprintf("%04d-%02d-%02d", $anio, $mes, $d);
        $real = get_aporte_real($conexion, $jug['id'], $fecha);
        $tope = min($real, 2000);
        $exceso = max(0, $real - 2000);

        $totalJugador += $tope;
        $totalSaldoJugador += $exceso;

        $tieneDeudaDia = isset($deudas_map[$jug['id']][$d]);
    ?>
        <td class="right">
            <?= $tope ? number_format($tope,0,',','.') : "" ?>
            <?php if ($tieneDeudaDia): ?>
                <br><span style="color:#d00; font-size:9pt;">●</span>
            <?php endif; ?>
        </td>
    <?php endforeach; ?>

    <?php
    // FECHA ESPECIAL = cualquier día NO miércoles/sábado
    $fechaEspecialTotal = 0;
    $fechaEspecialSaldo = 0;
    $tieneDeudaEspecial = false;

    for ($d = 1; $d <= $days_count; $d++) {
        $w = date('N', strtotime("$anio-$mes-$d"));
        if ($w != 3 && $w != 6) {
            $fecha = sprintf("%04d-%02d-%02d", $anio, $mes, $d);
            $real = get_aporte_real($conexion, $jug['id'], $fecha);
            $tope = min($real, 2000);
            $exceso = max(0, $real - 2000);

            $fechaEspecialTotal += $tope;
            $fechaEspecialSaldo += $exceso;

            if (isset($deudas_map[$jug['id']][$d])) {
                $tieneDeudaEspecial = true;
            }
        }
    }

    $totalJugador += $fechaEspecialTotal;
    $totalSaldoJugador += $fechaEspecialSaldo;
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
        $otros_html[] = htmlspecialchars($o['tipo']) . " (" . number_format($o['valor'],0,',','.') . ")";
        $otros_val += intval($o['valor']);
    }

    $totalJugador += $otros_val;
    $total_otros_global += $otros_val;
    ?>
    <td class="small"><?= implode("<br>", $otros_html) ?></td>
    <td class="right"><strong><?= number_format($totalJugador,0,',','.') ?></strong></td>

    <?php
    // SALDO ACUMULADO HASTA ESTE MES (NO SOLO EL MES)
    $saldoAcumulado = get_saldo_acumulado($conexion, $jug['id'], $mes, $anio);
    ?>
    <td class="right"><strong><?= number_format($saldoAcumulado,0,',','.') ?></strong></td>
    <?php
// CALCULAR DÍAS ADEUDADOS DEL JUGADOR
$diasDeuda = isset($deudas_map[$jug['id']]) 
             ? count($deudas_map[$jug['id']]) 
             : 0;

if ($diasDeuda > 0) {
    $textoDeuda = "Debe {$diasDeuda} día" . ($diasDeuda > 1 ? "s" : "");
} else {
    $textoDeuda = "Sin deuda";
}
?>
<td class="right">
    <?= $textoDeuda ?>
</td>


</tr>

<?php
  // Para totales globales del PDF
// Para totales globales del PDF
$total_mes_global += ($totalJugador + $totalSaldoJugador);
$total_saldo_global += $totalSaldoJugador;

endforeach;
?>


<tr style="background:#b4e7c7; font-weight:bold;">
    <td>Total por día</td>

    <?php foreach ($totalesDias as $t): ?>
        <td class="right"><?= number_format($t,0,',','.') ?></td>
    <?php endforeach; ?>

    <?php
    // Total FECHA ESPECIAL
    $q = $conexion->query("
        SELECT SUM(LEAST(aporte_principal,2000)) AS s
        FROM aportes
        WHERE MONTH(fecha)=$mes AND YEAR(fecha)=$anio
        AND DAYOFWEEK(fecha) NOT IN (4,7)
    ");
    $totEspecial = intval($q->fetch_assoc()['s'] ?? 0);
    ?>
    <td class="right"><?= number_format($totEspecial,0,',','.') ?></td>

    <td></td>

    <td class="right"><?= number_format($total_mes_global,0,',','.') ?></td>
    <td class="right"><?= number_format($total_saldo_global,0,',','.') ?></td>
</tr>


</tbody>
</table>

<div class="page-break"></div>

<!-- ================== -->
<!--     OTROS APORTES  -->
<!-- ================== -->

<div class="section-title"><strong>Otros aportes (resumen)</strong></div>

<table class="table-otros">
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
    SELECT j.nombre, o.tipo, o.valor
    FROM otros_aportes o
    JOIN jugadores j ON j.id = o.id_jugador
    WHERE o.mes=? AND o.anio=?
    ORDER BY j.nombre
");
$stmt->bind_param("ii", $mes, $anio);
$stmt->execute();
$res = $stmt->get_result();

while ($row = $res->fetch_assoc()):
?>
<tr>
    <td><?= htmlspecialchars($row['nombre']) ?></td>
    <td><?= htmlspecialchars($row['tipo']) ?></td>
    <td class="right"><?= number_format($row['valor'],0,',','.') ?></td>
</tr>
<?php endwhile; ?>

</tbody>

<tfoot>
<tr>
    <td colspan="2" class="right"><strong>Total otros:</strong></td>
    <td class="right"><strong><?= number_format($total_otros_global,0,',','.') ?></strong></td>
</tr>
</tfoot>
</table>


<!-- ================== -->
<!--   GASTOS DEL MES   -->
<!-- ================== -->

<?php
$stmtG = $conexion->prepare("
    SELECT nombre AS concepto, valor
    FROM gastos
    WHERE mes = ? AND anio = ?
    ORDER BY nombre ASC
");
$stmtG->bind_param("ii", $mes, $anio);
$stmtG->execute();
$resG = $stmtG->get_result();

$gastosListado = [];
$gastosTotal = 0;

while ($g = $resG->fetch_assoc()) {
    $gastosListado[] = $g;
    $gastosTotal += intval($g['valor']);
}

if (!empty($gastosListado)):
?>

<div class="section-title"><strong>Gastos del mes</strong></div>

<table class="gastos-table">
<thead>
<tr>
    <th>Concepto</th>
    <th class="right">Valor</th>
</tr>
</thead>

<tbody>
<?php foreach ($gastosListado as $g): ?>
<tr>
    <td><?= htmlspecialchars($g['concepto']) ?></td>
    <td class="right"><?= number_format($g['valor'], 0, ',', '.') ?></td>
</tr>
<?php endforeach; ?>
</tbody>

<tfoot>
<tr>
    <td class="right"><strong>Total gastos:</strong></td>
    <td class="right"><strong><?= number_format($gastosTotal, 0, ',', '.') ?></strong></td>
</tr>
</tfoot>
</table>

<?php else: ?>

<div class="section-title"><strong>Gastos del mes</strong></div>
<p style="font-size:14px;">No se registraron gastos en este mes.</p>

<?php endif; ?>

<!-- ================== -->
<!--  TOTAL DEL MES     -->
<!-- ================== -->

<?php
// TOTAL APORTES DEL MES - RESTANDO GASTOS DEL MES
$qG = $conexion->query("SELECT SUM(valor) AS s FROM gastos WHERE mes=$mes AND anio=$anio");
$gastosMes = intval($qG->fetch_assoc()['s'] ?? 0);

$totalMesFinal = $total_mes_global - $gastosMes;
?>

<div class="total-box">
    <strong>Total ingresado en <?= htmlspecialchars($mesName." ".$anio) ?>:</strong>
    <span style="float:right;"><strong><?= number_format($totalMesFinal,0,',','.') ?></strong></span>
</div>

<!-- ================== -->
<!--  TOTAL DEL AÑO     -->
<!-- ================== -->

<?php
$totalAnual = 0;

for ($m = 1; $m <= $mes; $m++) {

    // Aportes principales
    $diasm = cal_days_in_month(CAL_GREGORIAN, $m, $anio);

    for ($d = 1; $d <= $diasm; $d++) {
        $fecha = sprintf("%04d-%02d-%02d", $anio, $m, $d);
        $q = $conexion->query("SELECT SUM(aporte_principal) AS s FROM aportes WHERE fecha='$fecha'");
        $totalAnual += intval($q->fetch_assoc()['s'] ?? 0);
    }

    // Otros aportes
    $q2 = $conexion->query("SELECT SUM(valor) AS s FROM otros_aportes WHERE mes=$m AND anio=$anio");
    $totalAnual += intval($q2->fetch_assoc()['s'] ?? 0);

    // Gastos (SE RESTAN)
    $q3 = $conexion->query("SELECT SUM(valor) AS s FROM gastos WHERE mes=$m AND anio=$anio");
    $totalAnual -= intval($q3->fetch_assoc()['s'] ?? 0);
}
?>

<div class="total-box" style="background:#f7d08a;">
    <strong>Total del año <?= $anio ?> hasta <?= date("d/m/Y") ?>:</strong>
    <span style="float:right;"><strong><?= number_format($totalAnual,0,',','.') ?></strong></span>
</div>
<!-- ================== -->
<!--   OBSERVACIONES     -->
<!-- ================== -->

<div class="observaciones">
    <div class="observaciones-title"><strong>Observaciones del Mes</strong></div>
    <div class="obs"><?= nl2br(htmlspecialchars(get_obs($conexion,$mes,$anio))) ?></div>
</div>
</body>
</html>
