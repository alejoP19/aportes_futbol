<?php
header("Content-Type: application/json");
include "../conexion.php";

$data = json_decode(file_get_contents("php://input"), true);

$id_jugador = $data["id_jugador"];
$fecha = $data["fecha"];
$campo = $data["campo"];
$valor = (int)$data["valor"];

// Validación del campo permitido
$permitidos = ["aporte_principal", "otro_aporte"];
if (!in_array($campo, $permitidos)) {
    echo json_encode(["status" => "error", "msg" => "Campo inválido"]);
    exit;
}

// Insertar si no existe
$conexion->query("
    INSERT INTO aportes (id_jugador, fecha, aporte_principal, otro_aporte) 
    VALUES ($id_jugador, '$fecha', 0, 0)
    ON DUPLICATE KEY UPDATE id=id
");

// Actualizar el campo solicitado
$sql = $conexion->prepare("UPDATE aportes SET $campo = ? WHERE id_jugador = ? AND fecha = ?");
$sql->bind_param("iis", $valor, $id_jugador, $fecha);
$sql->execute();

echo json_encode(["status" => "ok"]);
