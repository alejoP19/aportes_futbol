<?php
include "../../conexion.php";
header("Content-Type: application/json; charset=utf-8");
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");

$mes  = isset($_GET['mes'])  ? intval($_GET['mes'])  : intval(date('n'));
$anio = isset($_GET['anio']) ? intval($_GET['anio']) : intval(date('Y'));

$TOPE = 3000;

try {
  $items = [];
  $total_general = 0;
  $fechaCorteMes = date('Y-m-t', strtotime("$anio-$mes-01"));

  /* =====================================================
     1) PRINCIPAL: jugadores visibles en planilla pública
  ===================================================== */
  $sqlPrincipal = "
    SELECT
      a.id,
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
    WHERE YEAR(a.fecha) = ?
      AND MONTH(a.fecha) = ?
      AND DAYOFWEEK(a.fecha) NOT IN (4,7)
      AND a.tipo_aporte IS NULL
      AND (
        j.activo = 1
        OR (j.activo = 0 AND (j.fecha_baja IS NULL OR j.fecha_baja > ?))
      )
    HAVING efectivo_total > 0
    ORDER BY a.fecha ASC, j.nombre ASC, a.id ASC
  ";

  $stmt = $conexion->prepare($sqlPrincipal);
  $stmt->bind_param("iiiis", $TOPE, $TOPE, $anio, $mes, $fechaCorteMes);
  $stmt->execute();
  $res = $stmt->get_result();

  while ($row = $res->fetch_assoc()) {
    $val = (int)$row["efectivo_total"];
    $total_general += $val;

    $items[] = [
      "id"             => (int)$row["id"],
      "nombre"         => $row["nombre"],
      "fecha"          => $row["fecha"],
      "fecha_label"    => date("d-m-Y", strtotime($row["fecha"])),
      "tabla"          => "Principal",
      "efectivo_total" => $val,
    ];
  }
  $stmt->close();

  /* =====================================================
     2) ESPORÁDICOS
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
      "nombre"         => $slot > 0 ? "Esporádico #{$slot}" : "Esporádico",
      "fecha"          => $row["fecha"],
      "fecha_label"    => date("d-m-Y", strtotime($row["fecha"])),
      "tabla"          => "Esporádico",
      "efectivo_total" => $val,
    ];
  }
  $stmt->close();

  usort($items, function($a, $b){
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