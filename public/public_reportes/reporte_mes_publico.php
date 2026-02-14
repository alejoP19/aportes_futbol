<?php
include "../../conexion.php";
header("Content-Type: text/html; charset=utf-8");

// =========================
// CONFIG (cuota base 3000)
// =========================
$TOPE = 3000;

// =========================
// MES / AÑO
// =========================
$mes  = intval($_GET['mes']  ?? date('n'));
$anio = intval($_GET['anio'] ?? date('Y'));

if ($mes < 1 || $mes > 12) $mes = (int)date('n');
if ($anio < 1900) $anio = (int)date('Y');

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
// “Otro juego” (día especial) igual que interfaz
// =========================
function pick_default_otro_dia($days, $days_count) {
    if (!in_array(28, $days) && 28 <= $days_count) return 28;
    for ($d = 1; $d <= $days_count; $d++) {
        if (!in_array($d, $days)) return $d;
    }
    return 1;
}
$otroDia = pick_default_otro_dia($days, $days_count);

// =========================
// OTROS PARTIDOS (no mié/sáb) - resumen por fecha
// =========================
$otrosDias = [];
for ($d=1; $d<=$days_count; $d++){
  if (!in_array($d, $days, true)) $otrosDias[] = $d;
}

$otrosItems = [];
$totalOtrosPartidos = 0;

foreach ($otrosDias as $d){
  $fecha = sprintf("%04d-%02d-%02d", $anio, $mes, (int)$d);

  $efectivoTotal = (int)$conexion->query("
      SELECT IFNULL(SUM(
          LEAST(a.aporte_principal + IFNULL(t.consumido,0), $TOPE)
      ),0) AS s
      FROM aportes a
      LEFT JOIN (
          SELECT target_aporte_id, SUM(amount) AS consumido
          FROM aportes_saldo_moves
          GROUP BY target_aporte_id
      ) t ON t.target_aporte_id = a.id
      WHERE a.fecha = '$fecha'
  ")->fetch_assoc()['s'] ?? 0;

  if ($efectivoTotal > 0){
    $otrosItems[] = [
      "fecha" => $fecha,
      "label" => date("d-m-Y", strtotime($fecha)),
      "total" => $efectivoTotal
    ];
    $totalOtrosPartidos += $efectivoTotal;
  }
}



// =========================
// JUGADORES
// =========================
// NOTA: Mantengo tu UNION ALL original (si tu proyecto real usa "activo=0" en jugadores,
// ajustas aquí; pero no lo cambio para no romper nada).
$jug_res = $conexion->query("
    SELECT id, nombre FROM jugadores
    UNION ALL
    SELECT id, nombre FROM jugadores_eliminados
    ORDER BY nombre ASC
");

$jugadores = [];
while ($r = $jug_res->fetch_assoc()) $jugadores[] = $r;

// =========================
// FUNCIONES
// =========================
function get_aporte_real($conexion, $id, $fecha) {
    $q = $conexion->prepare("SELECT aporte_principal FROM aportes WHERE id_jugador=? AND fecha=? LIMIT 1");
    $q->bind_param("is", $id, $fecha);
    $q->execute();
    $r = $q->get_result()->fetch_assoc();
    $q->close();
    return $r ? (int)$r['aporte_principal'] : 0;
}

function get_consumo_saldo_target($conexion, $id_jugador, $fecha) {
    $stmt = $conexion->prepare("
        SELECT IFNULL(SUM(m.amount),0) AS c
        FROM aportes a
        INNER JOIN aportes_saldo_moves m ON m.target_aporte_id = a.id
        WHERE a.id_jugador=? AND a.fecha=?
    ");
    $stmt->bind_param("is", $id_jugador, $fecha);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return (int)($row['c'] ?? 0);
}

// aporte efectivo del día: cap + consumo_target, cap 3000
function get_aporte_efectivo_dia($conexion, $id_jugador, $fecha, $TOPE = 3000) {
    $real    = get_aporte_real($conexion, $id_jugador, $fecha);
    $cashCap = min($real, $TOPE);
    $consumo = get_consumo_saldo_target($conexion, $id_jugador, $fecha);
    return min($cashCap + $consumo, $TOPE);
}

function get_otros($conexion, $id, $mes, $anio) {
    $q = $conexion->prepare("SELECT tipo, valor FROM otros_aportes WHERE id_jugador=? AND mes=? AND anio=?");
    $q->bind_param("iii", $id, $mes, $anio);
    $q->execute();
    $res = $q->get_result();
    $out = [];
    while ($r = $res->fetch_assoc()) $out[] = $r;
    $q->close();
    return $out;
}

function get_saldo_hasta_mes($conexion, $id, $mes, $anio) {
    $fechaCorte = date('Y-m-t', strtotime("$anio-$mes-01"));

    $q1 = $conexion->prepare("
        SELECT IFNULL(SUM(GREATEST(aporte_principal - 3000, 0)),0)
        FROM aportes
        WHERE id_jugador=? AND fecha<=?
    ");
    $q1->bind_param("is", $id, $fechaCorte);
    $q1->execute();
    $excedente = (int)$q1->get_result()->fetch_row()[0];
    $q1->close();

    $q2 = $conexion->prepare("
        SELECT IFNULL(SUM(amount),0)
        FROM aportes_saldo_moves
        WHERE id_jugador=? AND fecha_consumo<=?
    ");
    $q2->bind_param("is", $id, $fechaCorte);
    $q2->execute();
    $consumido = (int)$q2->get_result()->fetch_row()[0];
    $q2->close();

    return max(0, $excedente - $consumido);
}

function get_obs($conexion, $mes, $anio) {
    $q = $conexion->prepare("SELECT texto FROM gastos_observaciones WHERE mes=? AND anio=? LIMIT 1");
    $q->bind_param("ii", $mes, $anio);
    $q->execute();
    $r = $q->get_result()->fetch_assoc();
    $q->close();
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
    $jid = (int)$r['id_jugador'];
    $dia = (int)date('j', strtotime($r['fecha']));
    $deudas_mes[$jid][$dia] = true;
}

$deudas_lista = [];
$res = $conexion->query("
    SELECT id_jugador, fecha
    FROM deudas_aportes
    WHERE (YEAR(fecha) < $anio)
       OR (YEAR(fecha) = $anio AND MONTH(fecha) <= $mes)
    ORDER BY fecha ASC
");

while ($r = $res->fetch_assoc()) {
    $jid = (int)$r['id_jugador'];
    $f   = $r['fecha'];
    $deudas_lista[$jid][] = date("d-m-Y", strtotime($f));
}



// =========================
// SALDOS TOTALES
// =========================
$saldo_total_mes  = 0;
$saldo_total_anio = 0;

foreach ($jugadores as $j) {
    $jid = (int)$j['id'];
    $s = get_saldo_hasta_mes($conexion, $jid, $mes, $anio);
    $saldo_total_mes  += $s;
    $saldo_total_anio += $s;
}

// =========================
// TOTALES BASE (igual que get_totals.php)
// =========================
$month_total = $conexion->query("
    SELECT IFNULL(SUM(
        LEAST(a.aporte_principal + IFNULL(t.consumido,0), $TOPE)
    ),0) AS total_mes
    FROM aportes a
    LEFT JOIN (
        SELECT target_aporte_id, SUM(amount) AS consumido
        FROM aportes_saldo_moves
        GROUP BY target_aporte_id
    ) t ON t.target_aporte_id = a.id
    WHERE YEAR(a.fecha) = $anio
      AND MONTH(a.fecha) = $mes
")->fetch_assoc()['total_mes'] ?? 0;

$year_total = $conexion->query("
    SELECT IFNULL(SUM(
        LEAST(a.aporte_principal + IFNULL(t.consumido,0), $TOPE)
    ),0) AS total_anio
    FROM aportes a
    LEFT JOIN (
        SELECT target_aporte_id, SUM(amount) AS consumido
        FROM aportes_saldo_moves
        GROUP BY target_aporte_id
    ) t ON t.target_aporte_id = a.id
    WHERE YEAR(a.fecha) = $anio
      AND MONTH(a.fecha) <= $mes
")->fetch_assoc()['total_anio'] ?? 0;

// Otros / gastos
$otros_mes = (int)$conexion->query("
    SELECT IFNULL(SUM(valor),0)
    FROM otros_aportes
    WHERE mes=$mes AND anio=$anio
")->fetch_row()[0];

$otros_anio = (int)$conexion->query("
    SELECT IFNULL(SUM(valor),0)
    FROM otros_aportes
    WHERE anio=$anio AND mes<=$mes
")->fetch_row()[0];

$gasto_mes = (int)$conexion->query("
    SELECT IFNULL(SUM(valor),0)
    FROM gastos
    WHERE mes=$mes AND anio=$anio
")->fetch_row()[0];

$gasto_anio = (int)$conexion->query("
    SELECT IFNULL(SUM(valor),0)
    FROM gastos
    WHERE anio=$anio AND mes<=$mes
")->fetch_row()[0];

// Totales finales (sin sumar saldos)
$total_mes_real  = (int)$month_total + $otros_mes  - $gasto_mes;
$total_anio_real = (int)$year_total  + $otros_anio - $gasto_anio;

// ✅ NUEVO: Totales con saldo (hasta la fecha del reporte)
$total_mes_con_saldo  = (int)$total_mes_real  + (int)$saldo_total_mes;
$total_anio_con_saldo = (int)$total_anio_real + (int)$saldo_total_anio;

$obs = trim(get_obs($conexion, $mes, $anio));
?>

<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<title>Reporte mensual <?= htmlspecialchars($mesName) ?> <?= (int)$anio ?></title>
</head>

<body>

<h3>Aportantes y Aportes</h3>

<table border="1" width="100%" cellspacing="0" cellpadding="4">
<thead>
<tr>
  <th>Jugador</th>
  <?php foreach ($days as $d): ?><th><?= (int)$d ?></th><?php endforeach; ?>
  <th>Otra Fecha (<?= str_pad((string)$otroDia, 2, "0", STR_PAD_LEFT) ?>)</th>
  <th>Otros</th>
  <th>Total</th>
  <th>Saldo</th>
  <th>Deuda</th>
</tr>
</thead>
<tbody>

<?php foreach ($jugadores as $j): ?>
<?php
  $jid = (int)$j['id'];   // ✅ NUEVO: id real del jugador para todo el bloque
  $totalJugador = 0;
?>
<tr>
  <td><?= htmlspecialchars($j['nombre']) ?></td>

  <?php foreach ($days as $d): ?>
    <?php
      $f = sprintf("%04d-%02d-%02d", $anio, $mes, (int)$d);

      // ✅ EFECTIVO del día (incluye saldo consumido)
 $v = get_aporte_efectivo_dia($conexion, $jid, $f, $TOPE);
      $totalJugador += $v;

$hayDeudaDia = !empty($deudas_mes[$jid][(int)$d]);


    ?>
    <td>
      <?= $v ? (int)$v : "" ?>
   <?php if (!empty($deudas_mes[$jid][(int)$d])): ?>
  <span style="display:block; margin-top:2px; color:#d00; font-size:11pt; line-height:11pt; font-family: DejaVu Sans, sans-serif;">&#9679;</span>
<?php endif; ?>
    </td>
  <?php endforeach; ?>

  <?php
    // ✅ Especial = otroDia efectivo igual que interfaz
    $fEsp = sprintf("%04d-%02d-%02d", $anio, $mes, (int)$otroDia);
 $vEsp = get_aporte_efectivo_dia($conexion, $jid, $fEsp, $TOPE);

    $totalJugador += $vEsp;

    $hayDeudaEspecial = !empty($deudas_mes[$jid][(int)$otroDia]);

  ?>
  <td>
    <?= $vEsp ? (int)$vEsp : "" ?>
   <?php if (!empty($deudas_mes[$jid][(int)$otroDia])): ?>
  <span style="display:block; margin-top:2px; color:#d00; font-size:11pt; line-height:11pt; font-family: DejaVu Sans, sans-serif;">&#9679;</span>
<?php endif; ?>
  </td>

  <?php
    $otros = get_otros($conexion, $jid, $mes, $anio);
    $otros_val = 0;
    foreach ($otros as $o) $otros_val += (int)$o['valor'];
    $totalJugador += $otros_val;
  ?>
  <td><?= $otros_val ? (int)$otros_val : "" ?></td>
  <td><strong><?= number_format($totalJugador, 0, ',', '.') ?></strong></td>
  <td><?= number_format(get_saldo_hasta_mes($conexion, $jid, $mes, $anio), 0, ',', '.') ?></td>
<td>
  <?php
    $lista = $deudas_lista[$jid] ?? [];
    $n = count($lista);

    if ($n > 0) {
        echo "<strong>Deudas: {$n}</strong>";
        echo "<div style='margin-top:3px; color:#2e8b57; font-size:9pt; line-height:10pt; font-family: DejaVu Sans, sans-serif;'>";
        echo implode("<br>", array_map("htmlspecialchars", $lista));
        echo "</div>";
    } else {
        echo "";
    }
  ?>
</td>



</tr>
<?php endforeach; ?>

</tbody>
</table>

<br>
<?php if (!empty($otrosItems)): ?>
  <h3>Otros Partidos (no Miércoles/Sábado)</h3>
  <table border="1" width="60%" cellspacing="0" cellpadding="4">
    <thead>
      <tr>
        <th>#</th>
        <th>Fecha</th>
        <th>Total</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($otrosItems as $i => $it): ?>
        <tr>
          <td><?= (int)($i+1) ?></td>
          <td><?= htmlspecialchars($it["label"]) ?></td>
          <td>$ <?= number_format((int)$it["total"], 0, ',', '.') ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
    <tfoot>
      <tr>
        <td colspan="2"><strong>Total</strong></td>
        <td><strong>$ <?= number_format((int)$totalOtrosPartidos, 0, ',', '.') ?></strong></td>
      </tr>
    </tfoot>
  </table>
<?php else: ?>
  <h3>Otros Partidos (no Miércoles/Sábado)</h3>
  <p><em>Sin registros este mes.</em></p>
<?php endif; ?>
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

<br>


<div class="totales-con-saldo">
  <h3 class="totales-con-saldo-title">Totales con saldo (hasta este mes)</h3>

  <table class="totales-con-saldo-table">
    <tr>
      <td class="label"><strong>Total del mes (con saldos)</strong></td>
      <td class="money">$ 36.000</td>
    </tr>
    <tr>
      <td class="label">
        <strong>Total del año (con saldos, hasta este mes)</strong>
      </td>
      <td class="money">$ 111.000</td>
    </tr>
  </table>
</div>


<br>


<div class="observaciones">
  <h3 class="observaciones-title">Observaciones del mes</h3>
  <div class="obs">
    <?= $obs ? nl2br(htmlspecialchars($obs)) : "<em>Sin observaciones registradas.</em>" ?>
  </div>
</div>

</body>
</html>
