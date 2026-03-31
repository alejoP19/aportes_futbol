
<?php
include "../auth/auth.php";
protegerAdmin();

include "../../conexion.php";

header("Content-Type: application/json; charset=utf-8");

$id   = isset($_POST['id']) ? intval($_POST['id']) : 0;
$mes  = isset($_POST['mes']) ? intval($_POST['mes']) : intval(date('n'));
$anio = isset($_POST['anio']) ? intval($_POST['anio']) : intval(date('Y'));

if ($id <= 0) {
    echo json_encode(["ok" => false, "msg" => "ID inválido"]);
    exit;
}

if ($mes < 1 || $mes > 12) {
    echo json_encode(["ok" => false, "msg" => "Mes inválido"]);
    exit;
}

if ($anio < 1900 || $anio > 3000) {
    echo json_encode(["ok" => false, "msg" => "Año inválido"]);
    exit;
}

// usar último día del mes seleccionado
$fechaBaja = date("Y-m-t", strtotime(sprintf("%04d-%02d-01", $anio, $mes)));

// 1) Obtener datos del jugador antes de eliminarlo
$stmt = $conexion->prepare("SELECT nombre FROM jugadores WHERE id = ?");
$stmt->bind_param("i", $id);
$stmt->execute();
$res = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$res) {
    echo json_encode(["ok" => false, "msg" => "Jugador no existe"]);
    exit;
}

$nombre = $res['nombre'];

// 2) Insertar en jugadores_eliminados si NO existe aún
$stmt2 = $conexion->prepare("
    INSERT INTO jugadores_eliminados (id, nombre, fecha_eliminacion)
    SELECT ?, ?, ?
    FROM dual
    WHERE NOT EXISTS (
        SELECT 1
        FROM jugadores_eliminados
        WHERE id = ?
    )
");
$stmt2->bind_param("issi", $id, $nombre, $fechaBaja, $id);
$stmt2->execute();
$stmt2->close();

// 3) Marcar jugador como inactivo usando la fecha del periodo seleccionado
$stmt3 = $conexion->prepare("
    UPDATE jugadores
    SET activo = 0, fecha_baja = ?
    WHERE id = ?
");
$stmt3->bind_param("si", $fechaBaja, $id);

if ($stmt3->execute()) {
    echo json_encode([
        "ok" => true,
        "fecha_baja" => $fechaBaja
    ]);
} else {
    echo json_encode([
        "ok" => false,
        "msg" => $conexion->error
    ]);
}
$stmt3->close();
exit;