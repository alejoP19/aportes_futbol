<?php
include "../auth/auth.php";
protegerAdmin();

include "../../conexion.php";



$id = isset($_POST['id']) ? intval($_POST['id']) : 0;

if ($id <= 0) {
    echo json_encode(["ok" => false, "msg" => "ID inválido"]);
    exit;
}

// 1. Obtener datos del jugador antes de eliminarlo
$stmt = $conexion->prepare("SELECT nombre FROM jugadores WHERE id = ?");
$stmt->bind_param("i", $id);
$stmt->execute();
$res = $stmt->get_result()->fetch_assoc();

if (!$res) {
    echo json_encode(["ok" => false, "msg" => "Jugador no existe"]);
    exit;
}

$nombre = $res['nombre'];

// 2. Insertar en jugadores_eliminados si NO existe aún
$stmt2 = $conexion->prepare("
    INSERT INTO jugadores_eliminados (id, nombre, fecha_eliminacion)
    SELECT ?, ?, CURDATE()
    FROM dual
    WHERE NOT EXISTS (SELECT 1 FROM jugadores_eliminados WHERE id = ?)
");
$stmt2->bind_param("isi", $id, $nombre, $id);
$stmt2->execute();


// 3. Marcar el jugador como inactivo (no se borra)
$stmt3 = $conexion->prepare("UPDATE jugadores SET activo = 0, fecha_baja = CURDATE() WHERE id = ?");
$stmt3->bind_param("i", $id);


if ($stmt3->execute()) {
    echo json_encode(["ok" => true]);
} else {
    echo json_encode(["ok" => false, "msg" => $conexion->error]);
}
