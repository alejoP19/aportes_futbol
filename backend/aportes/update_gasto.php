<?php
header("Content-Type: application/json");
include "../../conexion.php";

$id = intval($_POST['id'] ?? 0);
$nombre = trim($_POST['nombre'] ?? '');
$valor = intval($_POST['valor'] ?? 0);

if (!$id || $nombre === '' || $valor <= 0) {
    echo json_encode(['ok'=>false, 'msg'=>'Datos inválidos']);
    exit;
}

$u = $conexion->prepare("UPDATE gastos SET nombre=?, valor=? WHERE id=?");
$u->bind_param("sii", $nombre, $valor, $id);
$u->execute();
$u->close();

echo json_encode(['ok'=>true]);
