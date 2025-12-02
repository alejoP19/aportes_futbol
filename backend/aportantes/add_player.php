<?php
// backend/add_player.php
header("Content-Type: application/json");
ini_set('display_errors',1);
error_reporting(E_ALL);

include "../auth/auth.php";
protegerAdmin();
include __DIR__ . "/../../conexion.php";

// --- Obtener datos
$raw = file_get_contents("php://input");
$data = json_decode($raw, true);
if (!$data || !is_array($data)) $data = $_POST;

$nombre = isset($data['nombre']) ? trim($data['nombre']) : '';
$telefono = isset($data['telefono']) ? trim($data['telefono']) : '';

if ($nombre === '' || $telefono === '') {
    echo json_encode(["status"=>"error","msg"=>"Datos incompletos"]);
    exit;
}

// --- Validar que el nombre no exista (sin importar mayúsculas/minúsculas)
$stmt = $conexion->prepare("SELECT id FROM jugadores WHERE LOWER(nombre)=LOWER(?) LIMIT 1");
$stmt->bind_param("s", $nombre);
$stmt->execute();
if($stmt->get_result()->num_rows > 0){
    echo json_encode(['status'=>'error','msg'=>'Nombre de jugador ya existe']);
    exit;
}

// --- Insertar nuevo jugador
$stmt = $conexion->prepare("INSERT INTO jugadores (nombre, telefono) VALUES (?, ?)");
$stmt->bind_param("ss", $nombre, $telefono);
if ($stmt->execute()) {
    $insertId = $stmt->insert_id;
    echo json_encode(["status"=>"ok","id"=>$insertId]);
} else {
    echo json_encode(["status"=>"error","msg"=>"Error al guardar el jugador"]);
}
?>
