<?php
header("Content-Type: application/json; charset=utf-8");
require_once __DIR__ . "/../../conexion.php";
require_once __DIR__ . "/../auth/auth.php";
protegerAdmin();

$raw = file_get_contents("php://input");
$in = json_decode($raw, true);

$slot  = intval($in["slot"] ?? 0);
$fecha = trim($in["fecha"] ?? "");
$valor = intval($in["valor"] ?? 0);

if ($slot < 1 || $slot > 22) {
  echo json_encode(["ok"=>false, "error"=>"slot inválido"]); exit;
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)) {
  echo json_encode(["ok"=>false, "error"=>"fecha inválida"]); exit;
}

// ✅ Importante: NO uses $fechaOtro aquí.
// Guardamos TODO como 'esporadico' y el sistema detecta "otros juegos"
// por DAYOFWEEK(fecha) en los reportes/totales.
$tipo = 'esporadico';

/* borrar si valor 0 */
if ($valor <= 0) {
  $del = $conexion->prepare("
    DELETE FROM aportes
    WHERE tipo_aporte=? AND esporadico_slot=? AND fecha=?
    LIMIT 1
  ");
  $del->bind_param("sis", $tipo, $slot, $fecha);
  $del->execute();
  $del->close();
  echo json_encode(["ok"=>true, "deleted"=>true]);
  exit;
}

/* upsert */
$up = $conexion->prepare("
  INSERT INTO aportes (id_jugador, fecha, aporte_principal, tipo_aporte, esporadico_slot)
  VALUES (NULL, ?, ?, ?, ?)
  ON DUPLICATE KEY UPDATE aporte_principal=VALUES(aporte_principal)
");
$up->bind_param("sisi", $fecha, $valor, $tipo, $slot);
$up->execute();
$up->close();

echo json_encode(["ok"=>true]);