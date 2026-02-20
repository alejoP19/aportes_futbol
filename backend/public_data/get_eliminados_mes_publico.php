<?php
header("Content-Type: application/json; charset=utf-8");
require_once __DIR__ . "/../../conexion.php";
$TOPE = 3000;

$mes  = intval($_GET['mes']  ?? date('n'));
$anio = intval($_GET['anio'] ?? date('Y'));

if ($mes < 1 || $mes > 12) $mes = (int)date('n');
if ($anio < 1900) $anio = (int)date('Y');

$inicioMes = sprintf("%04d-%02d-01", $anio, $mes);
$finMes    = date('Y-m-t', strtotime($inicioMes));
$fechaCorte = $finMes;

// 1) jugadores eliminados EN ESTE MES (fecha_baja dentro del mes)
$stmt = $conexion->prepare("
  SELECT id, nombre, fecha_baja
  FROM jugadores
  WHERE activo = 0
    AND fecha_baja IS NOT NULL
    AND fecha_baja >= ?
    AND fecha_baja <= ?
  ORDER BY fecha_baja ASC, nombre ASC
");
$stmt->bind_param("ss", $inicioMes, $finMes);
$stmt->execute();
$res = $stmt->get_result();

$items = [];
$total_eliminados_mes  = 0;
$total_eliminados_anio = 0;
$total_saldo_eliminados = 0;

while ($j = $res->fetch_assoc()) {
  $jid = (int)$j["id"];

  // A) total mes (efectivo) igual a tus reglas: LEAST(aporte_principal + consumo_target, 3000)
  $qMes = $conexion->prepare("
    SELECT IFNULL(SUM(
      LEAST(a.aporte_principal + IFNULL(t.consumido,0), ?)
    ),0) AS total_mes
    FROM aportes a
    LEFT JOIN (
      SELECT target_aporte_id, SUM(amount) AS consumido
      FROM aportes_saldo_moves
      GROUP BY target_aporte_id
    ) t ON t.target_aporte_id = a.id
    WHERE a.id_jugador = ?
      AND YEAR(a.fecha) = ?
      AND MONTH(a.fecha) = ?
  ");
  $qMes->bind_param("iiii", $TOPE, $jid, $anio, $mes);
  $qMes->execute();
  $totalMes = (int)($qMes->get_result()->fetch_assoc()["total_mes"] ?? 0);
  $qMes->close();

  // B) total año (hasta mes)
  $qAnio = $conexion->prepare("
    SELECT IFNULL(SUM(
      LEAST(a.aporte_principal + IFNULL(t.consumido,0), ?)
    ),0) AS total_anio
    FROM aportes a
    LEFT JOIN (
      SELECT target_aporte_id, SUM(amount) AS consumido
      FROM aportes_saldo_moves
      GROUP BY target_aporte_id
    ) t ON t.target_aporte_id = a.id
    WHERE a.id_jugador = ?
      AND YEAR(a.fecha) = ?
      AND MONTH(a.fecha) <= ?
  ");
  $qAnio->bind_param("iiii", $TOPE, $jid, $anio, $mes);
  $qAnio->execute();
  $totalAnio = (int)($qAnio->get_result()->fetch_assoc()["total_anio"] ?? 0);
  $qAnio->close();

  // C) saldo al corte (fin de mes)
  $qEx = $conexion->prepare("
    SELECT IFNULL(SUM(GREATEST(aporte_principal - ?, 0)), 0) AS excedente
    FROM aportes
    WHERE id_jugador = ?
      AND fecha <= ?
  ");
  $qEx->bind_param("iis", $TOPE, $jid, $fechaCorte);
  $qEx->execute();
  $excedente = (int)($qEx->get_result()->fetch_assoc()["excedente"] ?? 0);
  $qEx->close();

  $qCo = $conexion->prepare("
    SELECT IFNULL(SUM(amount), 0) AS consumido
    FROM aportes_saldo_moves
    WHERE id_jugador = ?
      AND fecha_consumo <= ?
  ");
  $qCo->bind_param("is", $jid, $fechaCorte);
  $qCo->execute();
  $consumido = (int)($qCo->get_result()->fetch_assoc()["consumido"] ?? 0);
  $qCo->close();

  $saldo = max(0, $excedente - $consumido);

  $total_eliminados_mes  += $totalMes;
  $total_eliminados_anio += $totalAnio;
  $total_saldo_eliminados += $saldo;

  $items[] = [
    "id" => $jid,
    "nombre" => $j["nombre"],
    "fecha_baja" => $j["fecha_baja"],
    "total_mes" => $totalMes,
    "total_anio" => $totalAnio,
    "saldo" => $saldo
  ];
}

$stmt->close();

echo json_encode([
  "ok" => true,
  "mes" => $mes,
  "anio" => $anio,
  "inicio" => $inicioMes,
  "fin" => $finMes,
  "totales" => [
    "eliminados_mes" => $total_eliminados_mes,
    "eliminados_anio" => $total_eliminados_anio,
    "saldo_eliminados" => $total_saldo_eliminados,
  ],
  "items" => $items
]);
