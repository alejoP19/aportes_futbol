<?php
header("Content-Type: application/json");
include "../../conexion.php";

$mes  = intval($_GET['mes']  ?? date('n'));
$anio = intval($_GET['anio'] ?? date('Y'));

// ========================
// APORTES PRINCIPALES
// ========================

// total del día (solo hoy)
$today = $conexion->query("
    SELECT IFNULL(SUM(aporte_principal),0) AS s 
    FROM aportes 
    WHERE fecha = CURDATE()
")->fetch_assoc()['s'];

// total del MES seleccionado
$month_total = $conexion->query("
    SELECT IFNULL(SUM(aporte_principal),0) AS s 
    FROM aportes 
    WHERE MONTH(fecha) = $mes 
      AND YEAR(fecha)  = $anio
")->fetch_assoc()['s'];

// total del AÑO HASTA ESE MES (enero..mes)
$year_total = $conexion->query("
    SELECT IFNULL(SUM(aporte_principal),0) AS s 
    FROM aportes 
    WHERE YEAR(fecha)  = $anio
      AND MONTH(fecha) <= $mes
")->fetch_assoc()['s'];

// ========================
// OTROS APORTES
// ========================
$otros_total = $conexion->query("
    SELECT IFNULL(SUM(valor),0) AS s 
    FROM otros_aportes 
    WHERE mes  = $mes 
      AND anio = $anio
")->fetch_assoc()['s'];

// total del año en OTROS aportes HASTA ESE MES
$otros_year = $conexion->query("
    SELECT IFNULL(SUM(valor),0) AS s 
    FROM otros_aportes 
    WHERE anio = $anio
      AND mes <= $mes
")->fetch_assoc()['s'];

// ========================
// GASTOS
// ========================
$gastos_mes = $conexion->query("
    SELECT IFNULL(SUM(valor),0) AS s 
    FROM gastos 
    WHERE mes  = $mes 
      AND anio = $anio
")->fetch_assoc()['s'];

$gastos_anio = $conexion->query("
    SELECT IFNULL(SUM(valor),0) AS s 
    FROM gastos 
    WHERE anio = $anio 
      AND mes <= $mes
")->fetch_assoc()['s'];

// ========================
// APLICAR RESTAS
// ========================
$month_total_final = $month_total + $otros_total - $gastos_mes;
$year_total_final  = $year_total  + $otros_year  - $gastos_anio;

echo json_encode([
    'ok'          => true,
    'today'       => intval($today),
    'month_total' => intval($month_total_final),
    'otros_mes'   => intval($otros_total),
    'year_total'  => intval($year_total_final),
    'gastos_mes'  => intval($gastos_mes),
    'gastos_anio' => intval($gastos_anio)
]);
