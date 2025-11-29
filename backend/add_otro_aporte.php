<?php
header("Content-Type: application/json");
include "../conexion.php";

$id_jugador = intval($_POST['id_jugador'] ?? 0);
$mes = intval($_POST['mes'] ?? date('n'));
$anio = intval($_POST['anio'] ?? date('Y'));
$tipo = trim($_POST['tipo'] ?? '');
$valor = intval($_POST['valor'] ?? 0);

if (!$id_jugador || $tipo === '') {
    echo json_encode(['ok'=>false,'msg'=>'datos incompletos']);
    exit;
}

$stmt = $conexion->prepare("INSERT INTO otros_aportes (id_jugador, mes, anio, tipo, valor) VALUES (?, ?, ?, ?, ?)");
$stmt->bind_param("iiisi", $id_jugador, $mes, $anio, $tipo, $valor);

$ok = $stmt->execute();
$stmt->close();

echo json_encode(['ok'=>(bool)$ok]);
