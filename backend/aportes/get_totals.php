<?php
header("Content-Type: application/json");
include "../../conexion.php";

$mes = isset($_GET['mes']) ? intval($_GET['mes']) : intval(date('n'));
$anio = isset($_GET['anio']) ? intval($_GET['anio']) : intval(date('Y'));
$hoy = date('Y-m-d');

// totales por día (hoy)
$today = $conexion->query("SELECT IFNULL(SUM(aporte_principal),0) as s FROM aportes WHERE fecha = '$hoy'")->fetch_assoc()['s'];

// total mes aportes
$month_total = $conexion->query("SELECT IFNULL(SUM(aporte_principal),0) as s FROM aportes WHERE MONTH(fecha) = $mes AND YEAR(fecha) = $anio")->fetch_assoc()['s'];

// total otros aportes mes
$otros_total = $conexion->query("SELECT IFNULL(SUM(valor),0) as s FROM otros_aportes WHERE mes = $mes AND anio = $anio")->fetch_assoc()['s'];

$month_total_all = $month_total + $otros_total;

// total año
$year_total = $conexion->query("SELECT IFNULL(SUM(aporte_principal),0) as s FROM aportes WHERE YEAR(fecha) = $anio")->fetch_assoc()['s'];
$otros_year = $conexion->query("SELECT IFNULL(SUM(valor),0) as s FROM otros_aportes WHERE anio = $anio")->fetch_assoc()['s'];
$year_total_all = $year_total + $otros_year;

echo json_encode([
    'ok'=>true,
    'today'=>intval($today),
    'month_total'=>intval($month_total_all),
    'year_total'=>intval($year_total_all)
]);
