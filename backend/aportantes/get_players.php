<?php
include "../auth/auth.php";
protegerAdmin();
include "../../conexion.php";
header("Content-Type: application/json");

$res = $conexion->query("SELECT id, nombre FROM jugadores WHERE activo = 1 ORDER BY nombre ASC
");

$jugadores = [];
while ($row = $res->fetch_assoc()) {
    $jugadores[] = $row;
}

echo json_encode($jugadores);
