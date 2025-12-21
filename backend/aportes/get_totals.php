<?php
header("Content-Type: application/json");
include "../../conexion.php";

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

$month_total = $conexion->query("
    SELECT IFNULL(SUM(
        CASE WHEN aporte_principal > 2000 THEN 2000 ELSE aporte_principal END
    ),0)
    FROM aportes
    WHERE MONTH(fecha) = $mes AND YEAR(fecha) = $anio
")->fetch_row()[0];

$year_total = $conexion->query("
    SELECT IFNULL(SUM(
        CASE WHEN aporte_principal > 2000 THEN 2000 ELSE aporte_principal END
    ),0)
    FROM aportes
    WHERE (YEAR(fecha) < $anio OR (YEAR(fecha) = $anio AND MONTH(fecha) <= $mes))
")->fetch_row()[0];

// ========================
// OTROS APORTES
// ========================
$otros_total = $conexion->query("
    SELECT IFNULL(SUM(valor),0)
    FROM otros_aportes
    WHERE mes = $mes AND anio = $anio
")->fetch_row()[0];

$otros_year = $conexion->query("
    SELECT IFNULL(SUM(valor),0)
    FROM otros_aportes
    WHERE (anio < $anio OR (anio = $anio AND mes <= $mes))
")->fetch_row()[0];

$fechaCorte = date('Y-m-t', strtotime("$anio-$mes-01"));

$saldo_total = $conexion->query("
    SELECT IFNULL(SUM(
        GREATEST(IFNULL(ex.excedente,0) - IFNULL(co.consumido,0), 0)
    ),0) AS saldo
    FROM jugadores j
    LEFT JOIN (
        SELECT id_jugador, SUM(GREATEST(aporte_principal - 2000, 0)) AS excedente
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
")->fetch_assoc()['saldo'];

// ========================
// GASTOS
// ========================
$gastos_mes = $conexion->query("
    SELECT IFNULL(SUM(valor),0)
    FROM gastos
    WHERE mes = $mes AND anio = $anio
")->fetch_row()[0];

$gastos_anio = $conexion->query("
    SELECT IFNULL(SUM(valor),0)
    FROM gastos
    WHERE (anio < $anio OR (anio = $anio AND mes <= $mes))
")->fetch_row()[0];

// ========================
// TOTALES CORRECTOS
// ========================

// Total del mes (sin sumar saldo como ingreso)
$month_total_final = $month_total + $otros_total - $gastos_mes;

// Total del año (acumulado real)
$year_total_final  = $year_total + $otros_year - $gastos_anio;

// $year_total_final  = $year_total  + $otros_year  - $gastos_anio;

echo json_encode([
    'ok'          => true,
    'today'       => (int)$today,
    'month_total' => (int)$month_total_final,
    'year_total'  => (int)$year_total_final,
    'otros_mes'   => (int)$otros_total,
    'gastos_mes'  => (int)$gastos_mes,
    'gastos_anio' => (int)$gastos_anio,
    'saldo_mes'   => (int)$saldo_total   // 👈 OBLIGATORIO
]);