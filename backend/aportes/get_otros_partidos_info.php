<?php
include "../../conexion.php";
require_once __DIR__ . "/../auth/auth.php";
protegerAdmin();

header("Content-Type: application/json; charset=utf-8");
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");

$mes  = isset($_GET['mes'])  ? intval($_GET['mes'])  : intval(date('n'));
$anio = isset($_GET['anio']) ? intval($_GET['anio']) : intval(date('Y'));

$TOPE = 3000;

/*
  Queremos: días NO miércoles/sábado dentro del mes,
  con total efectivo (lo que cuenta en tu planilla):
  efectivo_por_aporte = LEAST( LEAST(aporte_principal,3000) + consumido_target, 3000 )
*/

try {
  $sql = "
  SELECT 
    a.fecha,
    SUM(
      CASE 
        WHEN a.tipo_aporte = 'esporadico' THEN IFNULL(a.aporte_principal,0)
        ELSE LEAST(
          LEAST(IFNULL(a.aporte_principal,0), ?) + IFNULL(m.consumido,0),
          ?
        )
      END
    ) AS efectivo_total
  FROM aportes a
  LEFT JOIN (
    SELECT target_aporte_id, IFNULL(SUM(amount),0) AS consumido
    FROM aportes_saldo_moves
    GROUP BY target_aporte_id
  ) m ON m.target_aporte_id = a.id
  WHERE MONTH(a.fecha) = ?
    AND YEAR(a.fecha) = ?
    AND DAYOFWEEK(a.fecha) NOT IN (4,7)
    AND (a.tipo_aporte IS NULL OR a.tipo_aporte <> 'esporadico_otro')
  GROUP BY a.fecha
  HAVING efectivo_total > 0
  ORDER BY a.fecha ASC
";

  $stmt = $conexion->prepare($sql);
  $stmt->bind_param("iiii", $TOPE, $TOPE, $mes, $anio);
  $stmt->execute();
  $res = $stmt->get_result();

  $items = [];
  $total_general = 0;

  while ($row = $res->fetch_assoc()) {
    $fecha = $row["fecha"];
    $val = (int)$row["efectivo_total"];
    $total_general += $val;

    $items[] = [
      "fecha" => $fecha,
      "fecha_label" => date("d-m-Y", strtotime($fecha)),
      "efectivo_total" => $val,
    ];
  }

  $stmt->close();

  echo json_encode([
    "ok" => true,
    "cantidad" => count($items),
    "total_general" => $total_general,
    "items" => $items
  ]);
} catch (Throwable $e) {
  echo json_encode(["ok" => false, "msg" => "Error: " . $e->getMessage()]);
}
