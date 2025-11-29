<?php
header("Content-Type: application/json");
include "../conexion.php";

$sql = "SELECT id, nombre, telefono FROM jugadores ORDER BY nombre ASC";
$result = $conexion->query($sql);

$jugadores = [];
while ($row = $result->fetch_assoc()) {
    $jugadores[] = $row;
}

echo json_encode($jugadores);
