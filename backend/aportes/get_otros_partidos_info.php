<?php
include "../../conexion.php";
require_once __DIR__ . "/../auth/auth.php";
protegerAdmin();

header("Content-Type: application/json; charset=utf-8");
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");

$mes  = isset($_GET['mes'])  ? intval($_GET['mes'])  : intval(date('n'));
$anio = isset($_GET['anio']) ? intval($_GET['anio']) : intval(date('Y'));

$TOPE = 3000;

try {
  $fechaCorteMes = date('Y-m-t', strtotime("$anio-$mes-01"));

  /*
    SOLO jugadores visibles en la planilla principal:
    - activos
    - o eliminados después del corte del mes (siguen visibles en ese mes)
    Así excluimos los eliminados del modal, porque esos ya se muestran aparte.
  */
  $jugadoresVisibles = [];
  $resJug = $conexion->query("
    SELECT id
    FROM jugadores
    WHERE
      activo = 1
      OR (activo = 0 AND (fecha_baja IS NULL OR fecha_baja > '$fechaCorteMes'))
  ");

  while ($r = $resJug->fetch_assoc()) {
    $jugadoresVisibles[] = (int)$r["id"];
  }

  if (empty($jugadoresVisibles)) {
    echo json_encode([
      "ok" => true,
      "cantidad" => 0,
      "total_general" => 0,
      "items" => []
    ]);
    exit;
  }

  $inJug = implode(",", array_map("intval", $jugadoresVisibles));

  /*
    Mostrar CADA aporte por fila:
    - tipo_aporte IS NULL => aporte principal / otro juego principal
    - tipo_aporte='esporadico' => aporte esporádico / otro juego esporádico
    Excluimos:
    - esporadico_otro
    Solo días NO miércoles/sábado
  */
  $sql = "
    SELECT
      a.id,
      a.id_jugador,
      j.nombre,
      a.fecha,
      CASE
        WHEN a.tipo_aporte = 'esporadico' THEN 'Esporádico'
        ELSE 'Principal'
      END AS tabla,
      CASE
        WHEN a.tipo_aporte = 'esporadico' THEN IFNULL(a.aporte_principal,0)
        ELSE LEAST(
          LEAST(IFNULL(a.aporte_principal,0), ?) + IFNULL(m.consumido,0),
          ?
        )
      END AS efectivo_total
    FROM aportes a
    INNER JOIN jugadores j ON j.id = a.id_jugador
    LEFT JOIN (
      SELECT target_aporte_id, IFNULL(SUM(amount),0) AS consumido
      FROM aportes_saldo_moves
      GROUP BY target_aporte_id
    ) m ON m.target_aporte_id = a.id
    WHERE MONTH(a.fecha) = ?
      AND YEAR(a.fecha) = ?
      AND DAYOFWEEK(a.fecha) NOT IN (4,7)
      AND a.id_jugador IN ($inJug)
      AND (a.tipo_aporte IS NULL OR a.tipo_aporte = 'esporadico')
    HAVING efectivo_total > 0
    ORDER BY a.fecha ASC, j.nombre ASC, a.id ASC
  ";

  $stmt = $conexion->prepare($sql);
  $stmt->bind_param("iiii", $TOPE, $TOPE, $mes, $anio);
  $stmt->execute();
  $res = $stmt->get_result();

  $items = [];
  $total_general = 0;

  while ($row = $res->fetch_assoc()) {
    $val = (int)$row["efectivo_total"];
    $total_general += $val;

    $items[] = [
      "id"            => (int)$row["id"],
      "jugador_id"    => (int)$row["id_jugador"],
      "nombre"        => $row["nombre"],
      "fecha"         => $row["fecha"],
      "fecha_label"   => date("d-m-Y", strtotime($row["fecha"])),
      "tabla"         => $row["tabla"],
      "efectivo_total"=> $val,
    ];
  }

  $stmt->close();

  echo json_encode([
    "ok" => true,
    "cantidad" => count($items),
    "total_general" => $total_general,
    "items" => $items
  ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
  echo json_encode([
    "ok" => false,
    "msg" => "Error: " . $e->getMessage()
  ], JSON_UNESCAPED_UNICODE);
}