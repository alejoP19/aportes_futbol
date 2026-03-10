<?php
header("Content-Type: application/json; charset=utf-8");
include "../../conexion.php";
require_once __DIR__ . "/../auth/auth.php";
protegerAdmin();

$mes  = intval($_GET['mes']  ?? date('n'));
$anio = intval($_GET['anio'] ?? date('Y'));

if ($mes < 1 || $mes > 12) $mes = (int)date('n');
if ($anio < 1900) $anio = (int)date('Y');

$TOPE = 3000;

/**
 * Excedente generado SOLO en un mes
 * (esto alimenta "Saldo Actual Mes")
 */
function calcular_excedente_mes(mysqli $cx, int $anio, int $mes, int $TOPE): int {
  $sql = "
    SELECT IFNULL(SUM(GREATEST(aporte_principal - $TOPE, 0)),0) AS excedente_mes
    FROM aportes
    WHERE YEAR(fecha) = $anio
      AND MONTH(fecha) = $mes
      AND id_jugador IS NOT NULL
      AND tipo_aporte IS NULL
  ";
  $res = $cx->query($sql);
  return (int)($res->fetch_assoc()['excedente_mes'] ?? 0);
}

/**
 * Saldo acumulado real al corte:
 * SUMA por jugador de MAX(excedente - consumido, 0)
 * ✅ Incluye activos y eliminados
 * ✅ No deja que un jugador "borre" el saldo de otro
 */
function calcular_saldo_acumulado_real(mysqli $cx, string $fechaCorte, int $TOPE): int {
  $sql = "
    SELECT IFNULL(SUM(
      GREATEST(IFNULL(ex.excedente,0) - IFNULL(co.consumido,0), 0)
    ),0) AS saldo
    FROM jugadores j
    LEFT JOIN (
      SELECT id_jugador, SUM(GREATEST(aporte_principal - $TOPE, 0)) AS excedente
      FROM aportes
      WHERE fecha <= '$fechaCorte'
        AND id_jugador IS NOT NULL
        AND tipo_aporte IS NULL
      GROUP BY id_jugador
    ) ex ON ex.id_jugador = j.id
    LEFT JOIN (
      SELECT id_jugador, SUM(amount) AS consumido
      FROM aportes_saldo_moves
      WHERE fecha_consumo <= '$fechaCorte'
        AND id_jugador IS NOT NULL
      GROUP BY id_jugador
    ) co ON co.id_jugador = j.id
  ";

  $res = $cx->query($sql);
  return (int)($res->fetch_assoc()['saldo'] ?? 0);
}

/**
 * Totales de un mes
 */
function calcular_totales_mes(mysqli $cx, int $anio, int $mes, int $TOPE = 3000): array {
  $fechaCorte = date('Y-m-t', strtotime("$anio-$mes-01"));

  // 1) Aportes normales del mes
  $month_total = (int)($cx->query("
    SELECT IFNULL(SUM(
      LEAST(
        LEAST(IFNULL(a.aporte_principal,0), $TOPE) + IFNULL(t.consumido,0),
        $TOPE
      )
    ),0) AS total_mes
    FROM aportes a
    LEFT JOIN (
      SELECT target_aporte_id, SUM(amount) AS consumido
      FROM aportes_saldo_moves
      GROUP BY target_aporte_id
    ) t ON t.target_aporte_id = a.id
    WHERE YEAR(a.fecha) = $anio
      AND MONTH(a.fecha) = $mes
      AND a.id_jugador IS NOT NULL
      AND a.tipo_aporte IS NULL
  ")->fetch_assoc()['total_mes'] ?? 0);

  // 2) Esporádicos
  $esporadicos_mes = (int)($cx->query("
    SELECT IFNULL(SUM(aporte_principal),0) AS s
    FROM aportes
    WHERE tipo_aporte='esporadico'
      AND YEAR(fecha)=$anio AND MONTH(fecha)=$mes
  ")->fetch_assoc()['s'] ?? 0);

  // 3) Otros aportes
  $otros_mes = (int)($cx->query("
    SELECT IFNULL(SUM(valor),0) AS s
    FROM otros_aportes
    WHERE mes=$mes AND anio=$anio
  ")->fetch_assoc()['s'] ?? 0);

  $otros_esporadicos_mes = (int)($cx->query("
    SELECT IFNULL(SUM(aporte_principal),0) AS s
    FROM aportes
    WHERE tipo_aporte='esporadico_otro'
      AND YEAR(fecha)=$anio AND MONTH(fecha)=$mes
  ")->fetch_assoc()['s'] ?? 0);

  $otros_mes += $otros_esporadicos_mes;

  // 4) Gastos
  $gastos_mes = (int)($cx->query("
    SELECT IFNULL(SUM(valor),0) AS s
    FROM gastos
    WHERE mes=$mes AND anio=$anio
  ")->fetch_assoc()['s'] ?? 0);

  // 5) Saldos
  $saldo_mes   = calcular_excedente_mes($cx, $anio, $mes, $TOPE);           // solo generado este mes
  $saldo_total = calcular_saldo_acumulado_real($cx, $fechaCorte, $TOPE);    // acumulado real al corte

  // 6) Totales sin saldos
  $parcial_mes    = (int)($month_total + $esporadicos_mes + $otros_mes);
  $final_neto_mes = (int)($parcial_mes - $gastos_mes);

  return [
    "fecha_corte"     => $fechaCorte,

    "month_total"     => $month_total,
    "esporadicos_mes" => $esporadicos_mes,
    "otros_mes"       => $otros_mes,
    "gastos_mes"      => $gastos_mes,

    "parcial_mes"     => $parcial_mes,
    "final_neto_mes"  => $final_neto_mes,

    "saldo_mes"       => (int)$saldo_mes,
    "saldo_total"     => (int)$saldo_total,
  ];
}

// =======================
// MES ACTUAL
// =======================
$mesActual = calcular_totales_mes($conexion, $anio, $mes, $TOPE);

// =======================
// AÑO hasta mes seleccionado
// =======================
$parcial_anio    = 0;
$otros_anio      = 0;
$gastos_anio     = 0;
$final_anio_neto = 0;

for ($m = 1; $m <= $mes; $m++) {
  $tmp = calcular_totales_mes($conexion, $anio, $m, $TOPE);

  $parcial_anio    += (int)$tmp["parcial_mes"];
  $otros_anio      += (int)$tmp["otros_mes"];
  $gastos_anio     += (int)$tmp["gastos_mes"];
  $final_anio_neto += (int)$tmp["final_neto_mes"];
}

// Saldo acumulado real al corte del mes consultado
$saldo_total = (int)$mesActual["saldo_total"];

// Total final año con saldos acumulados
$total_real_hasta_fecha = (int)($final_anio_neto + $saldo_total);

echo json_encode([
  "ok" => true,

  // Parciales (sin saldos)
  "parcial_mes"  => (int)$mesActual["parcial_mes"],
  "parcial_anio" => (int)$parcial_anio,

  // Otros (informativo)
  "otros_mes"  => (int)$mesActual["otros_mes"],
  "otros_anio" => (int)$otros_anio,

  // Gastos
  "gastos_mes"  => (int)$mesActual["gastos_mes"],
  "gastos_anio" => (int)$gastos_anio,

  // Finales sin saldos
  "final_neto_mes"  => (int)$mesActual["final_neto_mes"],
  "final_anio_neto" => (int)$final_anio_neto,

  // Saldos
  "saldo_mes"   => (int)$mesActual["saldo_mes"],   // generado solo este mes
  "saldo_total" => (int)$saldo_total,              // acumulado real al corte

  // Total final año con saldo acumulado
  "total_real_hasta_fecha" => (int)$total_real_hasta_fecha,

], JSON_UNESCAPED_UNICODE);