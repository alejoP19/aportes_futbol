<?php
// backend/add_player.php (DEBUG)
header("Content-Type: application/json");
ini_set('display_errors',1);
error_reporting(E_ALL);

$logfile = __DIR__ . '/debug_add_player.log';
file_put_contents($logfile, "=== REQUEST at " . date('c') . " ===\n", FILE_APPEND);

// include conexion

include "../auth/auth.php";
protegerAdmin();

include __DIR__ . "/../../conexion.php";


// 1) Raw body (posible JSON)
$raw = file_get_contents("php://input");
file_put_contents($logfile, "RAW BODY:\n" . $raw . "\n", FILE_APPEND);

// try parse JSON
$data = json_decode($raw, true);
if (json_last_error() !== JSON_ERROR_NONE) {
    file_put_contents($logfile, "JSON ERROR: " . json_last_error_msg() . "\n", FILE_APPEND);
}

// 2) If no JSON, fall back to $_POST
if (!$data || !is_array($data)) {
    $data = $_POST;
    file_put_contents($logfile, "USING _POST\n", FILE_APPEND);
    file_put_contents($logfile, print_r($data, true), FILE_APPEND);
} else {
    file_put_contents($logfile, "USING JSON\n" . print_r($data, true), FILE_APPEND);
}

$nombre = isset($data['nombre']) ? trim($data['nombre']) : '';
$telefono = isset($data['telefono']) ? trim($data['telefono']) : '';

if ($nombre === '' || $telefono === '') {
    $msg = ["status"=>"error","msg"=>"Datos incompletos","received"=>$data];
    file_put_contents($logfile, "VALIDATION FAILED: " . json_encode($msg) . "\n\n", FILE_APPEND);
    echo json_encode($msg);
    exit;
}

// prepare insert
$sql = "INSERT INTO jugadores (nombre, telefono) VALUES (?, ?)";
$stmt = $conexion->prepare($sql);
if (!$stmt) {
    $err = $conexion->error;
    file_put_contents($logfile, "PREPARE ERROR: $err\n\n", FILE_APPEND);
    echo json_encode(["status"=>"error","msg"=>"prepare failed","error"=>$err]);
    exit;
}

if (!$stmt->bind_param("ss", $nombre, $telefono)) {
    $err = $stmt->error;
    file_put_contents($logfile, "BIND ERROR: $err\n\n", FILE_APPEND);
    echo json_encode(["status"=>"error","msg"=>"bind failed","error"=>$err]);
    exit;
}

$ok = $stmt->execute();
if (!$ok) {
    $err = $stmt->error;
    file_put_contents($logfile, "EXECUTE ERROR: $err\n\n", FILE_APPEND);
    echo json_encode(["status"=>"error","msg"=>"execute failed","error"=>$err]);
    exit;
}

$insertId = $stmt->insert_id;
file_put_contents($logfile, "INSERT OK. ID: $insertId\n\n", FILE_APPEND);
echo json_encode(["status"=>"ok","id"=>$insertId]);
