<?php
header("Content-Type: application/json");
include "../../conexion.php";

$mes = intval($_POST['mes'] ?? date('n'));
$anio = intval($_POST['anio'] ?? date('Y'));
$texto = trim($_POST['texto'] ?? '');

$stmt = $conexion->prepare("SELECT id FROM gastos_observaciones WHERE mes=? AND anio=? LIMIT 1");
$stmt->bind_param("ii",$mes,$anio);
$stmt->execute();
$res = $stmt->get_result();

if ($res->num_rows){
    $r = $res->fetch_assoc();
    $id = $r['id'];
    $u = $conexion->prepare("UPDATE gastos_observaciones SET texto=? WHERE id=?");
    $u->bind_param("si",$texto,$id);
    $u->execute();
    $u->close();
} else {
    $i = $conexion->prepare("INSERT INTO gastos_observaciones (mes,anio,texto) VALUES (?,?,?)");
    $i->bind_param("iis",$mes,$anio,$texto);
    $i->execute();
    $i->close();
}

echo json_encode(['ok'=>true]);
