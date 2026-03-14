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
    - o eliminados después del corte del mes
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

  $items = [];
  $total_general = 0;

  /* =====================================================
     1) PRINCIPAL: otros juegos de jugadores visibles
  ===================================================== */
  if (!empty($jugadoresVisibles)) {
    $inJug = implode(",", array_map("intval", $jugadoresVisibles));

    $sqlPrincipal = "
      SELECT
        a.id,
        a.id_jugador,
        j.nombre,
        a.fecha,
        'Principal' AS tabla,
        LEAST(
          LEAST(IFNULL(a.aporte_principal,0), ?) + IFNULL(m.consumido,0),
          ?
        ) AS efectivo_total
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
        AND a.tipo_aporte IS NULL
      HAVING efectivo_total > 0
      ORDER BY a.fecha ASC, j.nombre ASC, a.id ASC
    ";

    $stmt = $conexion->prepare($sqlPrincipal);
    $stmt->bind_param("iiii", $TOPE, $TOPE, $mes, $anio);
    $stmt->execute();
    $res = $stmt->get_result();

    while ($row = $res->fetch_assoc()) {
      $val = (int)$row["efectivo_total"];
      $total_general += $val;

      $items[] = [
        "id"             => (int)$row["id"],
        "jugador_id"     => (int)$row["id_jugador"],
        "nombre"         => $row["nombre"],
        "fecha"          => $row["fecha"],
        "fecha_label"    => date("d-m-Y", strtotime($row["fecha"])),
        "tabla"          => "Principal",
        "efectivo_total" => $val,
      ];
    }

    $stmt->close();
  }

  /* =====================================================
     2) ESPORÁDICOS: otros juegos de la tabla esporádicos
  ===================================================== */
  $sqlEsp = "
    SELECT
      id,
      fecha,
      aporte_principal AS efectivo_total,
      esporadico_slot
    FROM aportes
    WHERE tipo_aporte = 'esporadico'
      AND YEAR(fecha) = ?
      AND MONTH(fecha) = ?
      AND DAYOFWEEK(fecha) NOT IN (4,7)
      AND IFNULL(aporte_principal,0) > 0
    ORDER BY fecha ASC, esporadico_slot ASC, id ASC
  ";

  $stmt = $conexion->prepare($sqlEsp);
  $stmt->bind_param("ii", $anio, $mes);
  $stmt->execute();
  $res = $stmt->get_result();

  while ($row = $res->fetch_assoc()) {
    $val = (int)$row["efectivo_total"];
    $slot = (int)($row["esporadico_slot"] ?? 0);

    $total_general += $val;

    $items[] = [
      "id"             => (int)$row["id"],
      "jugador_id"     => null,
      "nombre"         => $slot > 0 ? "Esporádico #{$slot}" : "Esporádico",
      "fecha"          => $row["fecha"],
      "fecha_label"    => date("d-m-Y", strtotime($row["fecha"])),
      "tabla"          => "Esporádico",
      "efectivo_total" => $val,
    ];
  }

  $stmt->close();

  usort($items, function($a, $b) {
    if ($a["fecha"] === $b["fecha"]) {
      return strcmp((string)$a["nombre"], (string)$b["nombre"]);
    }
    return strcmp((string)$a["fecha"], (string)$b["fecha"]);
  });

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