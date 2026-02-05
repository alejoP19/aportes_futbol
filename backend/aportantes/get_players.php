
<?php
// backend/aportantes/get_players.php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . "/../../conexion.php";
require_once __DIR__ . "/../auth/auth.php";
protegerAdmin();
// muy importante para que no se mezclen errores HTML
ini_set('display_errors', 0);
error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING);

header("Content-Type: application/json; charset=utf-8");

// ✅ si no es admin, devolver JSON, NO redirigir HTML
if (!esAdmin()) {
    http_response_code(401);
    echo json_encode([
        "ok"  => false,
        "msg" => "No autorizado"
    ]);
    exit;
}

$players = [];

$res = $conexion->query("
    SELECT id, nombre, telefono, activo
    FROM jugadores
    ORDER BY nombre ASC
");

while ($row = $res->fetch_assoc()) {
    $players[] = [
        "id"       => (int)$row["id"],
        "nombre"   => $row["nombre"],
        "telefono" => $row["telefono"],
        "activo"   => (int)$row["activo"],
    ];
}

echo json_encode($players);
exit;
