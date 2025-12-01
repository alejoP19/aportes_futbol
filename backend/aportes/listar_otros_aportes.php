<?php
header("Content-Type: application/json");
include "../../conexion.php";

$mes = isset($_GET['mes'])? intval($_GET['mes']) : intval(date('n'));
$anio = isset($_GET['anio'])? intval($_GET['anio']) : intval(date('Y'));

$sql = $conexion->prepare("SELECT o.*, j.nombre FROM otros_aportes o JOIN jugadores j ON j.id=o.id_jugador WHERE o.mes=? AND o.anio=? ORDER BY j.nombre");
$sql->bind_param("ii", $mes, $anio);
$sql->execute();
$res = $sql->get_result();
$list = [];
while ($r = $res->fetch_assoc()) $list[] = $r;

echo json_encode(['ok'=>true,'otros'=>$list]);
