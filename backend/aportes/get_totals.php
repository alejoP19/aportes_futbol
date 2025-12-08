<?php
header("Content-Type: application/json");
include "../../conexion.php";

$mes = intval($_GET['mes'] ?? date('n'));
$anio = intval($_GET['anio'] ?? date('Y'));

// aportes
$today = $conexion->query("SELECT IFNULL(SUM(aporte_principal),0) s FROM aportes WHERE fecha = CURDATE()")->fetch_assoc()['s'];
$month_total = $conexion->query("SELECT IFNULL(SUM(aporte_principal),0) s FROM aportes WHERE MONTH(fecha)=$mes AND YEAR(fecha)=$anio")->fetch_assoc()['s'];
$year_total = $conexion->query("SELECT IFNULL(SUM(aporte_principal),0) s FROM aportes WHERE YEAR(fecha)=$anio")->fetch_assoc()['s'];

// otros aportes
$otros_total = $conexion->query("SELECT IFNULL(SUM(valor),0) s FROM otros_aportes WHERE mes=$mes AND anio=$anio")->fetch_assoc()['s'];
$otros_year = $conexion->query("SELECT IFNULL(SUM(valor),0) s FROM otros_aportes WHERE anio=$anio")->fetch_assoc()['s'];

// gastos
// gastos
$gastos_mes = $conexion->query("
    SELECT IFNULL(SUM(valor),0) s 
    FROM gastos 
    WHERE mes=$mes AND anio=$anio
")->fetch_assoc()['s'];

$gastos_anio = $conexion->query("
    SELECT IFNULL(SUM(valor),0) s 
    FROM gastos 
    WHERE anio=$anio AND mes <= $mes
")->fetch_assoc()['s'];



// aplicar restas
$month_total_final = $month_total + $otros_total - $gastos_mes;
$year_total_final = $year_total + $otros_year - $gastos_anio;

echo json_encode([
    'ok'=>true,
    'today'=>intval($today),
    'month_total'=>intval($month_total_final),
    'otros_mes'=>intval($otros_total),
    'year_total'=>intval($year_total_final),
    'gastos_mes'=>intval($gastos_mes),
    'gastos_anio'=>intval($gastos_anio)
]);
