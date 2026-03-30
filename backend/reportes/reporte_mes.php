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

$mesName        = date('F', mktime(0, 0, 0, $mes, 1));
$fechaCorteMes  = date('Y-m-t', strtotime("$anio-$mes-01"));
$fechaInicioMes = date('Y-m-01', strtotime("$anio-$mes-01"));

// =========================
// DÍAS MIÉRCOLES / SÁBADO
// =========================
$days = [];
$days_count = cal_days_in_month(CAL_GREGORIAN, $mes, $anio);
for ($d = 1; $d <= $days_count; $d++) {
  $w = date('N', strtotime("$anio-$mes-$d"));
  if ($w == 3 || $w == 6) $days[] = $d; // 3=Mié, 6=Sáb
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
// FUNCIONES BASE
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
    INNER JOIN aportes s ON s.id = m.source_aporte_id
    WHERE a.id_jugador=? AND a.fecha=?
      AND s.aporte_principal > 3000
  ");
  $stmt->bind_param("is", $id_jugador, $fecha);
  $stmt->execute();
  $row = $stmt->get_result()->fetch_assoc();
  $stmt->close();
  return (int)($row['c'] ?? 0);
}

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
  SELECT IFNULL(SUM(m.amount),0)
  FROM aportes_saldo_moves m
  INNER JOIN aportes s ON s.id = m.source_aporte_id
  WHERE m.id_jugador=? AND m.fecha_consumo<=?
    AND s.aporte_principal > 3000
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
  SELECT IFNULL(SUM(m.amount),0) AS consumido
  FROM aportes_saldo_moves m
  INNER JOIN aportes s ON s.id = m.source_aporte_id
  WHERE m.id_jugador=? AND m.fecha_consumo<=?
    AND s.aporte_principal > 3000
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
     SELECT m.target_aporte_id, SUM(m.amount) AS consumido
FROM aportes_saldo_moves m
INNER JOIN aportes s ON s.id = m.source_aporte_id
WHERE s.aporte_principal > 3000
GROUP BY m.target_aporte_id
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
// JUGADORES VISIBLES (igual que interfaz)
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
// ELIMINADOS
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
// TABLA PRINCIPAL - FOOTERS (SOLO ESTA TABLA)
// Incluye:
// - miércoles/sábado visibles
// - otros_aportes visibles
// NO incluye:
// - otros juegos
// - esporádicos
// =========================
$totales_por_dia_principal = [];
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
// TABLA ESPORÁDICOS - FOOTER (SOLO ESTA TABLA)
// Incluye:
// - miércoles/sábado esporádicos
// - esporadico_otro
// NO incluye:
// - otros juegos esporádicos
// =========================
$totales_por_dia_esp = [];
$total_esp_otros_aportes = 0;
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
// TABLA ÚNICA: OTROS JUEGOS (Principal + Esporádicos)
// OJO:
// - principal visibles
// - esporádicos
// - NO incluye otros juegos de eliminados para no duplicar visualmente
// =========================
$otros_juegos_items = [];
$total_otros_juegos = 0;

// Principal visibles (no mié/sáb)
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

// Esporádicos (no mié/sáb)
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
// ELIMINADOS - CÁLCULOS DEL MES PARA RESUMEN
// Para no duplicar:
// - aportes normales mié/sáb de eliminados NO visibles
// - otros juegos de eliminados
// - otros aportes de eliminados
// =========================

// 1) eliminados del mes no visibles en la planilla principal
$elimNoVisiblesMes = array_values(array_diff($elimIdsMes, $jugIds));
$inElimNoVisMes = build_in_clause_ids($elimNoVisiblesMes);

// 2) aportes normales (mié/sáb) del mes de eliminados NO visibles
$eliminados_normales_mes_no_visibles = q_scalar_assoc($conexion, "
  SELECT IFNULL(SUM(
    LEAST(
      LEAST(IFNULL(a.aporte_principal,0), $TOPE) + IFNULL(t.consumido,0),
      $TOPE
    )
  ),0) AS s
  FROM aportes a
  LEFT JOIN (
   SELECT m.target_aporte_id, SUM(m.amount) AS consumido
FROM aportes_saldo_moves m
INNER JOIN aportes s ON s.id = m.source_aporte_id
WHERE s.aporte_principal > 3000
GROUP BY m.target_aporte_id
  ) t ON t.target_aporte_id = a.id
  WHERE YEAR(a.fecha)=$anio
    AND MONTH(a.fecha)=$mes
    AND DAYOFWEEK(a.fecha) IN (4,7)
    AND a.id_jugador IN ($inElimNoVisMes)
    AND a.tipo_aporte IS NULL
", "s");

// 3) otros juegos de eliminados del mes
$eliminados_otros_juegos_mes = q_scalar_assoc($conexion, "
  SELECT IFNULL(SUM(
    LEAST(
      LEAST(IFNULL(a.aporte_principal,0), $TOPE) + IFNULL(t.consumido,0),
      $TOPE
    )
  ),0) AS s
  FROM aportes a
  LEFT JOIN (
 SELECT m.target_aporte_id, SUM(m.amount) AS consumido
FROM aportes_saldo_moves m
INNER JOIN aportes s ON s.id = m.source_aporte_id
WHERE s.aporte_principal > 3000
GROUP BY m.target_aporte_id
  ) t ON t.target_aporte_id = a.id
  WHERE YEAR(a.fecha)=$anio
    AND MONTH(a.fecha)=$mes
    AND DAYOFWEEK(a.fecha) NOT IN (4,7)
    AND a.id_jugador IN ($inMes)
    AND a.tipo_aporte IS NULL
", "s");

// 4) otros aportes de eliminados del mes
$eliminados_otros_aportes_mes = q_scalar_assoc($conexion, "
  SELECT IFNULL(SUM(valor),0) AS s
  FROM otros_aportes
  WHERE anio=$anio
    AND mes=$mes
    AND id_jugador IN ($inMes)
", "s");

// =========================
// RESUMEN GENERAL NUEVO
// =========================

// principal + esporádicos + otros juegos + eliminados faltantes + otros aportes eliminados/no eliminados
$total_parcial_mes_resumen =
  (int)$total_mes_principal
  + (int)$total_mes_esp
  + (int)$total_otros_juegos
  + (int)$eliminados_normales_mes_no_visibles
  + (int)$eliminados_otros_juegos_mes
  + (int)$eliminados_otros_aportes_mes;

// año = suma mes a mes con misma lógica
$total_parcial_anio_resumen = 0;
$otros_aportes_anio_info = 0;

for ($mm = 1; $mm <= $mes; $mm++) {
  $fechaCorteTmp = date('Y-m-t', strtotime("$anio-$mm-01"));
  $fechaInicioTmp = date('Y-m-01', strtotime("$anio-$mm-01"));

  // visibles de ese mes
  $jugTmp = [];
  $resTmp = $conexion->query("
    SELECT id
    FROM jugadores
    WHERE activo = 1
      OR (activo = 0 AND (fecha_baja IS NULL OR fecha_baja > '$fechaCorteTmp'))
  ");
  while ($r = $resTmp->fetch_assoc()) $jugTmp[] = (int)$r['id'];
  $inJugTmp = build_in_clause_ids($jugTmp);

  // eliminados del mes
  $elimTmp = [];
  $stmtTmp = $conexion->prepare("
    SELECT id
    FROM jugadores
    WHERE activo = 0
      AND fecha_baja IS NOT NULL
      AND fecha_baja >= ?
      AND fecha_baja <= ?
  ");
  $stmtTmp->bind_param("ss", $fechaInicioTmp, $fechaCorteTmp);
  $stmtTmp->execute();
  $resTmp2 = $stmtTmp->get_result();
  while ($r = $resTmp2->fetch_assoc()) $elimTmp[] = (int)$r['id'];
  $stmtTmp->close();

  $elimNoVisTmp = array_values(array_diff($elimTmp, $jugTmp));
  $inElimNoVisTmp = build_in_clause_ids($elimNoVisTmp);
  $inElimTmp = build_in_clause_ids($elimTmp);

  // principal visible mier/sab
  $principal_dias_tmp = q_scalar_assoc($conexion, "
    SELECT IFNULL(SUM(
      LEAST(
        LEAST(IFNULL(a.aporte_principal,0), $TOPE) + IFNULL(t.consumido,0),
        $TOPE
      )
    ),0) AS s
    FROM aportes a
    LEFT JOIN (
   SELECT m.target_aporte_id, SUM(m.amount) AS consumido
FROM aportes_saldo_moves m
INNER JOIN aportes s ON s.id = m.source_aporte_id
WHERE s.aporte_principal > 3000
GROUP BY m.target_aporte_id
    ) t ON t.target_aporte_id = a.id
    WHERE YEAR(a.fecha)=$anio
      AND MONTH(a.fecha)=$mm
      AND DAYOFWEEK(a.fecha) IN (4,7)
      AND a.id_jugador IN ($inJugTmp)
      AND a.tipo_aporte IS NULL
  ", "s");

  // otros aportes visibles
  $principal_otros_tmp = q_scalar_assoc($conexion, "
    SELECT IFNULL(SUM(valor),0) AS s
    FROM otros_aportes
    WHERE anio=$anio
      AND mes=$mm
      AND id_jugador IN ($inJugTmp)
  ", "s");

  // esporádicos mier/sab
  $esp_dias_tmp = q_scalar_assoc($conexion, "
    SELECT IFNULL(SUM(aporte_principal),0) AS s
    FROM aportes
    WHERE tipo_aporte='esporadico'
      AND YEAR(fecha)=$anio
      AND MONTH(fecha)=$mm
      AND DAYOFWEEK(fecha) IN (4,7)
  ", "s");

  // esporadico_otro
  $esp_otros_tmp = q_scalar_assoc($conexion, "
    SELECT IFNULL(SUM(aporte_principal),0) AS s
    FROM aportes
    WHERE tipo_aporte='esporadico_otro'
      AND YEAR(fecha)=$anio
      AND MONTH(fecha)=$mm
  ", "s");

  // otros juegos visibles
  $otros_juegos_vis_tmp = q_scalar_assoc($conexion, "
    SELECT IFNULL(SUM(
      LEAST(
        LEAST(IFNULL(a.aporte_principal,0), $TOPE) + IFNULL(t.consumido,0),
        $TOPE
      )
    ),0) AS s
    FROM aportes a
    LEFT JOIN (
     SELECT m.target_aporte_id, SUM(m.amount) AS consumido
FROM aportes_saldo_moves m
INNER JOIN aportes s ON s.id = m.source_aporte_id
WHERE s.aporte_principal > 3000
GROUP BY m.target_aporte_id
    ) t ON t.target_aporte_id = a.id
    WHERE YEAR(a.fecha)=$anio
      AND MONTH(a.fecha)=$mm
      AND DAYOFWEEK(a.fecha) NOT IN (4,7)
      AND a.id_jugador IN ($inJugTmp)
      AND a.tipo_aporte IS NULL
  ", "s");

  // otros juegos esporádicos
  $otros_juegos_esp_tmp = q_scalar_assoc($conexion, "
    SELECT IFNULL(SUM(aporte_principal),0) AS s
    FROM aportes
    WHERE tipo_aporte='esporadico'
      AND YEAR(fecha)=$anio
      AND MONTH(fecha)=$mm
      AND DAYOFWEEK(fecha) NOT IN (4,7)
  ", "s");

  // eliminados no visibles normales mier/sab
  $elim_norm_no_vis_tmp = q_scalar_assoc($conexion, "
    SELECT IFNULL(SUM(
      LEAST(
        LEAST(IFNULL(a.aporte_principal,0), $TOPE) + IFNULL(t.consumido,0),
        $TOPE
      )
    ),0) AS s
    FROM aportes a
    LEFT JOIN (
    SELECT m.target_aporte_id, SUM(m.amount) AS consumido
FROM aportes_saldo_moves m
INNER JOIN aportes s ON s.id = m.source_aporte_id
WHERE s.aporte_principal > 3000
GROUP BY m.target_aporte_id
    ) t ON t.target_aporte_id = a.id
    WHERE YEAR(a.fecha)=$anio
      AND MONTH(a.fecha)=$mm
      AND DAYOFWEEK(a.fecha) IN (4,7)
      AND a.id_jugador IN ($inElimNoVisTmp)
      AND a.tipo_aporte IS NULL
  ", "s");

  // eliminados otros juegos
  $elim_otros_juegos_tmp = q_scalar_assoc($conexion, "
    SELECT IFNULL(SUM(
      LEAST(
        LEAST(IFNULL(a.aporte_principal,0), $TOPE) + IFNULL(t.consumido,0),
        $TOPE
      )
    ),0) AS s
    FROM aportes a
    LEFT JOIN (
     SELECT m.target_aporte_id, SUM(m.amount) AS consumido
FROM aportes_saldo_moves m
INNER JOIN aportes s ON s.id = m.source_aporte_id
WHERE s.aporte_principal > 3000
GROUP BY m.target_aporte_id
    ) t ON t.target_aporte_id = a.id
    WHERE YEAR(a.fecha)=$anio
      AND MONTH(a.fecha)=$mm
      AND DAYOFWEEK(a.fecha) NOT IN (4,7)
      AND a.id_jugador IN ($inElimTmp)
      AND a.tipo_aporte IS NULL
  ", "s");

  // eliminados otros aportes
  $elim_otros_aportes_tmp = q_scalar_assoc($conexion, "
    SELECT IFNULL(SUM(valor),0) AS s
    FROM otros_aportes
    WHERE anio=$anio
      AND mes=$mm
      AND id_jugador IN ($inElimTmp)
  ", "s");

  $parcial_mes_tmp =
    (int)$principal_dias_tmp
    + (int)$principal_otros_tmp
    + (int)$esp_dias_tmp
    + (int)$esp_otros_tmp
    + (int)$otros_juegos_vis_tmp
    + (int)$otros_juegos_esp_tmp
    + (int)$elim_norm_no_vis_tmp
    + (int)$elim_otros_juegos_tmp
    + (int)$elim_otros_aportes_tmp;

  $total_parcial_anio_resumen += (int)$parcial_mes_tmp;
  $otros_aportes_anio_info += (int)$principal_otros_tmp + (int)$esp_otros_tmp + (int)$elim_otros_aportes_tmp;
}

// gastos
$gastos_mes_resumen = q_scalar_row($conexion, "
  SELECT IFNULL(SUM(valor),0)
  FROM gastos
  WHERE anio=$anio AND mes=$mes
");

$gastos_anio_resumen = q_scalar_row($conexion, "
  SELECT IFNULL(SUM(valor),0)
  FROM gastos
  WHERE anio=$anio AND mes<=$mes
");

// estimados finales sin saldos
$total_estimado_final_mes  = (int)$total_parcial_mes_resumen  - (int)$gastos_mes_resumen;
$total_estimado_final_anio = (int)$total_parcial_anio_resumen - (int)$gastos_anio_resumen;

// saldo actual mes = excedentes generados este mes
$saldo_actual_mes = q_scalar_assoc($conexion, "
  SELECT IFNULL(SUM(GREATEST(aporte_principal - $TOPE, 0)),0) AS s
  FROM aportes
  WHERE YEAR(fecha)=$anio
    AND MONTH(fecha)=$mes
    AND id_jugador IS NOT NULL
    AND tipo_aporte IS NULL
", "s");

// saldo acumulado = saldo total disponible al corte
$saldo_total_hasta_mes = q_scalar_assoc($conexion, "
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

$saldos_acumulados = (int)$saldo_total_hasta_mes;

// total final año con saldos
$total_final_anio_con_saldos = (int)$total_estimado_final_anio + (int)$saldos_acumulados;
// =========================
// CAJA GENERAL ACUMULADA
// = Suma de totales finales con saldos de años anteriores
// + total final con saldos del año actual hasta el mes seleccionado
// =========================
$caja_general_acumulada = 0;

for ($yy = 1900; $yy <= $anio; $yy++) {
  $mesLimite = ($yy < $anio) ? 12 : $mes;

  if ($mesLimite < 1) continue;

  $parcial_anio_loop = 0;
  $gastos_anio_loop  = 0;

  for ($mm = 1; $mm <= $mesLimite; $mm++) {
    $fechaCorteTmp = date('Y-m-t', strtotime("$yy-$mm-01"));
    $fechaInicioTmp = date('Y-m-01', strtotime("$yy-$mm-01"));

    // visibles de ese mes
    $jugTmp = [];
    $resTmp = $conexion->query("
      SELECT id
      FROM jugadores
      WHERE activo = 1
        OR (activo = 0 AND (fecha_baja IS NULL OR fecha_baja > '$fechaCorteTmp'))
    ");
    while ($r = $resTmp->fetch_assoc()) $jugTmp[] = (int)$r['id'];
    $inJugTmp = build_in_clause_ids($jugTmp);

    // eliminados del mes
    $elimTmp = [];
    $stmtTmp = $conexion->prepare("
      SELECT id
      FROM jugadores
      WHERE activo = 0
        AND fecha_baja IS NOT NULL
        AND fecha_baja >= ?
        AND fecha_baja <= ?
    ");
    $stmtTmp->bind_param("ss", $fechaInicioTmp, $fechaCorteTmp);
    $stmtTmp->execute();
    $resTmp2 = $stmtTmp->get_result();
    while ($r = $resTmp2->fetch_assoc()) $elimTmp[] = (int)$r['id'];
    $stmtTmp->close();

    $elimNoVisTmp = array_values(array_diff($elimTmp, $jugTmp));
    $inElimNoVisTmp = build_in_clause_ids($elimNoVisTmp);
    $inElimTmp = build_in_clause_ids($elimTmp);

    $principal_dias_tmp = q_scalar_assoc($conexion, "
      SELECT IFNULL(SUM(
        LEAST(
          LEAST(IFNULL(a.aporte_principal,0), $TOPE) + IFNULL(t.consumido,0),
          $TOPE
        )
      ),0) AS s
      FROM aportes a
      LEFT JOIN (
       SELECT m.target_aporte_id, SUM(m.amount) AS consumido
FROM aportes_saldo_moves m
INNER JOIN aportes s ON s.id = m.source_aporte_id
WHERE s.aporte_principal > 3000
GROUP BY m.target_aporte_id
      ) t ON t.target_aporte_id = a.id
      WHERE YEAR(a.fecha)=$yy
        AND MONTH(a.fecha)=$mm
        AND DAYOFWEEK(a.fecha) IN (4,7)
        AND a.id_jugador IN ($inJugTmp)
        AND a.tipo_aporte IS NULL
    ", "s");

    $principal_otros_tmp = q_scalar_assoc($conexion, "
      SELECT IFNULL(SUM(valor),0) AS s
      FROM otros_aportes
      WHERE anio=$yy
        AND mes=$mm
        AND id_jugador IN ($inJugTmp)
    ", "s");

    $esp_dias_tmp = q_scalar_assoc($conexion, "
      SELECT IFNULL(SUM(aporte_principal),0) AS s
      FROM aportes
      WHERE tipo_aporte='esporadico'
        AND YEAR(fecha)=$yy
        AND MONTH(fecha)=$mm
        AND DAYOFWEEK(fecha) IN (4,7)
    ", "s");

    $esp_otros_tmp = q_scalar_assoc($conexion, "
      SELECT IFNULL(SUM(aporte_principal),0) AS s
      FROM aportes
      WHERE tipo_aporte='esporadico_otro'
        AND YEAR(fecha)=$yy
        AND MONTH(fecha)=$mm
    ", "s");

    $otros_juegos_vis_tmp = q_scalar_assoc($conexion, "
      SELECT IFNULL(SUM(
        LEAST(
          LEAST(IFNULL(a.aporte_principal,0), $TOPE) + IFNULL(t.consumido,0),
          $TOPE
        )
      ),0) AS s
      FROM aportes a
      LEFT JOIN (
       SELECT m.target_aporte_id, SUM(m.amount) AS consumido
FROM aportes_saldo_moves m
INNER JOIN aportes s ON s.id = m.source_aporte_id
WHERE s.aporte_principal > 3000
GROUP BY m.target_aporte_id
      ) t ON t.target_aporte_id = a.id
      WHERE YEAR(a.fecha)=$yy
        AND MONTH(a.fecha)=$mm
        AND DAYOFWEEK(a.fecha) NOT IN (4,7)
        AND a.id_jugador IN ($inJugTmp)
        AND a.tipo_aporte IS NULL
    ", "s");

    $otros_juegos_esp_tmp = q_scalar_assoc($conexion, "
      SELECT IFNULL(SUM(aporte_principal),0) AS s
      FROM aportes
      WHERE tipo_aporte='esporadico'
        AND YEAR(fecha)=$yy
        AND MONTH(fecha)=$mm
        AND DAYOFWEEK(fecha) NOT IN (4,7)
    ", "s");

    $elim_norm_no_vis_tmp = q_scalar_assoc($conexion, "
      SELECT IFNULL(SUM(
        LEAST(
          LEAST(IFNULL(a.aporte_principal,0), $TOPE) + IFNULL(t.consumido,0),
          $TOPE
        )
      ),0) AS s
      FROM aportes a
      LEFT JOIN (
       SELECT m.target_aporte_id, SUM(m.amount) AS consumido
FROM aportes_saldo_moves m
INNER JOIN aportes s ON s.id = m.source_aporte_id
WHERE s.aporte_principal > 3000
GROUP BY m.target_aporte_id
      ) t ON t.target_aporte_id = a.id
      WHERE YEAR(a.fecha)=$yy
        AND MONTH(a.fecha)=$mm
        AND DAYOFWEEK(a.fecha) IN (4,7)
        AND a.id_jugador IN ($inElimNoVisTmp)
        AND a.tipo_aporte IS NULL
    ", "s");

    $elim_otros_juegos_tmp = q_scalar_assoc($conexion, "
      SELECT IFNULL(SUM(
        LEAST(
          LEAST(IFNULL(a.aporte_principal,0), $TOPE) + IFNULL(t.consumido,0),
          $TOPE
        )
      ),0) AS s
      FROM aportes a
      LEFT JOIN (
       SELECT m.target_aporte_id, SUM(m.amount) AS consumido
FROM aportes_saldo_moves m
INNER JOIN aportes s ON s.id = m.source_aporte_id
WHERE s.aporte_principal > 3000
GROUP BY m.target_aporte_id
      ) t ON t.target_aporte_id = a.id
      WHERE YEAR(a.fecha)=$yy
        AND MONTH(a.fecha)=$mm
        AND DAYOFWEEK(a.fecha) NOT IN (4,7)
        AND a.id_jugador IN ($inElimTmp)
        AND a.tipo_aporte IS NULL
    ", "s");

    $elim_otros_aportes_tmp = q_scalar_assoc($conexion, "
      SELECT IFNULL(SUM(valor),0) AS s
      FROM otros_aportes
      WHERE anio=$yy
        AND mes=$mm
        AND id_jugador IN ($inElimTmp)
    ", "s");

    $parcial_mes_tmp =
      (int)$principal_dias_tmp
      + (int)$principal_otros_tmp
      + (int)$esp_dias_tmp
      + (int)$esp_otros_tmp
      + (int)$otros_juegos_vis_tmp
      + (int)$otros_juegos_esp_tmp
      + (int)$elim_norm_no_vis_tmp
      + (int)$elim_otros_juegos_tmp
      + (int)$elim_otros_aportes_tmp;

    $parcial_anio_loop += (int)$parcial_mes_tmp;
    $gastos_anio_loop  += q_scalar_row($conexion, "
      SELECT IFNULL(SUM(valor),0)
      FROM gastos
      WHERE anio=$yy AND mes=$mm
    ");
  }

  $fechaCorteAnual = date('Y-m-t', strtotime("$yy-$mesLimite-01"));

  $saldo_acumulado_anual = q_scalar_assoc($conexion, "
    SELECT IFNULL(SUM(
      GREATEST(IFNULL(ex.excedente,0) - IFNULL(co.consumido,0), 0)
    ),0) AS saldo
    FROM jugadores j
    LEFT JOIN (
      SELECT id_jugador, SUM(GREATEST(aporte_principal - $TOPE, 0)) AS excedente
      FROM aportes
      WHERE fecha <= '$fechaCorteAnual'
        AND id_jugador IS NOT NULL
        AND tipo_aporte IS NULL
      GROUP BY id_jugador
    ) ex ON ex.id_jugador=j.id
    LEFT JOIN (
      SELECT id_jugador, SUM(amount) AS consumido
      FROM aportes_saldo_moves
      WHERE fecha_consumo <= '$fechaCorteAnual'
      GROUP BY id_jugador
    ) co ON co.id_jugador=j.id
  ", "saldo");

  $final_anio_loop = ((int)$parcial_anio_loop - (int)$gastos_anio_loop) + (int)$saldo_acumulado_anual;
  $caja_general_acumulada += (int)$final_anio_loop;
  
  }
// otros aportes informativos
$otros_aportes_mes_info = (int)(
  $total_otros_aportes_principal
  + $total_esp_otros_aportes
  + $eliminados_otros_aportes_mes
);



// informativos eliminados
$eliminados_mes_total = q_scalar_assoc($conexion, "
  SELECT IFNULL(SUM(
    LEAST(LEAST(IFNULL(a.aporte_principal,0), $TOPE) + IFNULL(t.consumido,0), $TOPE)
  ),0) AS s
  FROM aportes a
  LEFT JOIN (
    SELECT m.target_aporte_id, SUM(m.amount) AS consumido
FROM aportes_saldo_moves m
INNER JOIN aportes s ON s.id = m.source_aporte_id
WHERE s.aporte_principal > 3000
GROUP BY m.target_aporte_id
  ) t ON t.target_aporte_id=a.id
  WHERE YEAR(a.fecha)=$anio AND MONTH(a.fecha)=$mes
    AND a.id_jugador IN ($inMes)
    AND a.tipo_aporte IS NULL
", "s");

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
  $nDias = count($days);
  $wNombre  = 16;
  $wOtros   = 13;
  $wTotal   = 13;
  $wSaldo   = 12;
  $wDeuda   = 18;

  $fixed = $wNombre + $wOtros + $wTotal + $wSaldo + $wDeuda;
  $wDia = ($nDias > 0) ? max(2.6, (100 - $fixed) / $nDias) : 0;
  ?>

  <table class="pdf-table pdf-table--aportes" width="100%" cellspacing="0" cellpadding="4">
    <colgroup>
      <col width="<?= $wNombre ?>%">
      <?php foreach ($days as $_): ?>
        <col width="<?= $wDia ?>%"><?php endforeach; ?>
      <col width="<?= $wOtros ?>%">
      <col width="<?= $wTotal ?>%">
      <col width="<?= $wSaldo ?>%">
      <col width="<?= $wDeuda ?>%">
    </colgroup>

    <thead>
      <tr>
        <th class="col-jugador" style="width:<?= $wNombre ?>%;">Jugador</th>
        <?php foreach ($days as $d): ?><th class="col-dia"><?= (int)$d ?></th><?php endforeach; ?>
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

        <td class="td-total"><strong><?= number_format((int)$total_otros_aportes_principal, 0, ',', '.') ?></strong></td>
        <td class="td-total"><strong><?= number_format((int)$total_mes_principal, 0, ',', '.') ?></strong></td>
        <td class="td-total"><strong><?= number_format((int)$total_saldo_visibles, 0, ',', '.') ?></strong></td>
        <td></td>
      </tr>
    </tfoot>
  </table>
  <div class="note" style="margin-top:6px;">
   <strong> Nota:</strong> El Total Mes de la Tabla Principal <strong> NO incluye:</strong><br>
     <strong>-</strong> Totales días normales de la Tabla Aportes Esporádicos  <strong>(De Abajo).</strong> <br>
     <strong>-</strong> Aportes de la tabla Aportantes Eliminados <strong>(Detalle).</strong> <br>
     <strong>-</strong> Aportes de la columna Otros Juego <strong>(Cada día)</strong> registrados en la tabla Otros Juegos (Principal + Esporádicos / <strong>(De Abajo)</strong>). <br>
     <strong>-</strong> Saldos de ambas tablas.
  </div><br> <br>
  <div class="note" style="margin-top:6px;">
    <strong>Para Confirmar el Total Parcial del Mes se debe:</strong>
  </div><br>
  <div class="note" style="margin-top:6px;">
    1. Sumar los totales de todos los días normales de cada tabla (Principal + Esporádicos / <strong>(De Abajo)</strong>).
  </div><br>
  <div class="note" style="margin-top:6px;">
    2. Sumar el Total Final de la tabla Otros Juegos (Principal + Esporádicos <strong>(De Abajo)</strong>).
  </div><br>
  <div class="note" style="margin-top:6px;">
    3. Sumar los otros aportes del mes de la tabla Otros Aportes Mes (solo informativo <strong>(De Abajo)</strong>).
  </div><br>
  <div class="note" style="margin-top:6px;">
    3. Sumar los aportes de la tabla Aportantes Eliminados (Detalle)<strong>(De Abajo)</strong> del mes actual <strong style="color:brown">(columna Cantidad </strong>/ <strong style="color:cornflowerblue">Filas en azul claro)</strong>. 
  </div><br>

  <div class="note" style="margin-top:6px; color: darkgreen;">
   ➦ Con esto se obtiene el <strong> total Parcial Del Mes</strong>. <br>
   ➦ Resta los <strong>Gastos del Mes</strong> para obtener el <strong> total Estimado Del Mes</strong>.<br>
   ➦ Suma los <strong> Totales Estimados De Cada Mes</strong>. <br>
   ➦ Suma los <strong>Saldos Acumulados</strong> y se obtiene el <strong style="color:goldenrod;">Total Final Año (Con Saldos Acumulados)</strong>. <br>
   ➦ Suma la <strong>Caja General Acumulada</strong> de Años Anteriores con el <strong style="color:goldenrod;">Total Final Año (Con Saldos Acumulados <strong style="color:green;">(Año Actual)</strong>)</strong>
      y se Obtiene la <strong  style="color:goldenrod;">Caja General Acumulada <strong style="color:green;">(Año Actual)</strong></strong> 
  </div> <br>

  <div class="section-block">
    <h3 class="keep-title">Planilla Aportes Esporádicos (Resumen)</h3>

    <table class="pdf-table pdf-table--aportes" width="100%" cellspacing="0" cellpadding="4">
      <colgroup>
        <col width="<?= $wNombre ?>%">
        <?php foreach ($days as $_): ?>
          <col width="<?= $wDia ?>%"><?php endforeach; ?>
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

    <div class="note" style="margin-top:6px;color: darkgreen;">
     <strong>Nota:</strong>Los “Otros Juegos” de Esporádicos se muestran en la tabla <strong>“Otros Juegos” (De abajo)</strong> con la columna TABLA = Esporádico.
    </div>
  </div> <br> <br>

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
            <td colspan="3"><strong>Total Final </strong></td>
            <td style="text-align:right;"><strong>$ <?= number_format((int)$total_otros_juegos, 0, ',', '.') ?></strong></td>
          </tr>
        </tfoot>
      </table>
    <?php endif; ?>
  </div> <br> <br>

  <div class="sep"></div>

      <table class="totales-table final">
        <tr>
          <td class="label">
            <strong>Otros Aportes Mes</strong>
            <div class="note">(Solo Informativo)</div>
          </td>
          <td class="money">$ <?= number_format((int)$otros_aportes_mes_info, 0, ',', '.') ?></td>
        </tr>
        <tr>
          <td class="label">
            <strong>Otros Aportes Año</strong>
            <div class="note">(Solo Informativo)</div>
          </td>
          <td class="money">$ <?= number_format((int)$otros_aportes_anio_info, 0, ',', '.') ?></td>
        </tr>
      </table>
  <div class="section-block">
    <h3 class="keep-title">Aportantes Eliminados (Detalle)</h3>
    <div class="note" style="margin-top:6px;">
         <strong>Nota:</strong> filas del mes actual se marcan en <strong style="color:cornflowerblue">azul claro</strong> y las filas de meses anteriores se marcan con <strong style="color:brown"> Filas Rojas </strong>.
      </div>
    
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
                $esOtroJuego = ((int)date('N', strtotime($f)) !== 3 && (int)date('N', strtotime($f)) !== 6);

                $rowCls = $esMesActual ? "row-mes-actual" : "row-mes-previo";
                if ($esOtroJuego) $rowCls .= " elim-otros";

                $aportanteCell = "";
                if ($n === 1) {
                  $aportanteCell = "<strong>" . htmlspecialchars($nombre) . "</strong>";
                } elseif ($n === 2) {
                  $aportanteCell = "<span class='baja-pill'>Baja: " . htmlspecialchars($fechaBajaLabel) . "</span>";
                }

                $fechaLabel = htmlspecialchars(date("d-m-Y", strtotime($f)));
                if ($esOtroJuego) {
                  $fechaLabel .= "<br><span class='note'>(Aporte otro juego)</span>";
                }
                ?>
                <tr class="<?= $rowCls ?>">
                  <td><?= $aportanteCell ?></td>
                  <td style="text-align:right;"><?= $n ?></td>
                  <td><?= $fechaLabel ?></td>
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

    <?php endif; ?>
  </div>
  <div class="section-block">
    <h3 class="keep-title">Resumen General</h3>
    <h3 class="keep-title">Totales (COP)</h3><br>

    <div class="totales-box">

      <table class="totales-table">
        <tr>
          <td class="label">
            <strong>Total Parcial Mes Incluye:</strong>
            <div class="note">
              - Aportes de Cada Partido (Miércoles / Sábado) de Ambas Planillas.<br>
              - Aportes de los Jugadores Eliminados. <br>
                Estos aportes se pueden ver en la tabla Aportantes Eliminados (Detalle).<br>
              - Aportes de Columna (Otro Juego) de Ambas Planillas (Cada Día Jugado).<br>
                Estos aportes están registrados en la tabla Otros Juegos (Principal + Esporádicos). <br>
              - Otros Aportes de Ambas Tablas.
            </div>
          </td>
          <td class="money"></td>
        </tr>
      </table>

      <div class="sep"></div>

      <table class="totales-table">
        <tr>
          <td class="label">
            <strong>Total Parcial Mes</strong>
            <div class="note">(Activos + Eliminados + Otros Aportes / Sin Saldo)</div>
          </td>
          <td class="money">$ <?= number_format((int)$total_parcial_mes_resumen, 0, ',', '.') ?></td>
        </tr>
        <tr>
          <td class="label">
            <strong>Total Parcial Año</strong>
            <div class="note">(Activos + Eliminados + Otros Aportes / Sin Saldo)</div>
          </td>
          <td class="money">$ <?= number_format((int)$total_parcial_anio_resumen, 0, ',', '.') ?></td>
        </tr>
      </table>

      <div class="sep"></div>

      <table class="totales-table">
        <tr>
          <td class="label"><strong>Gastos del Mes</strong></td>
          <td class="money neg">$ <?= number_format((int)$gastos_mes_resumen, 0, ',', '.') ?></td>
        </tr>
        <tr>
          <td class="label"><strong>Gastos del Año</strong></td>
          <td class="money neg">$ <?= number_format((int)$gastos_anio_resumen, 0, ',', '.') ?></td>
        </tr>
      </table>

      <div class="sep"></div>

      <table class="totales-table">
        <tr>
          <td class="label">
            <strong>Total Estimado Final Mes</strong>
            <div class="note">(Total Parcial Mes - gastos / Sumable Por Mes / Sin Saldos)</div>
          </td>
          <td class="money strong">$ <?= number_format((int)$total_estimado_final_mes, 0, ',', '.') ?></td>
        </tr>
        <tr>
          <td class="label">
            <strong>Total Estimado Final Año</strong>
            <div class="note">(Total Parcial Año - gastos / Sumable Por Mes / Sin Saldos)</div>
          </td>
          <td class="money strong">$ <?= number_format((int)$total_estimado_final_anio, 0, ',', '.') ?></td>
        </tr>
      </table>

      <div class="sep"></div>

      <table class="totales-table">
        <tr>
          <td class="label"><strong>Saldo Actual Mes</strong>
            <div class="note">(Saldos Generados Este Mes)</div>
          </td>
          <td class="money">$ <?= number_format((int)$saldo_actual_mes, 0, ',', '.') ?></td>
        </tr>
        <tr>
          <td class="label"><strong>Saldos Acumulados</strong>
            <div class="note">(Saldos Generados Este Mes + Heredados Meses Anteriores)</div>
          </td>
          <td class="money">$ <?= number_format((int)$saldos_acumulados, 0, ',', '.') ?></td>
        </tr>
      </table>

      <div class="sep"></div>

      <table class="totales-table final">
        <tr>
          <td class="label">
            <strong>Total Final Año (Con Saldos Acumulados)</strong>
            <div class="note">(Total Estimado Final Año + Saldo acumulado)</div>
          </td>
          <td class="money strong">$ <?= number_format((int)$total_final_anio_con_saldos, 0, ',', '.') ?></td>
        </tr>

        <tr>
          <td class="label">
            <strong>Caja General Acumulada</strong>
            <div class="note">(Suma de los Totales Finales de Años Anteriores + Año Actual hasta el mes seleccionado)</div>
          </td>
          <td class="money strong">$ <?= number_format((int)$caja_general_acumulada, 0, ',', '.') ?></td>
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