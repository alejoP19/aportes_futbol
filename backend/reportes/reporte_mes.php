<?php
include "../../conexion.php";
header("Content-Type: text/html; charset=utf-8");

// =========================
// CONFIG
// =========================
$TOPE = 3000;

// =========================
// HELPERS (SQL)
// =========================
function q_scalar_assoc($conexion, $sql, $key){
  $r = $conexion->query($sql);
  if(!$r) return 0;
  $a = $r->fetch_assoc();
  return (int)($a[$key] ?? 0);
}
function q_scalar_row($conexion, $sql){
  $r = $conexion->query($sql);
  if(!$r) return 0;
  $a = $r->fetch_row();
  return (int)($a[0] ?? 0);
}
function build_in_clause_ids($ids){
  if (empty($ids)) return "NULL";
  return implode(",", array_map("intval", $ids));
}

// =========================
// MES / AÑO
// =========================
$mes  = intval($_GET['mes']  ?? date('n'));
$anio = intval($_GET['anio'] ?? date('Y'));
if ($mes < 1 || $mes > 12) $mes = (int)date('n');
if ($anio < 1900) $anio = (int)date('Y');

$mesName      = date('F', mktime(0, 0, 0, $mes, 1));
$fechaCorteMes= date('Y-m-t', strtotime("$anio-$mes-01"));
$fechaInicioMes = date('Y-m-01', strtotime("$anio-$mes-01"));

// =========================
// DÍAS MIÉRCOLES / SÁBADO
// =========================
$days = [];
$days_count = cal_days_in_month(CAL_GREGORIAN, $mes, $anio);
for ($d = 1; $d <= $days_count; $d++) {
  $w = date('N', strtotime("$anio-$mes-$d"));
  if ($w == 3 || $w == 6) $days[] = $d; // 3=Mié, 6=Sáb (PHP date('N'))
}

// =========================
// “Otro juego” (día especial) igual que interfaz
// =========================
function pick_default_otro_dia($days, $days_count){
  if (!in_array(28, $days) && 28 <= $days_count) return 28;
  for ($d = 1; $d <= $days_count; $d++) {
    if (!in_array($d, $days)) return $d;
  }
  return 1;
}
$otroDia = pick_default_otro_dia($days, $days_count);

// =========================
// FUNCIONES (tu lógica base)
// =========================
function get_aporte_real($conexion, $id, $fecha){
  $q = $conexion->prepare("SELECT aporte_principal FROM aportes WHERE id_jugador=? AND fecha=? LIMIT 1");
  $q->bind_param("is", $id, $fecha);
  $q->execute();
  $r = $q->get_result()->fetch_assoc();
  $q->close();
  return $r ? (int)$r['aporte_principal'] : 0;
}

function get_consumo_saldo_target($conexion, $id_jugador, $fecha){
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
function get_aporte_efectivo_dia($conexion, $id_jugador, $fecha, $TOPE = 3000){
  $real    = get_aporte_real($conexion, $id_jugador, $fecha);
  $cashCap = min($real, $TOPE);
  $consumo = get_consumo_saldo_target($conexion, $id_jugador, $fecha);
  return min($cashCap + $consumo, $TOPE);
}

function get_otros($conexion, $id, $mes, $anio){
  $q = $conexion->prepare("SELECT tipo, valor FROM otros_aportes WHERE id_jugador=? AND mes=? AND anio=?");
  $q->bind_param("iii", $id, $mes, $anio);
  $q->execute();
  $res = $q->get_result();
  $out = [];
  while ($r = $res->fetch_assoc()) $out[] = $r;
  $q->close();
  return $out;
}

function get_saldo_hasta_mes($conexion, $id, $mes, $anio){
  $fechaCorte = date('Y-m-t', strtotime("$anio-$mes-01"));

  $q1 = $conexion->prepare("
    SELECT IFNULL(SUM(GREATEST(aporte_principal - 3000, 0)),0)
    FROM aportes
    WHERE id_jugador=? AND fecha<=?
      AND (tipo_aporte IS NULL)
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

function get_saldo_hasta_fecha($conexion, $id, $fechaCorte, $TOPE = 3000){
  $q1 = $conexion->prepare("
    SELECT IFNULL(SUM(GREATEST(aporte_principal - ?, 0)),0) AS excedente
    FROM aportes
    WHERE id_jugador=? AND fecha<=?
      AND (tipo_aporte IS NULL)
  ");
  $q1->bind_param("iis", $TOPE, $id, $fechaCorte);
  $q1->execute();
  $excedente = (int)($q1->get_result()->fetch_assoc()["excedente"] ?? 0);
  $q1->close();

  $q2 = $conexion->prepare("
    SELECT IFNULL(SUM(amount),0) AS consumido
    FROM aportes_saldo_moves
    WHERE id_jugador=? AND fecha_consumo<=?
  ");
  $q2->bind_param("is", $id, $fechaCorte);
  $q2->execute();
  $consumido = (int)($q2->get_result()->fetch_assoc()["consumido"] ?? 0);
  $q2->close();

  return max(0, $excedente - $consumido);
}

function get_aportes_eliminado_hasta_mes($conexion, $jid, $anio, $mes, $TOPE = 3000){
  $stmt = $conexion->prepare("
    SELECT
      a.id,
      a.fecha,
      IFNULL(a.aporte_principal,0) AS real_aporte,
      IFNULL(t.consumido,0) AS consumido_target,
      LEAST(
        LEAST(IFNULL(a.aporte_principal,0), ?) + IFNULL(t.consumido,0),
        ?
      ) AS efectivo
    FROM aportes a
    LEFT JOIN (
      SELECT target_aporte_id, SUM(amount) AS consumido
      FROM aportes_saldo_moves
      GROUP BY target_aporte_id
    ) t ON t.target_aporte_id = a.id
    WHERE a.id_jugador = ?
      AND a.tipo_aporte IS NULL
      AND YEAR(a.fecha) = ?
      AND MONTH(a.fecha) <= ?
    ORDER BY a.fecha ASC, a.id ASC
  ");
  $stmt->bind_param("iiiii", $TOPE, $TOPE, $jid, $anio, $mes);
  $stmt->execute();

  $res = $stmt->get_result();
  $rows = [];
  while ($r = $res->fetch_assoc()) {
    $r["real_aporte"]      = (int)$r["real_aporte"];
    $r["consumido_target"] = (int)$r["consumido_target"];
    $r["efectivo"]         = (int)$r["efectivo"];
    $rows[] = $r;
  }
  $stmt->close();
  return $rows;
}

function get_obs($conexion, $mes, $anio){
  $q = $conexion->prepare("SELECT texto FROM gastos_observaciones WHERE mes=? AND anio=? LIMIT 1");
  $q->bind_param("ii", $mes, $anio);
  $q->execute();
  $r = $q->get_result()->fetch_assoc();
  $q->close();
  return $r['texto'] ?? "";
}

// =========================
// FUNCIÓN CLAVE: TOTALES “ADMIN-LIKE” POR MES (para poder sumar el AÑO igual que Admin)
// =========================
function calcular_mes_admin_like($conexion, $anio, $mes, $TOPE){
  $fechaCorteMes = date('Y-m-t', strtotime("$anio-$mes-01"));

  $reg_mes = q_scalar_assoc($conexion, "
    SELECT IFNULL(SUM(
      LEAST(LEAST(IFNULL(a.aporte_principal,0), $TOPE) + IFNULL(t.consumido,0), $TOPE)
    ),0) AS s
    FROM aportes a
    LEFT JOIN (
      SELECT target_aporte_id, SUM(amount) AS consumido
      FROM aportes_saldo_moves
      GROUP BY target_aporte_id
    ) t ON t.target_aporte_id=a.id
    WHERE YEAR(a.fecha)=$anio AND MONTH(a.fecha)=$mes
      AND a.id_jugador IS NOT NULL
      AND a.tipo_aporte IS NULL
  ", "s");

  $esp_mes = q_scalar_assoc($conexion, "
    SELECT IFNULL(SUM(aporte_principal),0) s
    FROM aportes
    WHERE tipo_aporte='esporadico'
      AND YEAR(fecha)=$anio AND MONTH(fecha)=$mes
  ", "s");

  $otros_tabla_mes = q_scalar_assoc($conexion, "
    SELECT IFNULL(SUM(valor),0) s
    FROM otros_aportes
    WHERE anio=$anio AND mes=$mes
  ", "s");

  $otros_esp_mes = q_scalar_assoc($conexion, "
    SELECT IFNULL(SUM(aporte_principal),0) s
    FROM aportes
    WHERE tipo_aporte='esporadico_otro'
      AND YEAR(fecha)=$anio AND MONTH(fecha)=$mes
  ", "s");

  $otros_mes = (int)$otros_tabla_mes + (int)$otros_esp_mes;

  $saldo_mes = q_scalar_assoc($conexion, "
    SELECT IFNULL(SUM(
      GREATEST(IFNULL(ex.excedente,0) - IFNULL(co.consumido,0), 0)
    ),0) AS saldo
    FROM jugadores j
    LEFT JOIN (
      SELECT id_jugador, SUM(GREATEST(aporte_principal - $TOPE, 0)) AS excedente
      FROM aportes
      WHERE fecha <= '$fechaCorteMes'
        AND id_jugador IS NOT NULL
        AND tipo_aporte IS NULL
      GROUP BY id_jugador
    ) ex ON ex.id_jugador=j.id
    LEFT JOIN (
      SELECT id_jugador, SUM(amount) AS consumido
      FROM aportes_saldo_moves
      WHERE fecha_consumo <= '$fechaCorteMes'
      GROUP BY id_jugador
    ) co ON co.id_jugador=j.id
  ", "saldo");

  $gasto_mes = q_scalar_row($conexion, "
    SELECT IFNULL(SUM(valor),0)
    FROM gastos
    WHERE anio=$anio AND mes=$mes
  ");

  // Admin: Parcial = registrados + esporádicos (incluye eliminados)
  $parcial_mes = (int)$reg_mes + (int)$esp_mes;

  // Admin: Estimado = parcial + otros + saldos (sin gastos)
  $estimado_mes = (int)$parcial_mes + (int)$otros_mes + (int)$saldo_mes;

  // Final = estimado - gastos
  $final_mes = (int)$estimado_mes - (int)$gasto_mes;

  return [
    "parcial_mes"  => $parcial_mes,
    "otros_mes"    => $otros_mes,
    "saldo_mes"    => $saldo_mes,
    "estimado_mes" => $estimado_mes,
    "gasto_mes"    => $gasto_mes,
    "final_mes"    => $final_mes,
  ];
}

// =========================
// JUGADORES VISIBLES (igual que Admin / PDF)
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

$jugIds = array_map(fn($j) => (int)$j['id'], $jugadores);
$inJugVisibles = build_in_clause_ids($jugIds);

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
// ELIMINADOS (ids) para secciones + “otros aportes eliminados”
// =========================
$elimIdsMes = [];
$stmt = $conexion->prepare("
  SELECT id
  FROM jugadores
  WHERE activo = 0
    AND fecha_baja IS NOT NULL
    AND fecha_baja >= ?
    AND fecha_baja <= ?
");
$stmt->bind_param("ss", $fechaInicioMes, $fechaCorteMes);
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
$stmt->bind_param("s", $fechaCorteMes);
$stmt->execute();
$res = $stmt->get_result();
while ($r = $res->fetch_assoc()) $elimIdsHasta[] = (int)$r['id'];
$stmt->close();

$inMes   = build_in_clause_ids($elimIdsMes);
$inHasta = build_in_clause_ids($elimIdsHasta);

// =========================
// FOOTERS TABLA PRINCIPAL (solo visibles)
// =========================
$totales_por_dia_principal = [];
$total_otro_dia_principal = 0;
$total_otros_aportes_principal = 0;
$total_mes_principal = 0;

$stmtDay = $conexion->prepare("
  SELECT IFNULL(SUM(
    LEAST(
      LEAST(IFNULL(a.aporte_principal,0), ?) + IFNULL(m.consumido,0),
      ?
    )
  ),0) AS total
  FROM aportes a
  LEFT JOIN (
    SELECT target_aporte_id, IFNULL(SUM(amount),0) AS consumido
    FROM aportes_saldo_moves
    GROUP BY target_aporte_id
  ) m ON m.target_aporte_id = a.id
  WHERE a.fecha = ?
    AND a.id_jugador IN ($inJugVisibles)
    AND (a.tipo_aporte IS NULL OR a.tipo_aporte NOT IN ('esporadico','esporadico_otro'))
");
foreach ($days as $d) {
  $fecha = sprintf("%04d-%02d-%02d", $anio, $mes, (int)$d);
  $stmtDay->bind_param("iis", $TOPE, $TOPE, $fecha);
  $stmtDay->execute();
  $row = $stmtDay->get_result()->fetch_assoc();
  $val = (int)($row['total'] ?? 0);
  $totales_por_dia_principal[(int)$d] = $val;
  $total_mes_principal += $val;
}
$stmtDay->close();

$fechaOtro = sprintf("%04d-%02d-%02d", $anio, $mes, (int)$otroDia);
$stmtOtro = $conexion->prepare("
  SELECT IFNULL(SUM(
    LEAST(
      LEAST(IFNULL(a.aporte_principal,0), ?) + IFNULL(m.consumido,0),
      ?
    )
  ),0) AS total
  FROM aportes a
  LEFT JOIN (
    SELECT target_aporte_id, IFNULL(SUM(amount),0) AS consumido
    FROM aportes_saldo_moves
    GROUP BY target_aporte_id
  ) m ON m.target_aporte_id = a.id
  WHERE a.fecha = ?
    AND a.id_jugador IN ($inJugVisibles)
    AND (a.tipo_aporte IS NULL OR a.tipo_aporte NOT IN ('esporadico','esporadico_otro'))
");
$stmtOtro->bind_param("iis", $TOPE, $TOPE, $fechaOtro);
$stmtOtro->execute();
$row = $stmtOtro->get_result()->fetch_assoc();
$total_otro_dia_principal = (int)($row['total'] ?? 0);
$stmtOtro->close();

$total_mes_principal += $total_otro_dia_principal;

$total_otros_aportes_principal = q_scalar_assoc($conexion, "
  SELECT IFNULL(SUM(valor),0) s
  FROM otros_aportes
  WHERE mes=$mes AND anio=$anio
    AND id_jugador IN ($inJugVisibles)
", "s");

$total_mes_principal += $total_otros_aportes_principal;

// saldo visibles al corte
$total_saldo_visibles = 0;
foreach ($jugadores as $j) {
  $total_saldo_visibles += get_saldo_hasta_mes($conexion, (int)$j['id'], $mes, $anio);
}

// =========================
// FOOTER ESPORÁDICOS (resumen)
// =========================
$totales_por_dia_esp = [];
$total_esp_otros_aportes = 0; // esporadico_otro
$total_mes_esp = 0;

$stmtEspDay = $conexion->prepare("
  SELECT IFNULL(SUM(aporte_principal),0) AS total
  FROM aportes
  WHERE tipo_aporte='esporadico'
    AND fecha = ?
    AND YEAR(fecha)=?
    AND MONTH(fecha)=?
");
foreach ($days as $d) {
  $fecha = sprintf("%04d-%02d-%02d", $anio, $mes, (int)$d);
  $stmtEspDay->bind_param("sii", $fecha, $anio, $mes);
  $stmtEspDay->execute();
  $row = $stmtEspDay->get_result()->fetch_assoc();
  $val = (int)($row['total'] ?? 0);
  $totales_por_dia_esp[(int)$d] = $val;
  $total_mes_esp += $val;
}
$stmtEspDay->close();

$total_esp_otros_aportes = q_scalar_assoc($conexion, "
  SELECT IFNULL(SUM(aporte_principal),0) s
  FROM aportes
  WHERE tipo_aporte='esporadico_otro'
    AND YEAR(fecha)=$anio AND MONTH(fecha)=$mes
", "s");
$total_mes_esp += $total_esp_otros_aportes;

// =========================
// TABLA ÚNICA: OTROS JUEGOS (Principal + Esporádico)
// =========================
$otros_juegos_items = [];
$total_otros_juegos = 0;

// Principal (no mié/sáb) SOLO visibles
$stmtOP = $conexion->prepare("
  SELECT a.fecha,
    IFNULL(SUM(
      LEAST(
        LEAST(IFNULL(a.aporte_principal,0), ?) + IFNULL(m.consumido,0),
        ?
      )
    ),0) AS total
  FROM aportes a
  LEFT JOIN (
    SELECT target_aporte_id, IFNULL(SUM(amount),0) AS consumido
    FROM aportes_saldo_moves
    GROUP BY target_aporte_id
  ) m ON m.target_aporte_id = a.id
  WHERE YEAR(a.fecha)=?
    AND MONTH(a.fecha)=?
    AND DAYOFWEEK(a.fecha) NOT IN (4,7)
    AND a.id_jugador IN ($inJugVisibles)
    AND (a.tipo_aporte IS NULL OR a.tipo_aporte NOT IN ('esporadico','esporadico_otro'))
  GROUP BY a.fecha
  HAVING total > 0
  ORDER BY a.fecha ASC
");
$stmtOP->bind_param("iiii", $TOPE, $TOPE, $anio, $mes);
$stmtOP->execute();
$resOP = $stmtOP->get_result();
while ($row = $resOP->fetch_assoc()) {
  $f = $row["fecha"];
  $v = (int)$row["total"];
  $total_otros_juegos += $v;
  $otros_juegos_items[] = [
    "tabla" => "Principal",
    "fecha" => $f,
    "fecha_label" => date("d-m-Y", strtotime($f)),
    "total" => $v,
  ];
}
$stmtOP->close();

// Esporádico (no mié/sáb)
$stmtOPE = $conexion->prepare("
  SELECT fecha, IFNULL(SUM(aporte_principal),0) AS total
  FROM aportes
  WHERE tipo_aporte='esporadico'
    AND YEAR(fecha)=?
    AND MONTH(fecha)=?
    AND DAYOFWEEK(fecha) NOT IN (4,7)
  GROUP BY fecha
  HAVING total > 0
  ORDER BY fecha ASC
");
$stmtOPE->bind_param("ii", $anio, $mes);
$stmtOPE->execute();
$resOPE = $stmtOPE->get_result();
while ($row = $resOPE->fetch_assoc()) {
  $f = $row["fecha"];
  $v = (int)$row["total"];
  $total_otros_juegos += $v;
  $otros_juegos_items[] = [
    "tabla" => "Esporádico",
    "fecha" => $f,
    "fecha_label" => date("d-m-Y", strtotime($f)),
    "total" => $v,
  ];
}
$stmtOPE->close();

usort($otros_juegos_items, function ($a, $b) {
  if ($a['fecha'] === $b['fecha']) return strcmp($a['tabla'], $b['tabla']);
  return strcmp($a['fecha'], $b['fecha']);
});

// =========================
// TOTALES (COP) - EXACTO COMO ADMIN (MES directo + AÑO sumando mes a mes)
// =========================
$acc_parcial_anio   = 0;
$acc_otros_anio     = 0;
$acc_saldo_anio     = 0;
$acc_estimado_anio  = 0;
$acc_gasto_anio     = 0;
$acc_final_anio     = 0;

$mes_calc = null;
for ($mm = 1; $mm <= $mes; $mm++){
  $tmp = calcular_mes_admin_like($conexion, $anio, $mm, $TOPE);
  if ($mm === $mes) $mes_calc = $tmp;

  $acc_parcial_anio  += (int)$tmp["parcial_mes"];
  $acc_otros_anio    += (int)$tmp["otros_mes"];
  $acc_saldo_anio    += (int)$tmp["saldo_mes"];
  $acc_estimado_anio += (int)$tmp["estimado_mes"];
  $acc_gasto_anio    += (int)$tmp["gasto_mes"];
  $acc_final_anio    += (int)$tmp["final_mes"];
}

// Mes
$parcial_mes            = (int)$mes_calc["parcial_mes"];
$otros_mes_real         = (int)$mes_calc["otros_mes"];
$saldo_total_hasta_mes  = (int)$mes_calc["saldo_mes"];   // “Saldos actuales”
$estimado_mes           = (int)$mes_calc["estimado_mes"];
$gasto_mes              = (int)$mes_calc["gasto_mes"];
$final_mes              = (int)$mes_calc["final_mes"];

// Año (sumado mes a mes)
$parcial_anio           = (int)$acc_parcial_anio;
$otros_anio_real        = (int)$acc_otros_anio;
$estimado_anio          = (int)$acc_estimado_anio;
$gasto_anio             = (int)$acc_gasto_anio;
$final_anio             = (int)$acc_final_anio;

// “Aportantes eliminados este mes” (solo INFORMACIÓN, como Admin)
$eliminados_mes_total = q_scalar_assoc($conexion, "
  SELECT IFNULL(SUM(
    LEAST(LEAST(IFNULL(a.aporte_principal,0), $TOPE) + IFNULL(t.consumido,0), $TOPE)
  ),0) AS s
  FROM aportes a
  LEFT JOIN (
    SELECT target_aporte_id, SUM(amount) AS consumido
    FROM aportes_saldo_moves
    GROUP BY target_aporte_id
  ) t ON t.target_aporte_id=a.id
  WHERE YEAR(a.fecha)=$anio AND MONTH(a.fecha)=$mes
    AND a.id_jugador IN ($inMes)
    AND a.tipo_aporte IS NULL
", "s");

// Saldo eliminados (solo INFORMACIÓN)
$saldo_eliminados_hasta_mes = q_scalar_assoc($conexion, "
  SELECT IFNULL(SUM(
    GREATEST(IFNULL(ex.excedente,0) - IFNULL(co.consumido,0), 0)
  ),0) AS saldo
  FROM (
    SELECT id FROM jugadores WHERE id IN ($inHasta)
  ) j
  LEFT JOIN (
    SELECT id_jugador, SUM(GREATEST(aporte_principal - $TOPE, 0)) AS excedente
    FROM aportes
    WHERE fecha <= '$fechaCorteMes'
      AND tipo_aporte IS NULL
    GROUP BY id_jugador
  ) ex ON ex.id_jugador=j.id
  LEFT JOIN (
    SELECT id_jugador, SUM(amount) AS consumido
    FROM aportes_saldo_moves
    WHERE fecha_consumo <= '$fechaCorteMes'
    GROUP BY id_jugador
  ) co ON co.id_jugador=j.id
", "saldo");

$obs = trim(get_obs($conexion, $mes, $anio));
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
  $wNombre  = 14;
  $wEsp     = 12;   // Otro Juego
  $wOtros   = 12;   // Otros Aportes
  $wTotal   = 12;
  $wSaldo   = 12;
  $wDeuda   = 16;

  $fixed = $wNombre + $wEsp + $wOtros + $wTotal + $wSaldo + $wDeuda;
  $wDia = ($nDias > 0) ? max(2.6, (100 - $fixed) / $nDias) : 0;
  ?>

  <table class="pdf-table pdf-table--aportes" width="100%" cellspacing="0" cellpadding="4">
    <colgroup>
      <col width="<?= $wNombre ?>%">
      <?php foreach ($days as $_): ?><col width="<?= $wDia ?>%"><?php endforeach; ?>
      <col width="<?= $wEsp ?>%">
      <col width="<?= $wOtros ?>%">
      <col width="<?= $wTotal ?>%">
      <col width="<?= $wSaldo ?>%">
      <col width="<?= $wDeuda ?>%">
    </colgroup>

    <thead>
      <tr>
        <th class="col-jugador" style="width:<?= $wNombre ?>%;">Jugador</th>
        <?php foreach ($days as $d): ?><th class="col-dia"><?= (int)$d ?></th><?php endforeach; ?>
        <th class="col-especial">Otro Juego<br></th>
        <th class="col-otros">Otros<br>Aportes</th>
        <th class="col-total">Total<br>Mes</th>
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
                <span class="deuda-dot">&#9679;</span>
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
              <span class="deuda-dot">&#9679;</span>
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

    <tfoot>
      <tr class="tfoot-principal">
        <td class="tfoot-label"><strong>TOTAL PRINCIPAL</strong></td>

        <?php foreach ($days as $d): ?>
          <td class="td-total"><strong><?= number_format((int)($totales_por_dia_principal[(int)$d] ?? 0), 0, ',', '.') ?></strong></td>
        <?php endforeach; ?>

        <td class="td-total"><strong><?= number_format((int)$total_otro_dia_principal, 0, ',', '.') ?></strong></td>
        <td class="td-total"><strong><?= number_format((int)$total_otros_aportes_principal, 0, ',', '.') ?></strong></td>
        <td class="td-total"><strong><?= number_format((int)$total_mes_principal, 0, ',', '.') ?></strong></td>
        <td class="td-total"><strong><?= number_format((int)$total_saldo_visibles, 0, ',', '.') ?></strong></td>
        <td></td>
      </tr>
    </tfoot>
  </table>

  <div class="section-block">
    <h3 class="keep-title">Planilla Aportes Esporádicos (Resumen)</h3>

    <table class="pdf-table pdf-table--aportes" width="100%" cellspacing="0" cellpadding="4">
      <colgroup>
        <col width="<?= $wNombre ?>%">
        <?php foreach ($days as $_): ?><col width="<?= $wDia ?>%"><?php endforeach; ?>
        <col width="<?= $wOtros ?>%">
        <col width="<?= $wTotal ?>%">
      </colgroup>

      <thead>
        <tr>
          <th class="col-jugador">#</th>
          <?php foreach ($days as $d): ?><th class="col-dia"><?= (int)$d ?></th><?php endforeach; ?>
          <th class="col-otros">Otros<br>Aportes</th>
          <th class="col-total">Total<br>Mes</th>
        </tr>
      </thead>

      <tbody>
        <tr>
          <td class="td-jugador"><strong>Esporádicos</strong></td>
          <?php foreach ($days as $d): ?>
            <td class="td-num"><?= ((int)($totales_por_dia_esp[(int)$d] ?? 0)) ? number_format((int)$totales_por_dia_esp[(int)$d], 0, ',', '.') : "0" ?></td>
          <?php endforeach; ?>
          <td class="td-num"><strong><?= number_format((int)$total_esp_otros_aportes, 0, ',', '.') ?></strong></td>
          <td class="td-total"><strong><?= number_format((int)$total_mes_esp, 0, ',', '.') ?></strong></td>
        </tr>
      </tbody>
    </table>

    <div class="note" style="margin-top:6px;">
      Nota: Los “Otros Juegos” de Esporádicos se muestran en la tabla “Otros Juegos” (abajo) con la columna TABLA = Esporádico.
    </div>
  </div>

  <div class="section-block">
    <h3 class="keep-title">Otros Juegos (Principal + Esporádicos)</h3>

    <?php if (empty($otros_juegos_items)): ?>
      <div style="font-size:11pt; opacity:.85;">
        No hay otros juegos (días no miércoles/sábado) con aportes en este mes.
      </div>
    <?php else: ?>
      <table class="pdf-table pdf-table--otros" border="1" width="100%" cellspacing="0" cellpadding="4">
        <colgroup>
          <col style="width:14%">
          <col style="width:18%">
          <col style="width:22%">
          <col style="width:46%">
        </colgroup>
        <thead>
          <tr>
            <th>#</th>
            <th>Tabla</th>
            <th>Fecha</th>
            <th>Total</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($otros_juegos_items as $i => $it): ?>
            <tr>
              <td>Partido <?= (int)($i + 1) ?></td>
              <td><strong><?= htmlspecialchars($it["tabla"]) ?></strong></td>
              <td><?= htmlspecialchars($it["fecha_label"]) ?></td>
              <td style="text-align:right;"><strong>$ <?= number_format((int)$it["total"], 0, ',', '.') ?></strong></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
        <tfoot>
          <tr>
            <td colspan="3"><strong>Total otros partidos</strong></td>
            <td style="text-align:right;"><strong>$ <?= number_format((int)$total_otros_juegos, 0, ',', '.') ?></strong></td>
          </tr>
        </tfoot>
      </table>
    <?php endif; ?>
  </div>

  <div class="section-block">
    <h3 class="keep-title">Aportantes Eliminados (Detalle)</h3>

    <?php
    $stmtEl = $conexion->prepare("
      SELECT id, nombre, fecha_baja
      FROM jugadores
      WHERE activo = 0
        AND fecha_baja IS NOT NULL
        AND fecha_baja >= ?
        AND fecha_baja <= ?
      ORDER BY fecha_baja ASC, nombre ASC
    ");
    $stmtEl->bind_param("ss", $fechaInicioMes, $fechaCorteMes);
    $stmtEl->execute();
    $resEl = $stmtEl->get_result();

    $eliminados = [];
    while ($r = $resEl->fetch_assoc()) $eliminados[] = $r;
    $stmtEl->close();

    $totalGeneralAportesEl = 0;
    $totalGeneralSaldoEl   = 0;
    $totalGeneralOtrosMesEl = 0;
    ?>

    <?php if (empty($eliminados)): ?>
      <div style="font-size:11pt; opacity:.85;">
        No hubo eliminados en este mes.
      </div>
    <?php else: ?>

      <table class="pdf-table pdf-table--eliminados" width="100%" cellspacing="0" cellpadding="4">
        <thead>
          <tr>
            <th style="width:34%;">Aportante</th>
            <th style="width:6%; text-align:right;">#</th>
            <th style="width:18%;">Fecha</th>
            <th style="width:14%; text-align:right;">Cantidad</th>
            <th style="width:14%; text-align:right;">Total</th>
            <th style="width:14%; text-align:right;">Saldo</th>
          </tr>
        </thead>

        <tbody>
          <?php foreach ($eliminados as $e): ?>
            <?php
            $jid = (int)$e["id"];
            $nombre = $e["nombre"];
            $fechaBajaLabel = !empty($e['fecha_baja']) ? date("d-m-Y", strtotime($e['fecha_baja'])) : "Sin fecha";

            $aportes = get_aportes_eliminado_hasta_mes($conexion, $jid, $anio, $mes, $TOPE);

            // Otros aportes del eliminado (tabla otros_aportes)
            $otros_eliminado_mes = q_scalar_assoc($conexion, "
              SELECT IFNULL(SUM(valor),0) s
              FROM otros_aportes
              WHERE anio=$anio AND mes=$mes AND id_jugador=$jid
            ", "s");

            $acum = 0;
            $saldoFinMes = get_saldo_hasta_fecha($conexion, $jid, $fechaCorteMes, $TOPE);
            $totalGeneralSaldoEl += $saldoFinMes;

            foreach ($aportes as $rr) $acum += (int)$rr["efectivo"];
            $totalGeneralAportesEl += $acum;

            $totalGeneralOtrosMesEl += (int)$otros_eliminado_mes;
            ?>

            <?php if (empty($aportes)): ?>
              <tr class="elim-head">
                <td>
                  <strong><?= htmlspecialchars($nombre) ?></strong><br>
                  <span class="baja-pill">Baja: <?= htmlspecialchars($fechaBajaLabel) ?></span>
                </td>
                <td colspan="5" style="opacity:.75;">Sin aportes registrados hasta este mes.</td>
              </tr>
            <?php else: ?>
              <?php
              $acumRunning = 0;
              $n = 0;
              ?>
              <?php foreach ($aportes as $r): ?>
                <?php
                $n++;
                $f = $r["fecha"];
                $efectivo = (int)$r["efectivo"];
                $acumRunning += $efectivo;

                $saldoEnFecha = get_saldo_hasta_fecha($conexion, $jid, $f, $TOPE);
                $esMesActual = ((int)date("Y", strtotime($f)) === (int)$anio) && ((int)date("n", strtotime($f)) === (int)$mes);
                $rowCls = $esMesActual ? "row-mes-actual" : "row-mes-previo";

                $aportanteCell = "";
                if ($n === 1) {
                  $aportanteCell = "<strong>" . htmlspecialchars($nombre) . "</strong>";
                } elseif ($n === 2) {
                  $aportanteCell = "<span class='baja-pill'>Baja: " . htmlspecialchars($fechaBajaLabel) . "</span>";
                }
                ?>
                <tr class="<?= $rowCls ?>">
                  <td><?= $aportanteCell ?></td>
                  <td style="text-align:right;"><?= $n ?></td>
                  <td><?= htmlspecialchars(date("d-m-Y", strtotime($f))) ?></td>
                  <td style="text-align:right;"><?= number_format($efectivo, 0, ',', '.') ?></td>
                  <td style="text-align:right;"><?= number_format($acumRunning, 0, ',', '.') ?></td>
                  <td style="text-align:right;"><strong><?= number_format($saldoEnFecha, 0, ',', '.') ?></strong></td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>

            <?php if ((int)$otros_eliminado_mes > 0): ?>
              <tr class="elim-otros">
                <td><strong>Otros aportes (eliminado) - Mes</strong></td>
                <td colspan="3"></td>
                <td style="text-align:right;"><strong>$ <?= number_format((int)$otros_eliminado_mes, 0, ',', '.') ?></strong></td>
                <td></td>
              </tr>
            <?php endif; ?>

            <tr class="elim-total">
              <td><strong>Total aportes jugador</strong></td>
              <td colspan="3"></td>
              <td style="text-align:right;"><strong>$ <?= number_format((int)($acum + (int)$otros_eliminado_mes), 0, ',', '.') ?></strong></td>
              <td style="text-align:right;"><strong>$ <?= number_format($saldoFinMes, 0, ',', '.') ?></strong></td>
            </tr>

            <tr class="elim-sep">
              <td colspan="6"></td>
            </tr>

          <?php endforeach; ?>
        </tbody>

        <tfoot>
          <tr class="elim-grand">
            <td><strong>Total General</strong></td>
            <td colspan="3"></td>
            <td style="text-align:right;"><strong>$ <?= number_format((int)($totalGeneralAportesEl + $totalGeneralOtrosMesEl), 0, ',', '.') ?></strong></td>
            <td style="text-align:right;"><strong>$ <?= number_format($totalGeneralSaldoEl, 0, ',', '.') ?></strong></td>
          </tr>
        </tfoot>
      </table>

      <div class="note" style="margin-top:6px;">
        Nota: filas del mes actual se marcan en azul; filas de meses anteriores se marcan en rojo.
      </div>

    <?php endif; ?>
  </div>

  <div class="section-block">
    <h3 class="keep-title">Resumen General</h3>
    <h3 class="keep-title">Totales (COP)</h3><br>

    <div class="totales-box">

      <table class="totales-table">
        <tr>
          <td class="label">
            <strong>Total Parcial Mes</strong>
            <div class="note">(Registrados + Esporádicos. Incluye eliminados porque están en aportes)</div>
          </td>
          <td class="money">$ <?= number_format((int)$parcial_mes, 0, ',', '.') ?></td>
        </tr>
        <tr>
          <td class="label">
            <strong>Total Parcial Año</strong>
            <div class="note">(Suma mes a mes: Parcial Ene + Parcial Feb + ...)</div>
          </td>
          <td class="money">$ <?= number_format((int)$parcial_anio, 0, ',', '.') ?></td>
        </tr>
      </table>

      <div class="sep"></div>

      <table class="totales-table">
        <tr>
          <td class="label">
            <strong>Otros Aportes Mes</strong>
            <div class="note">(otros_aportes + esporadico_otro)</div>
          </td>
          <td class="money">$ <?= number_format((int)$otros_mes_real, 0, ',', '.') ?></td>
        </tr>
        <tr>
          <td class="label">
            <strong>Otros Aportes Año</strong>
            <div class="note">(Suma mes a mes: Otros Ene + Otros Feb + ...)</div>
          </td>
          <td class="money">$ <?= number_format((int)$otros_anio_real, 0, ',', '.') ?></td>
        </tr>
        <tr>
          <td class="label"><strong>Saldos Actuales De Aportantes (Hasta Este Mes)</strong></td>
          <td class="money">$ <?= number_format((int)$saldo_total_hasta_mes, 0, ',', '.') ?></td>
        </tr>
      </table>

      <div class="sep"></div>

      <table class="totales-table">
        <tr>
          <td class="label">
            <strong>Aportes de Aportantes Eliminados (este mes)</strong>
            <div class="note">No aparecen en planilla, pero sus aportes están dentro del Parcial.</div>
          </td>
          <td class="money">$ <?= number_format((int)$eliminados_mes_total, 0, ',', '.') ?></td>
        </tr>
        <tr>
          <td class="label">
            <strong>Saldo eliminados hasta mes</strong>
            <div class="note">Saldo acumulado de eliminados al corte del mes.</div>
          </td>
          <td class="money">$ <?= number_format((int)$saldo_eliminados_hasta_mes, 0, ',', '.') ?></td>
        </tr>
      </table>

      <div class="sep"></div>

      <table class="totales-table">
        <tr>
          <td class="label">
            <strong>Total Estimado Mes</strong>
            <div class="note">(Parcial Mes + Otros Mes + Saldo Mes, sin gastos)</div>
          </td>
          <td class="money strong">$ <?= number_format((int)$estimado_mes, 0, ',', '.') ?></td>
        </tr>
        <tr>
          <td class="label">
            <strong>Total Estimado Año</strong>
            <div class="note">(Suma mes a mes: Estimado Ene + Estimado Feb + ...)</div>
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
            <div class="note">(Suma mes a mes: Final Ene + Final Feb + ...)</div>
          </td>
          <td class="money strong">$ <?= number_format((int)$final_anio, 0, ',', '.') ?></td>
        </tr>
      </table>

    </div>
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