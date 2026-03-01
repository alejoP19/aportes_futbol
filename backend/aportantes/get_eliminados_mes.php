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

function column_exists(mysqli $cx, string $table, string $col): bool {
  $db = $cx->query("SELECT DATABASE() AS db")->fetch_assoc()['db'] ?? '';
  if (!$db) return false;

  $stmt = $cx->prepare("
    SELECT COUNT(*) AS c
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = ?
      AND TABLE_NAME = ?
      AND COLUMN_NAME = ?
  ");
  $stmt->bind_param("sss", $db, $table, $col);
  $stmt->execute();
  $c = (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0);
  $stmt->close();
  return $c > 0;
}

function saldo_a_fecha(mysqli $cx, int $jid, string $fechaCorte, int $TOPE): int {
  // excedente acumulado hasta fecha (aportes - TOPE)
  $qEx = $cx->prepare("
    SELECT IFNULL(SUM(GREATEST(aporte_principal - ?, 0)), 0) AS ex
    FROM aportes
    WHERE id_jugador = ?
      AND tipo_aporte IS NULL
      AND fecha <= ?
  ");
  $qEx->bind_param("iis", $TOPE, $jid, $fechaCorte);
  $qEx->execute();
  $ex = (int)($qEx->get_result()->fetch_assoc()["ex"] ?? 0);
  $qEx->close();

  // consumido acumulado hasta fecha
  $qCo = $cx->prepare("
    SELECT IFNULL(SUM(amount), 0) AS co
    FROM aportes_saldo_moves
    WHERE id_jugador = ?
      AND fecha_consumo <= ?
  ");
  $qCo->bind_param("is", $jid, $fechaCorte);
  $qCo->execute();
  $co = (int)($qCo->get_result()->fetch_assoc()["co"] ?? 0);
  $qCo->close();

  return max(0, $ex - $co);
}

function deudas_hasta_fecha(mysqli $cx, int $jid, string $fechaCorte): array {
  // devuelve: ["total" => int, "fechas" => [ "YYYY-MM-DD", ... ]]
  $stmt = $cx->prepare("
    SELECT DATE(fecha) AS f
    FROM deudas_aportes
    WHERE id_jugador = ?
      AND fecha <= ?
    ORDER BY fecha ASC
  ");
  $stmt->bind_param("is", $jid, $fechaCorte);
  $stmt->execute();
  $res = $stmt->get_result();

  $fechas = [];
  while ($r = $res->fetch_assoc()) {
    if (!empty($r["f"])) $fechas[] = $r["f"];
  }
  $stmt->close();

  return [
    "total" => count($fechas),
    "fechas" => $fechas
  ];
}

try {
  $TOPE = 3000;

  $mes  = intval($_GET['mes']  ?? date('n'));
  $anio = intval($_GET['anio'] ?? date('Y'));
  if ($mes < 1 || $mes > 12) $mes = (int)date('n');
  if ($anio < 1900) $anio = (int)date('Y');

  $inicioMes = sprintf("%04d-%02d-01", $anio, $mes);
  $finMes    = date('Y-m-t', strtotime($inicioMes));

  // ✅ eliminados EN ESTE MES (los que se dieron de baja dentro del rango)
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

  // Detectar columna de fecha para otros_aportes
  $hasFechaOtros = column_exists($conexion, "otros_aportes", "fecha");
  $hasCreatedOtros = !$hasFechaOtros && column_exists($conexion, "otros_aportes", "created_at");

  $players = [];
  $rows    = [];

  $total_eliminados_mes   = 0; // aportes normales (registrados) del mes
  $total_otros_elim_mes   = 0; // otros aportes del mes (tabla otros_aportes + esporadico_otro si aplica)
  $total_general_aportes  = 0; // total listado en modal (normales + otros)
  $total_general_saldo    = 0; // saldo fin mes por eliminado

  while ($j = $res->fetch_assoc()) {
    $jid = (int)$j["id"];

    // ✅ saldo fin del mes (foto al corte)
    $saldo_fin_mes = saldo_a_fecha($conexion, $jid, $finMes, $TOPE);
    $deudas = deudas_hasta_fecha($conexion, $jid, $finMes);
    // =====================================================
    // 1) APORTES NORMALES DEL MES (SOLO MES, NO HISTÓRICO)
    // =====================================================
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
       AND MONTH(a.fecha) = ?
      ORDER BY a.fecha ASC, a.id ASC
    ");
    $qAportes->bind_param("iiiii", $TOPE, $TOPE, $jid, $anio, $mes);
    $qAportes->execute();
    $rr = $qAportes->get_result();

    $movs = []; // movimientos del mes (normales + otros) para este jugador
    $sum_normales_mes = 0;

    while ($r = $rr->fetch_assoc()) {
      $fecha    = date("Y-m-d", strtotime($r["fecha"]));
      $cantidad = (int)$r["cantidad"];

      $sum_normales_mes += $cantidad;

      $movs[] = [
        "jugador_id" => $jid,
        "fecha"      => $fecha,
        "kind"       => "normal",     // ✅ para CSS/JS
        "label"      => "Aporte",
        "cantidad"   => $cantidad,
        "total"      => $cantidad,    // no acumulado
        "saldo"      => $saldo_fin_mes
        
      ];
    }
    $qAportes->close();

    // =====================================================
    // 2) OTROS APORTES DEL MES (SOLO MES) con fecha visible
    // =====================================================
    if ($hasFechaOtros) {
      $qOtros = $conexion->prepare("
        SELECT tipo, valor, DATE(fecha) AS fecha_item
        FROM otros_aportes
        WHERE id_jugador = ?
          AND anio = ?
          AND mes = ?
        ORDER BY fecha_item ASC
      ");
      $qOtros->bind_param("iii", $jid, $anio, $mes);
    } elseif ($hasCreatedOtros) {
      $qOtros = $conexion->prepare("
        SELECT tipo, valor, DATE(created_at) AS fecha_item
        FROM otros_aportes
        WHERE id_jugador = ?
          AND anio = ?
          AND mes = ?
        ORDER BY fecha_item ASC
      ");
      $qOtros->bind_param("iii", $jid, $anio, $mes);
    } else {
      // fallback: no hay fecha real -> usamos el primer día del mes
      $qOtros = $conexion->prepare("
        SELECT tipo, valor, ? AS fecha_item
        FROM otros_aportes
        WHERE id_jugador = ?
          AND anio = ?
          AND mes = ?
        ORDER BY tipo ASC
      ");
      $fallbackDate = $inicioMes;
      $qOtros->bind_param("siii", $fallbackDate, $jid, $anio, $mes);
    }

    $qOtros->execute();
    $ro = $qOtros->get_result();

    $sum_otros_mes = 0;

    while ($o = $ro->fetch_assoc()) {
      $fecha_item = $o["fecha_item"] ? date("Y-m-d", strtotime($o["fecha_item"])) : $inicioMes;
      $valor = (int)($o["valor"] ?? 0);
      $tipo  = (string)($o["tipo"] ?? "Otro");

      if ($valor <= 0) continue;
      $sum_otros_mes += $valor;

      $movs[] = [
        "jugador_id" => $jid,
        "fecha"      => $fecha_item,
        "kind"       => "otro",
        "label"      => "Otro: " . $tipo,
        "cantidad"   => $valor,
        "total"      => $valor,
        "saldo"      => $saldo_fin_mes
      ];
    }
    $qOtros->close();

    // =====================================================
    // 3) Numerar y ordenar por fecha (y tipo)
    // =====================================================
    usort($movs, function($a, $b){
      if ($a["fecha"] === $b["fecha"]) {
        // normales primero, luego otros (o al revés si prefieres)
        return strcmp($a["kind"], $b["kind"]);
      }
      return strcmp($a["fecha"], $b["fecha"]);
    });

    $n = 0;
    foreach ($movs as $m) {
      $n++;
      $m["n"] = $n;
      $rows[] = $m;
    }

    // =====================================================
    // Totales por jugador (SOLO DEL MES LISTADO)
    // =====================================================
// ✅ Total aportes jugador (CONTABLE) = SOLO normales
$total_listado_jugador = (int)$sum_normales_mes;

// ✅ Informativo (otros del mes)
$total_listado_otros = (int)$sum_otros_mes;

   $players[] = [
  "id"             => $jid,
  "nombre"         => $j["nombre"],
  "fecha_baja"     => $j["fecha_baja"],

  "total_mes"      => $sum_normales_mes,      // ✅ SOLO normales
  "total_otros_mes"=> $sum_otros_mes,         // ✅ informativo
  "total_listado"  => $total_listado_jugador, // ✅ SOLO normales
  "saldo_fin_mes"  => $saldo_fin_mes,
  "deudas_total"  => (int)$deudas["total"],
  "deudas_fechas" => $deudas["fechas"],
];

    // Totales generales (modal)
  // Totales generales (modal)
// ✅ eliminados_mes = SOLO aportes normales del mes
$total_eliminados_mes  += $sum_normales_mes;

// ✅ informativo: otros aportes del mes (NO se suman a total_general_aportes)
$total_otros_elim_mes  += $sum_otros_mes;

// ✅ total_general_aportes = SOLO aportes normales (para cuadrar con planilla)
$total_general_aportes += $sum_normales_mes;

$total_general_saldo   += $saldo_fin_mes;
  }

  $stmt->close();

  echo json_encode([
    "ok" => true,
    "mes" => $mes,
    "anio" => $anio,
    "totales" => [
      // card (si quieres que el card sea SOLO normales como antes)
      "eliminados_mes" => $total_eliminados_mes,

      // informativos
      "otros_eliminados_mes" => $total_otros_elim_mes,

      // footer modal (lo que realmente estás listando)
      "total_general_aportes" => $total_general_aportes,
      "total_general_saldo"   => $total_general_saldo
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
  exit;
}