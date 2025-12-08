<?php
header("Content-Type: application/json");
include "../../conexion.php";

$mes = intval($_GET['mes'] ?? date('n'));
$anio = intval($_GET['anio'] ?? date('Y'));

$res = $conexion->query("
    SELECT id, nombre, valor 
    FROM gastos 
    WHERE mes=$mes AND anio=$anio
    ORDER BY id DESC
");

$data = [];
while ($r = $res->fetch_assoc()) $data[] = $r;

echo json_encode(['ok'=>true, 'gastos'=>$data]);
