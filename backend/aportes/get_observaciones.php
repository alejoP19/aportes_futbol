<?php
include "../../conexion.php";
header("Content-Type: application/json");

$mes = isset($_GET['mes']) ? intval($_GET['mes']) : intval(date('n'));
$anio = isset($_GET['anio']) ? intval($_GET['anio']) : intval(date('Y'));

$stmt = $conexion->prepare("SELECT texto FROM gastos_observaciones WHERE mes=? AND anio=? LIMIT 1");
$stmt->bind_param("ii", $mes, $anio);
$stmt->execute();
$res = $stmt->get_result()->fetch_assoc();

echo json_encode([
    "observaciones" => $res['texto'] ?? ""
]);
?>
