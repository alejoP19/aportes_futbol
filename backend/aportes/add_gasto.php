<?php
header("Content-Type: application/json");
require_once __DIR__ . "../auth/auth.php"; // ajusta la ruta según tu estructura
protegerAdmin();
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");
header("Expires: Sat, 01 Jan 2000 00:00:00 GMT");
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
