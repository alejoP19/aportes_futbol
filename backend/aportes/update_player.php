<?php
// backend/aportantes/update_player.php
require_once __DIR__ . "/../../conexion.php";
require_once __DIR__ . "/../auth/auth.php";
protegerAdmin();

header("Content-Type: application/json; charset=utf-8");

// 1. Datos del POST
$id       = intval($_POST['id'] ?? 0);
$nombre   = trim($_POST['nombre'] ?? '');
$telefono = trim($_POST['telefono'] ?? '');

if ($id <= 0 || $nombre === '') {
    echo json_encode([
        "ok"  => false,
        "msg" => "Datos inválidos"
    ]);
    exit;
}

// 2. Actualizar en BD
$stmt = $conexion->prepare("UPDATE jugadores SET nombre = ?, telefono = ? WHERE id = ?");
$stmt->bind_param("ssi", $nombre, $telefono, $id);

if ($stmt->execute()) {
    echo json_encode([
        "ok"  => true,
        "msg" => "Jugador actualizado correctamente"
    ]);
} else {
    echo json_encode([
        "ok"  => false,
        "msg" => "Error al actualizar el jugador"
    ]);
}

$stmt->close();
exit;
