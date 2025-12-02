<?php
// public/archivos.php
header('Content-Type: application/json');

// Conexión a tu base de datos
$mysqli = new mysqli("localhost","usuario","password","db_aportes");

if ($mysqli->connect_errno) {
    echo json_encode(["error"=>"Error de conexión"]);
    exit;
}

// Recibir parámetros opcionales de año y mes
$anio = isset($_GET['anio']) ? (int)$_GET['anio'] : date('Y');
$mes  = isset($_GET['mes']) ? (int)$_GET['mes'] : date('m');

// Consultar todos los aportantes, incluyendo eliminados
$sql = "SELECT *, 
        IF(eliminado=1,'eliminado','') as clase_eliminado
        FROM aportantes 
        WHERE YEAR(fecha) = $anio AND MONTH(fecha) = $mes
        ORDER BY nombre ASC";

$res = $mysqli->query($sql);

$datos = [];
while($row = $res->fetch_assoc()) {
    $datos[] = $row;
}

// Consultar totales
$sqlTotales = "SELECT 
    SUM(aporte_dia) as tDia, 
    SUM(aporte_mes) as tMes, 
    SUM(aporte_anio) as tAnio
    FROM aportantes 
    WHERE YEAR(fecha) = $anio AND MONTH(fecha) = $mes";

$resT = $mysqli->query($sqlTotales);
$totales = $resT->fetch_assoc();

// Observaciones
$sqlObs = "SELECT observaciones FROM aportantes_observaciones 
           WHERE YEAR(fecha) = $anio AND MONTH(fecha) = $mes";
$resObs = $mysqli->query($sqlObs);
$observaciones = [];
while($row = $resObs->fetch_assoc()) {
    $observaciones[] = $row['observaciones'];
}

echo json_encode([
    "datos" => $datos,
    "totales" => $totales,
    "observaciones" => $observaciones
]);
?>
