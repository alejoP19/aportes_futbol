<?php
header("Content-Type: application/json");
include "../auth/auth.php";
protegerAdmin();
include "../../conexion.php";

$nombre = trim($_POST['nombre'] ?? '');
$valor = intval($_POST['valor'] ?? 0);
$mes = intval($_POST['mes'] ?? date('n'));
$anio = intval($_POST['anio'] ?? date('Y'));

if ($nombre == '' || $valor <= 0) {
    echo json_encode(['ok'=>false, 'msg'=>'Datos inválidos']);
    exit;
}

$stmt = $conexion->prepare("INSERT INTO gastos (nombre,valor,mes,anio) VALUES (?,?,?,?)");
$stmt->bind_param("siii", $nombre, $valor, $mes, $anio);
$stmt->execute();

echo json_encode(['ok'=>true]);

