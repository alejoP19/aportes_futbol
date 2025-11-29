<?php
// backend/guardar_aporte.php
header("Content-Type: application/json");
include "../conexion.php";

$input = file_get_contents("php://input");
$data = json_decode($input, true);

$id_jugador = intval($data['id_jugador'] ?? 0);
$fecha = $data['fecha'] ?? '';
$valor = intval($data['valor'] ?? 0);

if (!$id_jugador || !$fecha) {
    echo json_encode(['ok'=>false,'msg'=>'datos invalidos']);
    exit;
}

// insertar o actualizar
$stmt = $conexion->prepare("
  INSERT INTO aportes (id_jugador, fecha, aporte_principal)
  VALUES (?, ?, ?)
  ON DUPLICATE KEY UPDATE aporte_principal = VALUES(aporte_principal)
");
$stmt->bind_param("isi", $id_jugador, $fecha, $valor);
$ok = $stmt->execute();
$stmt->close();

echo json_encode(['ok' => (bool)$ok]);
