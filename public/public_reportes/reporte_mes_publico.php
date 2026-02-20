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

$mesName = date('F', mktime(0, 0, 0, $mes, 1));
$fechaCorteMes = date('Y-m-t', strtotime("$anio-$mes-01"));

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
function pick_default_otro_dia($days, $days_count)
{
  if (!in_array(28, $days) && 28 <= $days_count) return 28;
  for ($d = 1; $d <= $days_count; $d++) {
    if (!in_array($d, $days)) return $d;
  }
  return 1;
}
$otroDia = pick_default_otro_dia($days, $days_count);

// =========================
// JUGADORES
// =========================
$jug_res = $conexion->query("
    SELECT id, nombre, activo, fecha_baja
    FROM jugadores
    WHERE
      activo = 1
      OR (activo = 0 AND (fecha_baja IS NULL OR fecha_baja > '$fechaCorteMes'))
    ORDER BY nombre ASC
");

$jugadores = [];
while ($r = $jug_res->fetch_assoc()) $jugadores[] = $r;

// =========================
// FUNCIONES
// =========================
function get_aporte_real($conexion, $id, $fecha)
{
  $q = $conexion->prepare("SELECT aporte_principal FROM aportes WHERE id_jugador=? AND fecha=? LIMIT 1");
  $q->bind_param("is", $id, $fecha);
  $q->execute();
  $r = $q->get_result()->fetch_assoc();
  $q->close();
  return $r ? (int)$r['aporte_principal'] : 0;
}

function get_consumo_saldo_target($conexion, $id_jugador, $fecha)
{
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
function get_aporte_efectivo_dia($conexion, $id_jugador, $fecha, $TOPE = 3000)
{
  $real    = get_aporte_real($conexion, $id_jugador, $fecha);
  $cashCap = min($real, $TOPE);
  $consumo = get_consumo_saldo_target($conexion, $id_jugador, $fecha);
  return min($cashCap + $consumo, $TOPE);
}

function get_otros($conexion, $id, $mes, $anio)
{
  $q = $conexion->prepare("SELECT tipo, valor FROM otros_aportes WHERE id_jugador=? AND mes=? AND anio=?");
  $q->bind_param("iii", $id, $mes, $anio);
  $q->execute();
  $res = $q->get_result();
  $out = [];
  while ($r = $res->fetch_assoc()) $out[] = $r;
  $q->close();
  return $out;
}

function get_saldo_hasta_mes($conexion, $id, $mes, $anio)
{
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

function get_obs($conexion, $mes, $anio)
{
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
// TOTALES BASE
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

// =========================
// ELIMINADOS + SALDOS
// =========================
$fechaInicioMes = date('Y-m-01', strtotime("$anio-$mes-01"));
$fechaCorte     = date('Y-m-t',  strtotime("$anio-$mes-01"));

$elimIdsMes = [];
$stmt = $conexion->prepare("
  SELECT id
  FROM jugadores
  WHERE activo = 0
    AND fecha_baja IS NOT NULL
    AND fecha_baja >= ?
    AND fecha_baja <= ?
");
$stmt->bind_param("ss", $fechaInicioMes, $fechaCorte);
$stmt->execute();
$res = $stmt->get_result();
while ($r = $res->fetch_assoc()) $elimIdsMes[] = (int)$r['id'];
$stmt->close();

$elimIdsHasta = [];
$stmt = $conexion->prepare("
  SELECT id
  FROM jugadores
  WHERE activo = 0
    AND fecha_baja IS NOT NULL
    AND fecha_baja <= ?
");
$stmt->bind_param("s", $fechaCorte);
$stmt->execute();
$res = $stmt->get_result();
while ($r = $res->fetch_assoc()) $elimIdsHasta[] = (int)$r['id'];
$stmt->close();

function build_in_clause_pdf($ids)
{
  if (empty($ids)) return "NULL";
  return implode(",", array_map("intval", $ids));
}
$inMes   = build_in_clause_pdf($elimIdsMes);
$inHasta = build_in_clause_pdf($elimIdsHasta);

$eliminados_mes_total = (int)($conexion->query("
  SELECT IFNULL(SUM(
    LEAST(a.aporte_principal + IFNULL(t.consumido,0), $TOPE)
  ),0) AS total
  FROM aportes a
  LEFT JOIN (
    SELECT target_aporte_id, SUM(amount) AS consumido
    FROM aportes_saldo_moves
    GROUP BY target_aporte_id
  ) t ON t.target_aporte_id = a.id
  WHERE YEAR(a.fecha)=$anio AND MONTH(a.fecha)=$mes
    AND a.id_jugador IN ($inMes)
")->fetch_assoc()['total'] ?? 0);

$eliminados_anio_total = (int)($conexion->query("
  SELECT IFNULL(SUM(
    LEAST(a.aporte_principal + IFNULL(t.consumido,0), $TOPE)
  ),0) AS total
  FROM aportes a
  LEFT JOIN (
    SELECT target_aporte_id, SUM(amount) AS consumido
    FROM aportes_saldo_moves
    GROUP BY target_aporte_id
  ) t ON t.target_aporte_id = a.id
  WHERE YEAR(a.fecha)=$anio AND MONTH(a.fecha) <= $mes
    AND a.id_jugador IN ($inHasta)
")->fetch_assoc()['total'] ?? 0);

$saldo_eliminados_hasta_mes = (int)($conexion->query("
  SELECT IFNULL(SUM(
    GREATEST(IFNULL(ex.excedente,0) - IFNULL(co.consumido,0), 0)
  ),0) AS saldo
  FROM (
    SELECT id
    FROM jugadores
    WHERE id IN ($inHasta)
  ) j
  LEFT JOIN (
    SELECT id_jugador, SUM(GREATEST(aporte_principal - $TOPE, 0)) AS excedente
    FROM aportes
    WHERE fecha <= '$fechaCorte'
    GROUP BY id_jugador
  ) ex ON ex.id_jugador = j.id
  LEFT JOIN (
    SELECT id_jugador, SUM(amount) AS consumido
    FROM aportes_saldo_moves
    WHERE fecha_consumo <= '$fechaCorte'
    GROUP BY id_jugador
  ) co ON co.id_jugador = j.id
")->fetch_assoc()['saldo'] ?? 0);

$saldo_total_hasta_mes = (int)($conexion->query("
  SELECT IFNULL(SUM(
    GREATEST(IFNULL(ex.excedente,0) - IFNULL(co.consumido,0), 0)
  ),0) AS saldo
  FROM jugadores j
  LEFT JOIN (
    SELECT id_jugador, SUM(GREATEST(aporte_principal - $TOPE, 0)) AS excedente
    FROM aportes
    WHERE fecha <= '$fechaCorte'
    GROUP BY id_jugador
  ) ex ON ex.id_jugador = j.id
  LEFT JOIN (
    SELECT id_jugador, SUM(amount) AS consumido
    FROM aportes_saldo_moves
    WHERE fecha_consumo <= '$fechaCorte'
    GROUP BY id_jugador
  ) co ON co.id_jugador = j.id
")->fetch_assoc()['saldo'] ?? 0);

// =========================
// SECCIONES
// =========================
$parcial_mes  = (int)$month_total - (int)$eliminados_mes_total;
$parcial_anio = (int)$year_total  - (int)$eliminados_anio_total;

$estimado_mes  = (int)$month_total + (int)$otros_mes  + (int)$saldo_total_hasta_mes;
$estimado_anio = (int)$year_total  + (int)$otros_anio + (int)$saldo_total_hasta_mes;

$final_mes  = (int)$estimado_mes  - (int)$gasto_mes;
$final_anio = (int)$estimado_anio - (int)$gasto_anio;

$obs = trim(get_obs($conexion, $mes, $anio));

// =========================
// OTROS PARTIDOS
// =========================
$otros_partidos_items = [];
$total_otros_partidos = 0;

$sqlOtrosPartidos = "
  SELECT 
    a.fecha,
    SUM(
      LEAST(
        LEAST(IFNULL(a.aporte_principal,0), ?) + IFNULL(m.consumido,0),
        ?
      )
    ) AS efectivo_total
  FROM aportes a
  LEFT JOIN (
    SELECT target_aporte_id, IFNULL(SUM(amount),0) AS consumido
    FROM aportes_saldo_moves
    GROUP BY target_aporte_id
  ) m ON m.target_aporte_id = a.id
  WHERE YEAR(a.fecha) = ?
    AND MONTH(a.fecha) = ?
    AND DAYOFWEEK(a.fecha) NOT IN (4,7)
  GROUP BY a.fecha
  HAVING efectivo_total > 0
  ORDER BY a.fecha ASC
";

$stmtOP = $conexion->prepare($sqlOtrosPartidos);
$stmtOP->bind_param("iiii", $TOPE, $TOPE, $anio, $mes);
$stmtOP->execute();
$resOP = $stmtOP->get_result();

while ($row = $resOP->fetch_assoc()) {
  $fecha = $row["fecha"];
  $val = (int)$row["efectivo_total"];
  $total_otros_partidos += $val;

  $otros_partidos_items[] = [
    "fecha" => $fecha,
    "fecha_label" => date("d-m-Y", strtotime($fecha)),
    "efectivo_total" => $val,
  ];
}
$stmtOP->close();
?>

<!DOCTYPE html>
<html>

<head>
  <meta charset="utf-8">
  <title>Reporte mensual <?= htmlspecialchars($mesName) ?> <?= (int)$anio ?></title>
</head>

<body>

  <h3 class="keep-title">Aportantes y Aportes</h3>

  <?php
  // ====== ANCHOS DINÁMICOS ======
$nDias = count($days);
$wNombre  = 12;   // ← ancho ideal
$wEsp     = 16;
$wOtros   = 16;
$wTotal   = 13;
$wSaldo   = 13;
$wDeuda   = 14;

$fixed = $wNombre + $wEsp + $wOtros + $wTotal + $wSaldo + $wDeuda;
$wDia = ($nDias > 0) ? max(2.5, (100 - $fixed) / $nDias) : 0;
  ?>

  <table class="pdf-table pdf-table--aportes" width="100%" cellspacing="0" cellpadding="4">
    <colgroup>
   <col width="<?= $wNombre ?>%">
      <?php foreach ($days as $_): ?>
      <col width="<?= $wDia ?>%">
      <?php endforeach; ?>
       <col width="<?= $wEsp ?>%">
  <col width="<?= $wOtros ?>%">
  <col width="<?= $wTotal ?>%">
  <col width="<?= $wSaldo ?>%">
  <col width="<?= $wDeuda ?>%">
    </colgroup>

    <thead>
      <tr>
      <th class="col-jugador" style="width:<?= $wNombre ?>%;">Jugador</th>

        <?php foreach ($days as $d): ?>
          <th class="col-dia"><?= (int)$d ?></th>
        <?php endforeach; ?>
        <th class="col-especial">Otro Juego<br>(<?= str_pad((string)$otroDia, 2, "0", STR_PAD_LEFT) ?>)</th>
        <th class="col-otros">Otros Aportes</th>
        <th class="col-total">Total</th>
        <th class="col-saldo">Saldo</th>
        <th class="col-deuda">Deuda</th>
      </tr>
    </thead>

    <tbody>
      <?php foreach ($jugadores as $j): ?>
        <?php
        $jid = (int)$j['id'];
        $totalJugador = 0;
        ?>
        <tr class="<?= ((int)($j['activo'] ?? 1) === 0 ? 'eliminado' : '') ?>">
          <td class="td-jugador"><?= htmlspecialchars($j['nombre']) ?></td>

          <?php foreach ($days as $d): ?>
            <?php
            $f = sprintf("%04d-%02d-%02d", $anio, $mes, (int)$d);
            $v = get_aporte_efectivo_dia($conexion, $jid, $f, $TOPE);
            $totalJugador += $v;
            ?>
            <td class="td-num">
              <?= $v ? (int)$v : "" ?>
              <?php if (!empty($deudas_mes[$jid][(int)$d])): ?>
                <span style="display:block; margin-top:2px; color:#d00; font-size:11pt; line-height:11pt; font-family: DejaVu Sans, sans-serif;">&#9679;</span>
              <?php endif; ?>
            </td>
          <?php endforeach; ?>

          <?php
          $fEsp = sprintf("%04d-%02d-%02d", $anio, $mes, (int)$otroDia);
          $vEsp = get_aporte_efectivo_dia($conexion, $jid, $fEsp, $TOPE);
          $totalJugador += $vEsp;
          ?>
          <td class="td-num">
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
          <td class="td-num"><?= $otros_val ? (int)$otros_val : "" ?></td>
          <td class="td-total"><strong><?= number_format($totalJugador, 0, ',', '.') ?></strong></td>
          <td class="td-num"><?= number_format(get_saldo_hasta_mes($conexion, $jid, $mes, $anio), 0, ',', '.') ?></td>

          <td class="td-deuda">
            <?php
            $lista = $deudas_lista[$jid] ?? [];
            $n = count($lista);

            if ($n > 0) {
              echo "<strong>Deudas: {$n}</strong>";
              echo "<div class='deuda-list'>";
              echo implode("<br>", array_map("htmlspecialchars", $lista));
              echo "</div>";
            }
            ?>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>


 
  <div class="section-block">
    <h3 class="keep-title">Resumen General</h3>
    <h3 class="keep-title">Totales (COP)</h3><br>
    <div class="totales-sub">Incluyen valores de Los Días de la Columna Otro Juego (<?= str_pad((string)$otroDia, 2, "0", STR_PAD_LEFT) ?>) <br> (Ver Tabla Datos de Otros Juegos - Abajo &dArr; ) </div>
<br>
    <div class="totales-box">
      <table class="totales-table">
        <tr>
          <td class="label">
            <strong>Total Parcial Mes</strong>
            <div class="note">(Sin otros aportes / saldo / eliminados)</div>
          </td>
          <td class="money">$ <?= number_format((int)$parcial_mes, 0, ',', '.') ?></td>
        </tr>
        <tr>
          <td class="label">
            <strong>Total Parcial Año</strong>
            <div class="note">(Sin otros aportes / saldo / eliminados)</div>
          </td>
          <td class="money">$ <?= number_format((int)$parcial_anio, 0, ',', '.') ?></td>
        </tr>
      </table>

      <div class="sep"></div>

      <table class="totales-table">
        <tr>
          <td class="label"><strong>Otros Aportes Mes</strong></td>
          <td class="money">$ <?= number_format((int)$otros_mes, 0, ',', '.') ?></td>
        </tr>
        <tr>
          <td class="label"><strong>Otros Aportes Año</strong></td>
          <td class="money">$ <?= number_format((int)$otros_anio, 0, ',', '.') ?></td>
        </tr>
        <tr>
          <td class="label"><strong>Saldo Actual Hasta Mes</strong></td>
          <td class="money">$ <?= number_format((int)$saldo_total_hasta_mes, 0, ',', '.') ?></td>
        </tr>
      </table>

      <div class="sep"></div>

      <table class="totales-table">
        <tr>
          <td class="label">
            <strong>Aportantes eliminados este mes</strong>
            <div class="note">No aparecen en planilla, pero sus aportes y saldos siguen sumando en totales.</div>
          </td>
          <td class="money">$ <?= number_format((int)$eliminados_mes_total, 0, ',', '.') ?></td>
        </tr>
        <tr>
          <td class="label">
            <strong>Saldo eliminados hasta mes</strong>
            <div class="note">Saldo acumulado de eliminados (hasta fecha corte del mes).</div>
          </td>
          <td class="money">$ <?= number_format((int)$saldo_eliminados_hasta_mes, 0, ',', '.') ?></td>
        </tr>
      </table>

      <div class="sep"></div>

      <table class="totales-table">
        <tr>
          <td class="label">
            <strong>Total Estimado Mes</strong>
            <div class="note">(+ otros aportes + saldos + eliminados, sin gastos)</div>
          </td>
          <td class="money strong">$ <?= number_format((int)$estimado_mes, 0, ',', '.') ?></td>
        </tr>
        <tr>
          <td class="label">
            <strong>Total Estimado Año</strong>
            <div class="note">(+ otros aportes + saldos + eliminados, sin gastos)</div>
          </td>
          <td class="money strong">$ <?= number_format((int)$estimado_anio, 0, ',', '.') ?></td>
        </tr>
      </table>

      <div class="sep"></div>

      <table class="totales-table">
        <tr>
          <td class="label"><strong>Gastos del Mes</strong></td>
          <td class="money neg">$ <?= number_format((int)$gasto_mes, 0, ',', '.') ?></td>
        </tr>
        <tr>
          <td class="label"><strong>Gastos del Año</strong></td>
          <td class="money neg">$ <?= number_format((int)$gasto_anio, 0, ',', '.') ?></td>
        </tr>
      </table>

      <div class="sep"></div>

      <table class="totales-table final">
        <tr>
          <td class="label">
            <strong>Total Final Mes</strong>
            <div class="note">(estimado - gastos)</div>
          </td>
          <td class="money strong">$ <?= number_format((int)$final_mes, 0, ',', '.') ?></td>
        </tr>
        <tr>
          <td class="label">
            <strong>Total Final Año</strong>
            <div class="note">(estimado - gastos)</div>
          </td>
          <td class="money strong">$ <?= number_format((int)$final_anio, 0, ',', '.') ?></td>
        </tr>
      </table>
    </div>
  </div>


  <div class="section-block">
    <h3 class="keep-title">Datos de Otros Juegos</h3>

    <?php if (empty($otros_partidos_items)): ?>
      <div style="font-size:11pt; opacity:.85;">
        No hay otros partidos (días no miércoles/sábado) con aportes en este mes.
      </div>
    <?php else: ?>
      <table class="pdf-table pdf-table--otros" border="1" width="100%" cellspacing="0" cellpadding="4">
        <colgroup>
          <col style="width:18%">
          <col style="width:22%">
          <col style="width:60%">
        </colgroup>
        <thead>
          <tr>
            <th>Partido</th>
            <th>Fecha</th>
            <th>Total</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($otros_partidos_items as $i => $it): ?>
            <tr>
              <td>Partido <?= (int)($i + 1) ?></td>
              <td><?= htmlspecialchars($it["fecha_label"]) ?></td>
              <td style="text-align:right;"><strong>$ <?= number_format((int)$it["efectivo_total"], 0, ',', '.') ?></strong></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
        <tfoot>
          <tr>
            <td colspan="2"><strong>Total otros partidos</strong></td>
            <td style="text-align:right;"><strong>$ <?= number_format((int)$total_otros_partidos, 0, ',', '.') ?></strong></td>
          </tr>
        </tfoot>
      </table>
    <?php endif; ?>
  </div>

  <div class="section-block">
    <div class="observaciones">
      <h3 class="observaciones-title">Observaciones del mes</h3>
      <div class="obs">
        <?= $obs ? nl2br(htmlspecialchars($obs)) : "<em>Sin observaciones registradas.</em>" ?>
      </div>
    </div>
  </div>


</body>

</html>