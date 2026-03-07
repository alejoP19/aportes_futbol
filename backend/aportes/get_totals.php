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
 * Excedente acumulado (aportes - TOPE) a una fecha.
 * Esto es lo que "genera saldo" (sin restar consumos).
 */
function calcular_excedente_robusto(mysqli $cx, string $fechaCorte, int $TOPE): int {
  $sql = "
    SELECT IFNULL(SUM(GREATEST(aporte_principal - $TOPE, 0)),0) AS excedente
    FROM aportes
    WHERE fecha <= '$fechaCorte'
      AND id_jugador IS NOT NULL
      AND tipo_aporte IS NULL
  ";
  return (int)($cx->query($sql)->fetch_assoc()['excedente'] ?? 0);
}

/**
 * Consumido acumulado a una fecha (saldo usado).
 */
function calcular_consumido_robusto(mysqli $cx, string $fechaCorte): int {
  $sql = "
    SELECT IFNULL(SUM(amount),0) AS consumido
    FROM aportes_saldo_moves
    WHERE fecha_consumo <= '$fechaCorte'
      AND id_jugador IS NOT NULL
  ";
  return (int)($cx->query($sql)->fetch_assoc()['consumido'] ?? 0);
}

/**
 * Saldo robusto (foto) a una fecha:
 * saldo = excedente acumulado - consumido acumulado, clamp a 0.
 */
function calcular_saldo_robusto(mysqli $cx, string $fechaCorte, int $TOPE): int {
  $ex = calcular_excedente_robusto($cx, $fechaCorte, $TOPE);
  $co = calcular_consumido_robusto($cx, $fechaCorte);
  return (int)max(0, $ex - $co);
}

function calcular_totales_mes(mysqli $cx, int $anio, int $mes, int $TOPE = 3000): array {
  $fechaCorte     = date('Y-m-t', strtotime("$anio-$mes-01"));
  $fechaCortePrev = date('Y-m-t', strtotime("$anio-$mes-01 -1 month"));

  // 1) Aportes normales del mes (cap + consumido, tope)
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

  // 3) Otros aportes (otros_aportes + esporadico_otro)
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

  // 5) Saldos:
  // saldo_total = foto real al corte (excedente - consumido)
  $saldo_total = calcular_saldo_robusto($cx, $fechaCorte, $TOPE);

  // saldo_mes = SOLO saldo generado este mes (excedente nuevo), NUNCA negativo
  $ex_corte = calcular_excedente_robusto($cx, $fechaCorte, $TOPE);
  $ex_prev  = calcular_excedente_robusto($cx, $fechaCortePrev, $TOPE);
  $saldo_mes = (int)max(0, $ex_corte - $ex_prev);

  // 6) Cálculos sin saldos (lo que el usuario suma)
  $parcial_mes    = (int)($month_total + $esporadicos_mes + $otros_mes);
  $final_neto_mes = (int)($parcial_mes - $gastos_mes);

  return [
    "fecha_corte"     => $fechaCorte,

    "otros_mes"       => $otros_mes,
    "gastos_mes"      => $gastos_mes,

    "parcial_mes"     => $parcial_mes,
    "final_neto_mes"  => $final_neto_mes,

    // 2 saldos que pintas:
    "saldo_mes"       => $saldo_mes,    // generado este mes (NO negativo)
    "saldo_total"     => $saldo_total,  // acumulado real al corte (foto)
  ];
}

// =======================
// MES ACTUAL
// =======================
$mesActual = calcular_totales_mes($conexion, $anio, $mes, $TOPE);

// =======================
// AÑO hasta mes seleccionado (SUMA de meses SIN saldos)
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

// saldo acumulado (foto) a corte del mes seleccionado
$saldo_total = (int)$mesActual["saldo_total"];

// total real hasta la fecha
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

  // Finales sin saldos (SUMABLE)
  "final_neto_mes"  => (int)$mesActual["final_neto_mes"],
  "final_anio_neto" => (int)$final_anio_neto,

  // Saldos (informativo, pero ahora SIN negativos)
  "saldo_mes"   => (int)$mesActual["saldo_mes"],   // generado este mes (solo excedente nuevo)
  "saldo_total" => (int)$saldo_total,              // acumulado real al corte (foto)

  // Total real hasta fecha
  "total_real_hasta_fecha" => (int)$total_real_hasta_fecha,

], JSON_UNESCAPED_UNICODE);