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
 * Saldo robusto (foto) a una fecha de corte:
 * excedente acumulado (aportes-TOPE) - consumido acumulado, por jugador.
 * No depende de que el jugador exista/esté activo en "jugadores".
 */
function calcular_saldo_robusto(mysqli $cx, string $fechaCorte, int $TOPE): int {
  $sql = "
    SELECT IFNULL(SUM(
      GREATEST(IFNULL(ex.excedente,0) - IFNULL(co.consumido,0), 0)
    ),0) AS saldo
    FROM (
      SELECT id_jugador
      FROM aportes
      WHERE fecha <= '$fechaCorte'
        AND id_jugador IS NOT NULL
        AND tipo_aporte IS NULL
      GROUP BY id_jugador

      UNION

      SELECT id_jugador
      FROM aportes_saldo_moves
      WHERE fecha_consumo <= '$fechaCorte'
        AND id_jugador IS NOT NULL
      GROUP BY id_jugador
    ) ids
    LEFT JOIN (
      SELECT id_jugador, SUM(GREATEST(aporte_principal - $TOPE, 0)) AS excedente
      FROM aportes
      WHERE fecha <= '$fechaCorte'
        AND id_jugador IS NOT NULL
        AND tipo_aporte IS NULL
      GROUP BY id_jugador
    ) ex ON ex.id_jugador = ids.id_jugador
    LEFT JOIN (
      SELECT id_jugador, SUM(amount) AS consumido
      FROM aportes_saldo_moves
      WHERE fecha_consumo <= '$fechaCorte'
        AND id_jugador IS NOT NULL
      GROUP BY id_jugador
    ) co ON co.id_jugador = ids.id_jugador
  ";
  return (int)($cx->query($sql)->fetch_assoc()['saldo'] ?? 0);
}

/**
 * Breakdown informativo (no afecta cálculo robusto).
 */
function saldo_activos_info(mysqli $cx, string $fechaCorte, int $TOPE): int {
  $sql = "
    SELECT IFNULL(SUM(GREATEST(IFNULL(ex.excedente,0) - IFNULL(co.consumido,0), 0)),0) AS saldo
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
    WHERE j.activo = 1
  ";
  return (int)($cx->query($sql)->fetch_assoc()['saldo'] ?? 0);
}

function saldo_eliminados_info(mysqli $cx, string $fechaCorte, int $TOPE): int {
  $sql = "
    SELECT IFNULL(SUM(GREATEST(IFNULL(ex.excedente,0) - IFNULL(co.consumido,0), 0)),0) AS saldo
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
    WHERE j.activo = 0
      AND j.fecha_baja IS NOT NULL
      AND j.fecha_baja <= '$fechaCorte'
  ";
  return (int)($cx->query($sql)->fetch_assoc()['saldo'] ?? 0);
}

function calcular_totales_mes(mysqli $cx, int $anio, int $mes, int $TOPE = 3000): array {
  $fechaCorte = date('Y-m-t', strtotime("$anio-$mes-01"));
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

  // 2) Esporádicos (suma completa)
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

  // 5) Saldo (foto) a corte y a corte previo
  $saldo_corte = calcular_saldo_robusto($cx, $fechaCorte, $TOPE);
  $saldo_prev  = calcular_saldo_robusto($cx, $fechaCortePrev, $TOPE);

  // ✅ SALDO DEL MES (delta)
  $saldo_mes = (int)($saldo_corte - $saldo_prev);

  // Info (no contable)
  $saldo_activos = saldo_activos_info($cx, $fechaCorte, $TOPE);
  $saldo_eliminados = saldo_eliminados_info($cx, $fechaCorte, $TOPE);

  // =========================
  // Cálculos
  // =========================
  $parcial_mes = (int)($month_total + $esporadicos_mes + $otros_mes); // sin saldos
  $final_neto_mes = (int)($parcial_mes - $gastos_mes);               // sin saldos (sumable)

  // ✅ con saldo DEL MES (sumable)
  $final_con_saldo_mes_sumable = (int)($final_neto_mes + $saldo_mes);

  // ✅ con saldo ACUMULADO (foto)
  $final_con_saldo_mes_foto = (int)($final_neto_mes + $saldo_corte);

  return [
    "fecha_corte" => $fechaCorte,

    "month_total" => $month_total,
    "esporadicos_mes" => $esporadicos_mes,
    "otros_mes" => $otros_mes,
    "gastos_mes" => $gastos_mes,

    "parcial_mes" => $parcial_mes,

    // saldos
    "saldo_total" => $saldo_corte,  // acumulado
    "saldo_mes" => $saldo_mes,      // delta

    // finales netos
    "final_neto_mes" => $final_neto_mes,

    // finales con saldo
    "final_con_saldo_mes_sumable" => $final_con_saldo_mes_sumable, // con saldo mes
    "final_con_saldo_mes_foto" => $final_con_saldo_mes_foto,       // con saldo acumulado

    // compat
    "final_mes" => $final_neto_mes,

    // info
    "saldo_activos" => $saldo_activos,
    "saldo_eliminados" => $saldo_eliminados,
    "saldo_prev" => $saldo_prev,
  ];
}

// =======================
// MES ACTUAL
// =======================
$mesActual = calcular_totales_mes($conexion, $anio, $mes, $TOPE);

// =======================
// AÑO hasta mes seleccionado
// =======================
$parcial_anio = 0;
$otros_anio = 0;
$gastos_anio = 0;

$final_anio_neto = 0;
$saldo_anio_mes = 0; // ✅ suma de saldos mensuales (delta)
$final_anio_con_saldo_sumable = 0;
$final_anio_con_saldo_acumulado_sumado = 0; // <-- NUEVO (cuadra con suma Enero+Febrero)

for ($m = 1; $m <= $mes; $m++) {
  $tmp = calcular_totales_mes($conexion, $anio, $m, $TOPE);

  $parcial_anio += (int)$tmp["parcial_mes"];
  $otros_anio   += (int)$tmp["otros_mes"];
  $gastos_anio  += (int)$tmp["gastos_mes"];

  $final_anio_neto += (int)$tmp["final_neto_mes"];

  // suma de saldos mensuales (delta)
  $saldo_anio_mes += (int)$tmp["saldo_mes"];

  // compat “sumable”
  $final_anio_con_saldo_sumable += (int)$tmp["final_con_saldo_mes_sumable"];
   // ✅ NUEVO: suma de "mes con saldo acumulado" (duplica saldos previos, pero cuadra con lo que suma el usuario)
  $final_anio_con_saldo_acumulado_sumado += (int)$tmp["final_con_saldo_mes_foto"];
}

// saldo acumulado (foto) a corte del mes seleccionado:
$saldo_total_corte = (int)$mesActual["saldo_total"];

// anual con saldo ACUMULADO (foto)
$final_anio_con_saldo_foto = (int)($final_anio_neto + $saldo_total_corte);

// anual con saldo DEL MES (sumable) => neto anual + suma de deltas
$final_anio_con_saldo_mes = (int)($final_anio_neto + $saldo_anio_mes);

// mes con saldo DEL MES (sumable)
$final_mes_con_saldo_mes = (int)$mesActual["final_con_saldo_mes_sumable"];

// mes con saldo ACUMULADO (foto)
$final_mes_con_saldo_foto = (int)$mesActual["final_con_saldo_mes_foto"];

echo json_encode([
  "ok" => true,

  // parciales
  "parcial_mes"  => (int)$mesActual["parcial_mes"],
  "parcial_anio" => (int)$parcial_anio,

  // otros
  "otros_mes"  => (int)$mesActual["otros_mes"],
  "otros_anio" => (int)$otros_anio,

  // gastos
  "gastos_mes"  => (int)$mesActual["gastos_mes"],
  "gastos_anio" => (int)$gastos_anio,

  // netos sin saldos
  "final_neto_mes"  => (int)$mesActual["final_neto_mes"],
  "final_anio_neto" => (int)$final_anio_neto,

  // ===== saldos (los 2 que necesitas mostrar) =====
  "saldo_mes"   => (int)$mesActual["saldo_mes"],   // de este mes
  "saldo_total" => (int)$mesActual["saldo_total"], // acumulado a corte

  // ===== finales con saldo (los 4 que pediste) =====
  // con saldo del mes
  "final_mes_con_saldo_mes"  => (int)$final_mes_con_saldo_mes,
  "final_anio_con_saldo_mes" => (int)$final_anio_con_saldo_mes,

  // con saldo acumulado (foto)
  "final_mes_con_saldo"  => (int)$final_mes_con_saldo_foto,
  "final_anio_con_saldo" => (int)$final_anio_con_saldo_foto,
  // ✅ NUEVO: para que cuadre con sumar (Enero con saldo acumulado) + (Febrero con saldo acumulado) + ...
   "final_anio_con_saldo_acumulado_sumado" => (int)$final_anio_con_saldo_acumulado_sumado,

  // ===== compat: tus campos “Resultado Suma Meses” =====
  "final_con_saldo_mes_sumable"  => (int)$final_mes_con_saldo_mes,
  "final_anio_con_saldo_sumable" => (int)$final_anio_con_saldo_mes,

  "debug" => [
    "fecha_corte" => (string)$mesActual["fecha_corte"],
    "saldo_prev"  => (int)$mesActual["saldo_prev"],
    "saldo_activos" => (int)$mesActual["saldo_activos"],
    "saldo_eliminados" => (int)$mesActual["saldo_eliminados"],
    "saldo_anio_mes" => (int)$saldo_anio_mes,
  ],
], JSON_UNESCAPED_UNICODE);