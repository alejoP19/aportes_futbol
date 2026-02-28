<?php
try {
  // ... todo tu código ...
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode([
    "ok" => false,
    "error" => $e->getMessage(),
  ], JSON_UNESCAPED_UNICODE);
  exit;
}
header("Content-Type: application/json; charset=utf-8");
require_once __DIR__ . "/../../conexion.php";
require_once __DIR__ . "/../auth/auth.php";
protegerAdmin();

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {
  $TOPE = 3000;

  $mes  = intval($_GET['mes']  ?? date('n'));
  $anio = intval($_GET['anio'] ?? date('Y'));
  if ($mes < 1 || $mes > 12) $mes = (int)date('n');
  if ($anio < 1900) $anio = (int)date('Y');

  $inicioMes = sprintf("%04d-%02d-01", $anio, $mes);
  $finMes    = date('Y-m-t', strtotime($inicioMes));

  // eliminados EN ESTE MES
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

  $players = [];
  $rows    = [];

  $total_eliminados_mes   = 0; // solo del mes seleccionado
  $total_general_aportes  = 0; // aportes listados (hasta mes) sumados
  $total_general_saldo    = 0; // saldo fin mes sumado por eliminado

  while ($j = $res->fetch_assoc()) {
    $jid = (int)$j["id"];

    // Aportes registrados del AÑO hasta el MES seleccionado
    $qAportes = $conexion->prepare("
      SELECT
        a.id,
        a.fecha,
        LEAST(
          LEAST(IFNULL(a.aporte_principal,0), ?) + IFNULL(t.consumido,0),
          ?
        ) AS cantidad
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
    $qAportes->bind_param("iiiii", $TOPE, $TOPE, $jid, $anio, $mes);
    $qAportes->execute();
    $rr = $qAportes->get_result();

    $n = 0;
    $total_aportes_jugador = 0;
    $total_mes_jugador     = 0;

    while ($r = $rr->fetch_assoc()) {
      $n++;
      $fecha    = date("Y-m-d", strtotime($r["fecha"]));
      $cantidad = (int)$r["cantidad"];

      $mRow = (int)date("n", strtotime($fecha));
      $aRow = (int)date("Y", strtotime($fecha));
      $es_mes_actual = ($mRow === $mes && $aRow === $anio) ? 1 : 0;

      // saldo al corte de ESA fecha
      $qEx = $conexion->prepare("
        SELECT IFNULL(SUM(GREATEST(aporte_principal - ?, 0)), 0) AS ex
        FROM aportes
        WHERE id_jugador = ?
          AND tipo_aporte IS NULL
          AND fecha <= ?
      ");
      $qEx->bind_param("iis", $TOPE, $jid, $fecha);
      $qEx->execute();
      $excedente = (int)($qEx->get_result()->fetch_assoc()["ex"] ?? 0);
      $qEx->close();

      $qCo = $conexion->prepare("
        SELECT IFNULL(SUM(amount), 0) AS co
        FROM aportes_saldo_moves
        WHERE id_jugador = ?
          AND fecha_consumo <= ?
      ");
      $qCo->bind_param("is", $jid, $fecha);
      $qCo->execute();
      $consumido = (int)($qCo->get_result()->fetch_assoc()["co"] ?? 0);
      $qCo->close();

      $saldo = max(0, $excedente - $consumido);

      $total_aportes_jugador += $cantidad;
      if ($es_mes_actual) $total_mes_jugador += $cantidad;

      $rows[] = [
        "jugador_id"   => $jid,
        "n"            => $n,
        "fecha"        => $fecha,
        "cantidad"     => $cantidad,
        "total"        => $cantidad, // NO acumulado (como pediste)
        "saldo"        => $saldo,
        "es_mes_actual"=> $es_mes_actual
      ];
    }
    $qAportes->close();

    // saldo fin de mes
    $fechaCorteMes = $finMes;

    $qExFin = $conexion->prepare("
      SELECT IFNULL(SUM(GREATEST(aporte_principal - ?, 0)), 0) AS ex
      FROM aportes
      WHERE id_jugador = ?
        AND tipo_aporte IS NULL
        AND fecha <= ?
    ");
    $qExFin->bind_param("iis", $TOPE, $jid, $fechaCorteMes);
    $qExFin->execute();
    $exFin = (int)($qExFin->get_result()->fetch_assoc()["ex"] ?? 0);
    $qExFin->close();

    $qCoFin = $conexion->prepare("
      SELECT IFNULL(SUM(amount), 0) AS co
      FROM aportes_saldo_moves
      WHERE id_jugador = ?
        AND fecha_consumo <= ?
    ");
    $qCoFin->bind_param("is", $jid, $fechaCorteMes);
    $qCoFin->execute();
    $coFin = (int)($qCoFin->get_result()->fetch_assoc()["co"] ?? 0);
    $qCoFin->close();

    $saldo_fin_mes = max(0, $exFin - $coFin);

    $players[] = [
      "id"            => $jid,
      "nombre"        => $j["nombre"],
      "fecha_baja"    => $j["fecha_baja"], // ✅ bien
      "total_aportes" => $total_aportes_jugador,
      "saldo_fin_mes" => $saldo_fin_mes,
      "total_mes"     => $total_mes_jugador
    ];

    $total_eliminados_mes  += $total_mes_jugador;
    $total_general_aportes += $total_aportes_jugador;
    $total_general_saldo   += $saldo_fin_mes;
  }

  $stmt->close();

  echo json_encode([
    "ok" => true,
    "mes" => $mes,
    "anio" => $anio,
    "totales" => [
      "eliminados_mes" => $total_eliminados_mes,           // para el card
      "total_general_aportes" => $total_general_aportes,   // footer modal
      "total_general_saldo" => $total_general_saldo        // footer modal
    ],
    "players" => $players,
    "rows" => $rows
  ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode([
    "ok" => false,
    "error" => $e->getMessage(),
  ], JSON_UNESCAPED_UNICODE);
}