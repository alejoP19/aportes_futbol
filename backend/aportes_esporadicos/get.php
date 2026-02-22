<?php
header("Content-Type: application/json; charset=utf-8");
require_once __DIR__ . "/../../conexion.php";
require_once __DIR__ . "/../auth/auth.php";
protegerAdmin();

$mes  = intval($_GET['mes']  ?? date('n'));
$anio = intval($_GET['anio'] ?? date('Y'));
$otro = intval($_GET['otro'] ?? 0);

if ($mes < 1 || $mes > 12) $mes = (int)date('n');
if ($anio < 1900) $anio = (int)date('Y');

$daysInMonth = cal_days_in_month(CAL_GREGORIAN, $mes, $anio);

/* días válidos (mié=3, sáb=6) */
$dias_validos = [];
for ($d=1; $d<=$daysInMonth; $d++){
  $fecha = sprintf("%04d-%02d-%02d", $anio, $mes, $d);
  $dow = date("N", strtotime($fecha));
  if ($dow == 3 || $dow == 6) $dias_validos[] = $d;
}

/* escoger default de otro día (no mié/sáb) */
function pick_default_otro_dia($dias_validos, $daysInMonth){
  if (!in_array(28, $dias_validos) && 28 <= $daysInMonth) return 28;
  for ($d=1; $d<=$daysInMonth; $d++){
    if (!in_array($d, $dias_validos)) return $d;
  }
  return 1;
}
if ($otro < 1 || $otro > $daysInMonth) $otro = pick_default_otro_dia($dias_validos, $daysInMonth);
if (in_array($otro, $dias_validos)) $otro = pick_default_otro_dia($dias_validos, $daysInMonth);

$fechaOtro = sprintf("%04d-%02d-%02d", $anio, $mes, $otro);

$slots = intval($_GET['slots'] ?? 10);
if ($slots < 1) $slots = 10;
if ($slots > 22) $slots = 22;

/* ==========================
   1) APORTES POR DÍA (tipo_aporte='esporadico')
   ========================== */
$stmt = $conexion->prepare("
  SELECT fecha, esporadico_slot, aporte_principal
  FROM aportes
  WHERE tipo_aporte='esporadico'
    AND YEAR(fecha)=?
    AND MONTH(fecha)=?
");
$stmt->bind_param("ii", $anio, $mes);
$stmt->execute();
$res = $stmt->get_result();

$map = []; // [$slot][$fecha] = valor
while($r = $res->fetch_assoc()){
  $slot = intval($r["esporadico_slot"] ?? 0);
  if ($slot < 1 || $slot > 22) continue;
  $f = $r["fecha"];
  $map[$slot][$f] = intval($r["aporte_principal"] ?? 0);
}
$stmt->close();

/* ==========================
   2) META POR SLOT (tipo_aporte='esporadico_otro')
   Guardada con fecha dummy: YYYY-MM-01
   ========================== */
$fechaMeta = sprintf("%04d-%02d-01", $anio, $mes);

$meta_by_slot = []; // [$slot] => ["otro_aporte"=>int, "nota"=>string]
$stmt = $conexion->prepare("
  SELECT esporadico_slot AS slot,
         IFNULL(aporte_principal,0) AS otro_aporte,
         IFNULL(nota,'') AS nota
  FROM aportes
  WHERE tipo_aporte='esporadico_otro'
    AND fecha=?
");
$stmt->bind_param("s", $fechaMeta);
$stmt->execute();
$res = $stmt->get_result();
while($r = $res->fetch_assoc()){
  $s = (int)$r["slot"];
  if ($s < 1 || $s > 22) continue;
  $meta_by_slot[$s] = [
    "otro_aporte" => (int)$r["otro_aporte"],
    "nota" => (string)$r["nota"]
  ];
}
$stmt->close();

/* columnas (fechas reales) */
$fechasDias = [];
foreach($dias_validos as $d){
  $fechasDias[] = sprintf("%04d-%02d-%02d", $anio, $mes, $d);
}

$rows = [];
$totals_by_date = []; // [$fecha] = sum

$total_otros_aporte = 0;

/* construir filas */
for($slot=1; $slot <= $slots; $slot++){
  $row = [
    "slot" => $slot,
    "dias" => [],
    "otro" => 0,
    "otros_aporte" => 0,
    "nota" => ""
  ];

  // días mié/sáb
  foreach($fechasDias as $f){
    $v = intval($map[$slot][$f] ?? 0);
    $row["dias"][$f] = $v;
    $totals_by_date[$f] = ($totals_by_date[$f] ?? 0) + $v;
  }

  // otro día elegido (MISMO que planilla)
  $vOtro = intval($map[$slot][$fechaOtro] ?? 0);
  $row["otro"] = $vOtro;
  $totals_by_date[$fechaOtro] = ($totals_by_date[$fechaOtro] ?? 0) + $vOtro;

  // meta (otro_aporte + nota)
  if (isset($meta_by_slot[$slot])) {
    $row["otros_aporte"] = (int)$meta_by_slot[$slot]["otro_aporte"];
    $row["nota"] = (string)$meta_by_slot[$slot]["nota"];
  }

  $total_otros_aporte += (int)$row["otros_aporte"];

  $rows[] = $row;
}

$total_mes_esporadicos = 0;
foreach($totals_by_date as $sum) $total_mes_esporadicos += (int)$sum;

echo json_encode([
  "ok" => true,
  "mes" => $mes,
  "anio" => $anio,
  "dias_validos" => $dias_validos,
  "otro_dia" => $otro,
  "fecha_otro" => $fechaOtro,
  "slots" => $slots,
  "rows" => $rows,
  "totals_by_date" => $totals_by_date,
  "total_mes_esporadicos" => $total_mes_esporadicos,
  "meta_by_slot" => $meta_by_slot,
  "total_otros_aporte" => $total_otros_aporte,
], JSON_UNESCAPED_UNICODE);