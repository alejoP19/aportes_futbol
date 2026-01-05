<?php
header("Content-Type: application/json");


$id = intval($_POST['id'] ?? 0);

if (!$id) {
    echo json_encode(['ok'=>false, 'msg'=>'ID inválido']);
    exit;
}

$del = $conexion->prepare("DELETE FROM gastos WHERE id=?");
$del->bind_param("i", $id);
$del->execute();
$del->close();

echo json_encode(['ok'=>true]);
