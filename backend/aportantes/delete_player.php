<?php
include "../auth/auth.php";
protegerAdmin();

include "../../conexion.php";

// header("Content-Type: application/json");

// // Asegurar método POST
// if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
//     echo json_encode(["ok" => false, "msg" => "Método no permitido"]);
//     exit;
// }

// // Validar ID
// $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
// if ($id <= 0) {
//     echo json_encode(["ok" => false, "msg" => "ID inválido"]);
//     exit;
// }

// // Verificar que el jugador exista
// $check = $conexion->prepare("SELECT id FROM jugadores WHERE id = ? LIMIT 1");
// $check->bind_param("i", $id);
// $check->execute();
// $result = $check->get_result();

// if ($result->num_rows === 0) {
//     echo json_encode(["ok" => false, "msg" => "El jugador no existe"]);
//     exit;
// }

// // Marcar como inactivo
// $sql = "UPDATE jugadores SET activo = 0 WHERE id = ?";
// $stmt = $conexion->prepare($sql);
// $stmt->bind_param("i", $id);

// if ($stmt->execute()) {
//     echo json_encode(["ok" => true, "msg" => "Jugador eliminado correctamente"]);
// } else {
//     echo json_encode(["ok" => false, "msg" => "Error al eliminar: " . $stmt->error]);
// }


// SI SE DESEA QUE LOS JUADORES ELIMINADOS QUEDEN EL LA TABLA DE JUGADORES ELIMINADOS
// USAR EL CÓDIGO SIGUIENTE POR EL ANTERIOR (COMENTARLO NO BORRARLO)
// <?php
// include "../conexion.php";
// header("Content-Type: application/json");

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
    INSERT INTO jugadores_eliminados (id, nombre)
    SELECT ?, ?
    FROM dual
    WHERE NOT EXISTS (SELECT 1 FROM jugadores_eliminados WHERE id = ?)
");
$stmt2->bind_param("isi", $id, $nombre, $id);
$stmt2->execute();

// 3. Marcar el jugador como inactivo (no se borra)
$stmt3 = $conexion->prepare("UPDATE jugadores SET activo = 0 WHERE id = ?");
$stmt3->bind_param("i", $id);

if ($stmt3->execute()) {
    echo json_encode(["ok" => true]);
} else {
    echo json_encode(["ok" => false, "msg" => $conexion->error]);
}
