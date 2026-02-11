<?php
include "../../conexion.php";
require_once __DIR__ . "/../auth/auth.php";
protegerAdmin();

header("Content-Type: application/json; charset=utf-8");

$mes  = isset($_GET['mes'])  ? intval($_GET['mes'])  : intval(date('n'));
$anio = isset($_GET['anio']) ? intval($_GET['anio']) : intval(date('Y'));

$TOPE = 3000;

// Subquery: saldo usado por aporte (por target_aporte_id)
$sql = "
SELECT 
  t.fecha,
  COUNT(*) AS jugadores,
  SUM(t.real) AS real_total,
  SUM(t.excedente) AS excedente_total,
  SUM(t.saldo_target) AS saldo_usado_total,
  SUM(t.efectivo) AS efectivo_total
FROM (
  SELECT 
    a.id,
    a.fecha,
    IFNULL(a.aporte_principal,0) AS real,
    GREATEST(IFNULL(a.aporte_principal,0) - ?, 0) AS excedente,
    IFNULL(ms.saldo_target,0) AS saldo_target,
    LEAST(LEAST(IFNULL(a.aporte_principal,0), ?) + IFNULL(ms.saldo_target,0), ?) AS efectivo
  FROM aportes a
  LEFT JOIN (
    SELECT target_aporte_id, IFNULL(SUM(amount),0) AS saldo_target
    FROM aportes_saldo_moves
    GROUP BY target_aporte_id
  ) ms ON ms.target_aporte_id = a.id
  WHERE YEAR(a.fecha)=? AND MONTH(a.fecha)=?
) t
WHERE 
  -- MySQL DAYOFWEEK: Sunday=1 ... Wednesday=4 ... Saturday=7
  DAYOFWEEK(t.fecha) NOT IN (4,7) 
  AND (t.real > 0 OR t.saldo_target > 0)
GROUP BY t.fecha
ORDER BY t.fecha ASC
";

$stmt = $conexion->prepare($sql);
$stmt->bind_param("iiiii", $TOPE, $TOPE, $TOPE, $anio, $mes);
$stmt->execute();
$res = $stmt->get_result();

$items = [];
$totalGeneral = 0;

while ($row = $res->fetch_assoc()) {
  $fecha = $row["fecha"]; // YYYY-MM-DD
  $row["fecha_label"] = date("d-m-Y", strtotime($fecha));

  $row["jugadores"] = (int)$row["jugadores"];
  $row["real_total"] = (int)$row["real_total"];
  $row["excedente_total"] = (int)$row["excedente_total"];
  $row["saldo_usado_total"] = (int)$row["saldo_usado_total"];
  $row["efectivo_total"] = (int)$row["efectivo_total"];

  $totalGeneral += $row["efectivo_total"];
  $items[] = $row;
}

$stmt->close();

echo json_encode([
  "ok" => true,
  "cantidad" => count($items),
  "total_general" => $totalGeneral,
  "items" => $items
]);
