<?php
require_once __DIR__ . "/../../conexion.php";
require_once __DIR__ . "/../auth/auth.php";
protegerAdmin();

header("Content-Type: application/json; charset=utf-8");

$id = intval($_POST["id"] ?? 0);

if ($id <= 0) {
    echo json_encode([
        "ok" => false,
        "msg" => "ID inválido"
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$stmt = $conexion->prepare("
    UPDATE jugadores
    SET activo = 1,
        fecha_baja = NULL
    WHERE id = ?
");
$stmt->bind_param("i", $id);

if ($stmt->execute()) {
    echo json_encode([
        "ok" => true,
        "msg" => "Jugador reactivado correctamente"
    ], JSON_UNESCAPED_UNICODE);
} else {
    echo json_encode([
        "ok" => false,
        "msg" => "No se pudo reactivar el jugador"
    ], JSON_UNESCAPED_UNICODE);
}

$stmt->close();
exit;