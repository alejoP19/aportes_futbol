<?php
include "../../conexion.php";
require_once __DIR__ . "../auth/auth.php"; // ajusta la ruta según tu estructura


$data = json_decode(file_get_contents("php://input"), true);

$accion     = $data['accion'] ?? '';
$id_jugador = intval($data['id_jugador'] ?? 0);
$fecha      = $data['fecha'] ?? '';

if (!$id_jugador || !$fecha) {
    echo json_encode(["ok" => false, "msg" => "Datos incompletos"]);
    exit;
}

// ============================
// OPCIÓN A — Deuda POR DÍA
// ============================

if ($accion === "agregar") {

    // AGREGA SOLO ESE DÍA
    $stmt = $conexion->prepare("
        INSERT IGNORE INTO deudas_aportes (id_jugador, fecha)
        VALUES (?, ?)
    ");
    $stmt->bind_param("is", $id_jugador, $fecha);
    $stmt->execute();
    $stmt->close();

    echo json_encode(["ok" => true]);
    exit;
}

if ($accion === "borrar") {

    // BORRA SOLO ESE DÍA
    $stmt = $conexion->prepare("
        DELETE FROM deudas_aportes
        WHERE id_jugador = ? AND fecha = ?
    ");
    $stmt->bind_param("is", $id_jugador, $fecha);
    $stmt->execute();
    $stmt->close();

    echo json_encode(["ok" => true]);
    exit;
}

echo json_encode(["ok" => false, "msg" => "Acción inválida"]);
if ($accion === "borrar_todas") {
    $stmt = $conexion->prepare("
        DELETE FROM deudas_aportes
        WHERE id_jugador = ?
    ");
    $stmt->bind_param("i", $id_jugador);
    $stmt->execute();
    $stmt->close();
    
    echo json_encode(["ok" => true]);
    exit;
}
