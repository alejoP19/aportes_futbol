<?php
header("Content-Type: application/json");
include "../../conexion.php";
require_once __DIR__ . "/../auth/auth.php";
protegerAdmin();

$mes  = intval($_GET['mes']  ?? date('n'));
$anio = intval($_GET['anio'] ?? date('Y'));

$fechaCorte = date('Y-m-t', strtotime("$anio-$mes-01"));

// ========================
// APORTES BASE
// ========================
$today = $conexion->query("
    SELECT IFNULL(SUM(aporte_principal),0)
    FROM aportes
    WHERE fecha = CURDATE()
")->fetch_row()[0];

/*
  ✅ TOTAL MES:
  Para cada aporte del mes:
  aporte_efectivo = MIN(aporte_principal + consumido_en_ese_target, 3000)
*/
$month_total = $conexion->query("
    SELECT IFNULL(SUM(
        LEAST(a.aporte_principal + IFNULL(t.consumido,0), 3000)
    ),0) AS total_mes
    FROM aportes a
    LEFT JOIN (
        SELECT target_aporte_id, SUM(amount) AS consumido
        FROM aportes_saldo_moves
        GROUP BY target_aporte_id
    ) t ON t.target_aporte_id = a.id
    WHERE MONTH(a.fecha) = $mes
      AND YEAR(a.fecha)  = $anio
")->fetch_assoc()['total_mes'] ?? 0;

/*
  ✅ TOTAL AÑO (enero..mes):
*/
$year_total = $conexion->query("
    SELECT IFNULL(SUM(
        LEAST(a.aporte_principal + IFNULL(t.consumido,0), 3000)
    ),0) AS total_anio
    FROM aportes a
    LEFT JOIN (
        SELECT target_aporte_id, SUM(amount) AS consumido
        FROM aportes_saldo_moves
        GROUP BY target_aporte_id
    ) t ON t.target_aporte_id = a.id
    WHERE YEAR(a.fecha) = $anio
      AND MONTH(a.fecha) <= $mes
")->fetch_assoc()['total_anio'] ?? 0;

// ========================
// OTROS APORTES
// ========================
$otros_total = $conexion->query("
    SELECT IFNULL(SUM(valor),0)
    FROM otros_aportes
    WHERE mes  = $mes
      AND anio = $anio
")->fetch_row()[0];

$otros_year = $conexion->query("
    SELECT IFNULL(SUM(valor),0)
    FROM otros_aportes
    WHERE anio = $anio
      AND mes <= $mes
")->fetch_row()[0];

// ========================
// SALDO TOTAL (INFORMATIVO)
// ========================
$saldo_total = $conexion->query("
    SELECT IFNULL(SUM(
        GREATEST(IFNULL(ex.excedente,0) - IFNULL(co.consumido,0), 0)
    ),0) AS saldo
    FROM jugadores j
    LEFT JOIN (
        SELECT id_jugador, SUM(GREATEST(aporte_principal - 3000, 0)) AS excedente
        FROM aportes
        WHERE fecha <= '$fechaCorte'
        GROUP BY id_jugador
    ) ex ON ex.id_jugador = j.id
    LEFT JOIN (
        SELECT id_jugador, SUM(amount) AS consumido
        FROM aportes_saldo_moves
        WHERE fecha_consumo <= '$fechaCorte'
        GROUP BY id_jugador
    ) co ON co.id_jugador = j.id
")->fetch_assoc()['saldo'] ?? 0;

// ========================
// GASTOS
// ========================
$gastos_mes = $conexion->query("
    SELECT IFNULL(SUM(valor),0)
    FROM gastos
    WHERE mes  = $mes
      AND anio = $anio
")->fetch_row()[0];

$gastos_anio = $conexion->query("
    SELECT IFNULL(SUM(valor),0)
    FROM gastos
    WHERE anio = $anio
      AND mes <= $mes
")->fetch_row()[0];

// ========================
// TOTALES
// ========================
$month_total_final = (int)$month_total + (int)$otros_total - (int)$gastos_mes;
$year_total_final  = (int)$year_total + (int)$otros_year - (int)$gastos_anio;

echo json_encode([
    'ok'          => true,
    'today'       => (int)$today,
    'month_total' => (int)$month_total_final,
    'year_total'  => (int)$year_total_final,
    'otros_mes'   => (int)$otros_total,
    'gastos_mes'  => (int)$gastos_mes,
    'gastos_anio' => (int)$gastos_anio,
    'saldo_mes'   => (int)$saldo_total
]);
