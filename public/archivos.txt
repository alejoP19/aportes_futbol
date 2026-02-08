<?php
header('Content-Type: application/json');

// Conexión
$mysqli = new mysqli('localhost:3307', 'root', '', 'aportes_futbol');

if ($mysqli->connect_errno) {
    echo json_encode(["error" => "Error de conexión"]);
    exit;
}

$anio = isset($_GET['anio']) ? (int)$_GET['anio'] : date('Y');
$mes  = isset($_GET['mes']) ? (int)$_GET['mes'] : date('n');


// =====================================================
// 1. LISTA DE APORTANTES (jugadores activos e inactivos)
// =====================================================
$sql = "SELECT *,
        IF(activo=0,'eliminado','') AS clase_eliminado
        FROM jugadores
        ORDER BY nombre ASC";

$res = $mysqli->query($sql);
$datos = [];
while ($row = $res->fetch_assoc()) {
    $datos[] = $row;
}


// =====================================================
// 2. TOTALES DEL MES (tabla: aportes)
// =====================================================
$sqlTotMes = "SELECT 
        SUM(aporte_principal) AS total_mes
        FROM aportes
        WHERE YEAR(fecha) = $anio
        AND MONTH(fecha) = $mes";

$resMes = $mysqli->query($sqlTotMes);
$totales_mes = $resMes->fetch_assoc();
if (!$totales_mes["total_mes"]) $totales_mes["total_mes"] = 0;

// ===============================
// 3. TOTALES DEL AÑO COMPLETO
// ===============================
$sqlTotAnio = "SELECT 
        SUM(aporte_principal) AS total_anio
        FROM aportes
        WHERE YEAR(fecha) = $anio";

$resAnio = $mysqli->query($sqlTotAnio);
$totales_anio = $resAnio->fetch_assoc();

if (!$totales_anio['total_anio']) {
    $totales_anio['total_anio'] = 0;
}



// =====================================================
// 4. TOTALES OTROS APORTES (tabla: otros_aportes)
// =====================================================
$sqlOtros = "SELECT 
        SUM(valor) AS total_otros
        FROM otros_aportes
        WHERE anio = $anio
        AND mes = $mes";

$resOtros = $mysqli->query($sqlOtros);
$totales_otros = $resOtros->fetch_assoc();
if (!$totales_otros["total_otros"]) $totales_otros["total_otros"] = 0;


// =====================================================
// 5. OBSERVACIONES (tabla: gastos_observaciones)
// =====================================================
$sqlObs = "SELECT texto
        FROM gastos_observaciones
        WHERE anio = $anio
        AND mes = $mes";

$resObs = $mysqli->query($sqlObs);

$observaciones = "";
if ($row = $resObs->fetch_assoc()) {
    $observaciones = $row['texto'];
}


// =====================================================
// RESPUESTA COMPLETA
// =====================================================
echo json_encode([
    "datos"          => $datos,
    "totales_mes"    => $totales_mes,
     "totales_anio" => $totales_anio,
    "totales_otros"  => $totales_otros,
    "observaciones"  => $observaciones
]);
?>

