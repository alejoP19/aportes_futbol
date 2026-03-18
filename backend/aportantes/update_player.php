<?php
require_once __DIR__ . "/../../conexion.php";
require_once __DIR__ . "/../auth/auth.php";
protegerAdmin();

header("Content-Type: application/json; charset=utf-8");

$id       = intval($_POST['id'] ?? 0);
$nombre   = trim($_POST['nombre'] ?? '');
$telefono = trim($_POST['telefono'] ?? '');

if ($id <= 0 || $nombre === '') {
    echo json_encode([
        "ok"  => false,
        "msg" => "Datos inválidos"
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$stmt = $conexion->prepare("
    UPDATE jugadores
    SET nombre = ?, telefono = ?
    WHERE id = ?
");

if (!$stmt) {
    echo json_encode([
        "ok"  => false,
        "msg" => "Error preparando la consulta"
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$stmt->bind_param("ssi", $nombre, $telefono, $id);

if ($stmt->execute()) {
    echo json_encode([
        "ok"  => true,
        "msg" => "Jugador actualizado correctamente"
    ], JSON_UNESCAPED_UNICODE);
} else {
    echo json_encode([
        "ok"  => false,
        "msg" => "Error al actualizar el jugador"
    ], JSON_UNESCAPED_UNICODE);
}

$stmt->close();
exit;