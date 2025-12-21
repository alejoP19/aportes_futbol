<?php
include "../../conexion.php";
header("Content-Type: text/html; charset=utf-8");

// =========================
// MES / AÑO
// =========================
$mes  = intval($_GET['mes']  ?? date('n'));
$anio = intval($_GET['anio'] ?? date('Y'));

if ($mes < 1 || $mes > 12) $mes = date('n');
if ($anio < 1900) $anio = date('Y');

$mesName = date('F', mktime(0,0,0,$mes,1));

// =========================
// DÍAS MIÉRCOLES / SÁBADO
// =========================
$days = [];
$days_count = cal_days_in_month(CAL_GREGORIAN, $mes, $anio);
for ($d = 1; $d <= $days_count; $d++) {
    $w = date('N', strtotime("$anio-$mes-$d"));
    if ($w == 3 || $w == 6) $days[] = $d;
}

// =========================
// JUGADORES
// =========================
$jug_res = $conexion->query("
    SELECT id, nombre FROM jugadores
    UNION ALL
    SELECT id, nombre FROM jugadores_eliminados
    ORDER BY nombre ASC
");

$jugadores = [];
while ($r = $jug_res->fetch_assoc()) $jugadores[] = $r;
$totalJugadores = count($jugadores);

// =========================
// FUNCIONES
// =========================
function get_aporte_real($conexion, $id, $fecha) {
    $q = $conexion->prepare("SELECT aporte_principal FROM aportes WHERE id_jugador=? AND fecha=? LIMIT 1");
    $q->bind_param("is", $id, $fecha);
    $q->execute();
    $r = $q->get_result()->fetch_assoc();
    return $r ? intval($r['aporte_principal']) : 0;
}

function get_otros($conexion, $id, $mes, $anio) {
    $q = $conexion->prepare("SELECT tipo, valor FROM otros_aportes WHERE id_jugador=? AND mes=? AND anio=?");
    $q->bind_param("iii", $id, $mes, $anio);
    $q->execute();
    $res = $q->get_result();
    $out = [];
    while ($r = $res->fetch_assoc()) $out[] = $r;
    return $out;
}

function get_saldo_hasta_mes($conexion, $id, $mes, $anio) {
    $fechaCorte = date('Y-m-t', strtotime("$anio-$mes-01"));

    $q1 = $conexion->prepare("
        SELECT IFNULL(SUM(aporte_principal - 2000),0)
        FROM aportes
        WHERE id_jugador=? AND aporte_principal>2000 AND fecha<=?
    ");
    $q1->bind_param("is", $id, $fechaCorte);
    $q1->execute();
    $excedente = intval($q1->get_result()->fetch_row()[0]);

    $q2 = $conexion->prepare("
        SELECT IFNULL(SUM(amount),0)
        FROM aportes_saldo_moves
        WHERE id_jugador=? AND fecha_consumo<=?
    ");
    $q2->bind_param("is", $id, $fechaCorte);
    $q2->execute();
    $consumido = intval($q2->get_result()->fetch_row()[0]);

    return max(0, $excedente - $consumido);
}

function get_obs($conexion, $mes, $anio) {
    $q = $conexion->prepare("SELECT texto FROM gastos_observaciones WHERE mes=? AND anio=? LIMIT 1");
    $q->bind_param("ii", $mes, $anio);
    $q->execute();
    $r = $q->get_result()->fetch_assoc();
    return $r['texto'] ?? "";
}

// =========================
// DEUDAS
// =========================
$deudas_mes = [];
$res = $conexion->query("
    SELECT id_jugador, fecha
    FROM deudas_aportes
    WHERE YEAR(fecha)=$anio AND MONTH(fecha)=$mes
");
while ($r = $res->fetch_assoc()) {
    $deudas_mes[$r['id_jugador']][intval(date('j',strtotime($r['fecha'])))] = true;
}

$deudas_totales = [];
$res = $conexion->query("
    SELECT id_jugador, COUNT(*) c
    FROM deudas_aportes
    WHERE YEAR(fecha)<$anio OR (YEAR(fecha)=$anio AND MONTH(fecha)<=$mes)
    GROUP BY id_jugador
");
while ($r = $res->fetch_assoc()) {
    $deudas_totales[$r['id_jugador']] = intval($r['c']);
}

// =========================
// SALDOS TOTALES
// =========================
$saldo_total_mes  = 0;
$saldo_total_anio = 0;

foreach ($jugadores as $j) {
    $s = get_saldo_hasta_mes($conexion, $j['id'], $mes, $anio);
    $saldo_total_mes  += $s;
    $saldo_total_anio += $s;
}

// =========================
// TOTALES BASE
// =========================
$total_mes_base = intval($conexion->query("
    SELECT IFNULL(SUM(LEAST(aporte_principal,2000)),0)
    FROM aportes WHERE YEAR(fecha)=$anio AND MONTH(fecha)=$mes
")->fetch_row()[0]);

$total_anio_base = intval($conexion->query("
    SELECT IFNULL(SUM(LEAST(aporte_principal,2000)),0)
    FROM aportes WHERE YEAR(fecha)<$anio OR (YEAR(fecha)=$anio AND MONTH(fecha)<=$mes)
")->fetch_row()[0]);

$otros_mes = intval($conexion->query("
    SELECT IFNULL(SUM(valor),0) FROM otros_aportes WHERE mes=$mes AND anio=$anio
")->fetch_row()[0]);

$otros_anio = intval($conexion->query("
    SELECT IFNULL(SUM(valor),0) FROM otros_aportes WHERE anio=$anio AND mes<=$mes
")->fetch_row()[0]);

$gasto_mes = intval($conexion->query("
    SELECT IFNULL(SUM(valor),0) FROM gastos WHERE mes=$mes AND anio=$anio
")->fetch_row()[0]);

$gasto_anio = intval($conexion->query("
    SELECT IFNULL(SUM(valor),0) FROM gastos WHERE anio=$anio AND mes<=$mes
")->fetch_row()[0]);

// =========================
// TOTALES (SIN SUMAR SALDOS)
// =========================
$total_mes_real  = $total_mes_base  + $otros_mes  - $gasto_mes;
$total_anio_real = $total_anio_base + $otros_anio - $gasto_anio;

// SALDO TOTAL DEL MES (SE MUESTRA APARTE)
$saldo_mes = $saldo_total_mes;
$saldo_mes  = $total_mes_real;
$saldo_anio = $total_anio_real;

$obs = trim(get_obs($conexion, $mes, $anio));
?>

<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<title>Reporte mensual <?= $mesName ?> <?= $anio ?></title>
</head>

<body>

<h3>Aportantes y Aportes</h3>

<table border="1" width="100%" cellspacing="0" cellpadding="4">
<thead>
<tr>
<th>Jugador</th>
<?php foreach ($days as $d): ?><th><?= $d ?></th><?php endforeach; ?>
<th>Especial</th><th>Otros</th><th>Total</th><th>Saldo</th><th>Deuda</th>
</tr>
</thead>
<tbody>

<?php foreach ($jugadores as $j): ?>
<tr>
<td><?= htmlspecialchars($j['nombre']) ?></td>

<?php
$totalJugador = 0;
foreach ($days as $d):
  $f = sprintf("%04d-%02d-%02d", $anio, $mes, $d);
$r = get_aporte_real($conexion, $j['id'], $f);
    $v = min($r,2000);
    $totalJugador += $v;

    // 🔴 CORRECCIÓN: detectar deuda del día
    $hayDeudaDia = isset($deudas_mes[$j['id']][$d]);
?>
<td>
    <?= $v ?: "" ?>
    <?php if ($hayDeudaDia): ?>
        <br><span style="color:#d00; font-size:9pt;">●</span>
    <?php endif; ?>
</td>
<?php endforeach; ?>

<?php
// 🔴 detectar deuda en fecha especial (día NO miércoles ni sábado)
$hayDeudaEspecial = false;

if (isset($deudas_mes[$j['id']])) {
    foreach ($deudas_mes[$j['id']] as $dia => $v) {
        $w = date('N', strtotime("$anio-$mes-$dia"));
        if ($w != 3 && $w != 6) {
            $hayDeudaEspecial = true;
            break;
        }
    }
}
?>
<td>
    <?php if ($hayDeudaEspecial): ?>
        <span style="color:#d00; font-size:9pt;">●</span>
    <?php endif; ?>
</td>


<?php
$otros = get_otros($conexion, $j['id'], $mes, $anio);
$otros_val = array_sum(array_column($otros,'valor'));
$totalJugador += $otros_val;
?>

<td><?= $otros_val ?: "" ?></td>
<td><strong><?= number_format($totalJugador,0,',','.') ?></strong></td>
<td><?= number_format(get_saldo_hasta_mes($conexion,$j['id'],$mes,$anio),0,',','.') ?></td>
<td><?= ($deudas_totales[$j['id']] ?? 0) ? "Debe {$deudas_totales[$j['id']]} días" : "" ?></td>
</tr>
<?php endforeach; ?>

</tbody>
</table>

<br>



<h3>Resumen General</h3>
<table border="1" width="60%" cellspacing="0" cellpadding="4">
  <tr><td><strong>Total del mes</strong></td><td>$ <?= number_format($total_mes_real,0,',','.') ?></td></tr>
  <tr><td><strong>Total saldo del mes</strong></td><td>$ <?= number_format($saldo_total_mes,0,',','.') ?></td></tr>
  <tr><td><strong>Total otros aportes del mes</strong></td><td>$ <?= number_format($otros_mes,0,',','.') ?></td></tr>
  <tr><td><strong>Gastos totales del mes</strong></td><td>$ <?= number_format($gasto_mes,0,',','.') ?></td></tr>
  <tr><td><strong>Total gastos del año</strong></td><td>$ <?= number_format($gasto_anio,0,',','.') ?></td></tr>
  <tr><td><strong>Total aportes del año (hasta este mes)</strong></td><td>$ <?= number_format($total_anio_real,0,',','.') ?></td></tr>
</table>


<?php if ($obs): ?>
<br>
  <div class="observaciones">
    <h3 class="observaciones-title">Observaciones del mes</h3>
    <div class="obs">
      <?= nl2br(htmlspecialchars($obs)) ?>
    </div>
  </div>
<?php endif; ?>

</body>
</html>
