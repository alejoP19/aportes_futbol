<?php
// backend/aportes/deudas.php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . "/../../conexion.php";
require_once __DIR__ . "/../auth/auth.php";

header("Content-Type: application/json; charset=utf-8");

// ✅ Opción: validar admin sin redirigir
if (!esAdmin()) {
    http_response_code(401);
    echo json_encode([
        "ok"  => false,
        "msg" => "No autorizado"
    ]);
    exit;
}

// Leer JSON del body
$raw  = file_get_contents("php://input");
$data = json_decode($raw, true);

$accion     = $data['accion']     ?? '';
$id_jugador = intval($data['id_jugador'] ?? 0);
$fecha      = $data['fecha']      ?? '';

if (!$id_jugador || !$fecha) {
    echo json_encode(["ok" => false, "msg" => "Datos incompletos"]);
    exit;
}

try {

    switch ($accion) {
        case "agregar":
            // AGREGA SOLO ESE DÍA
            $stmt = $conexion->prepare("
                INSERT IGNORE INTO deudas_aportes (id_jugador, fecha)
                VALUES (?, ?)
            ");
            $stmt->bind_param("is", $id_jugador, $fecha);
            $stmt->execute();
            $stmt->close();
            echo json_encode(["ok" => true]);
            break;

        case "borrar":
            // BORRA SOLO ESE DÍA
            $stmt = $conexion->prepare("
                DELETE FROM deudas_aportes
                WHERE id_jugador = ? AND fecha = ?
            ");
            $stmt->bind_param("is", $id_jugador, $fecha);
            $stmt->execute();
            $stmt->close();
            echo json_encode(["ok" => true]);
            break;

        case "borrar_todas":
            // BORRA TODAS LAS DEUDAS DE ESE JUGADOR
            $stmt = $conexion->prepare("
                DELETE FROM deudas_aportes
                WHERE id_jugador = ?
            ");
            $stmt->bind_param("i", $id_jugador);
            $stmt->execute();
            $stmt->close();
            echo json_encode(["ok" => true]);
            break;

        default:
            echo json_encode(["ok" => false, "msg" => "Acción inválida"]);
            break;
    }

} catch (Throwable $e) {
    // ❗No imprimimos HTML, solo JSON
    echo json_encode(["ok" => false, "msg" => "Error interno al procesar la deuda"]);
}
