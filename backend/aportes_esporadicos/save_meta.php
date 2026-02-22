<?php
header("Content-Type: application/json; charset=utf-8");
require_once __DIR__ . "/../../conexion.php";
require_once __DIR__ . "/../auth/auth.php";
protegerAdmin();

$raw = file_get_contents("php://input");
$in = json_decode($raw, true);

$slot  = intval($in["slot"] ?? 0);
$mes   = intval($in["mes"] ?? 0);
$anio  = intval($in["anio"] ?? 0);
$valor = intval($in["otro_aporte"] ?? 0);
$nota  = trim($in["nota"] ?? "");

if ($slot < 1 || $slot > 22) { echo json_encode(["ok"=>false,"msg"=>"slot inválido"]); exit; }
if ($mes < 1 || $mes > 12)   { echo json_encode(["ok"=>false,"msg"=>"mes inválido"]); exit; }
if ($anio < 1900)            { echo json_encode(["ok"=>false,"msg"=>"año inválido"]); exit; }

// lo guardamos como aporte en tabla aportes, tipo "esporadico_otro"
// fecha = primer día del mes (solo para agrupar por mes/año)
$fecha = sprintf("%04d-%02d-01", $anio, $mes);
$tipo  = "esporadico_otro";

// si valor 0 y nota vacía => borra
if ($valor <= 0 && $nota === "") {
  $del = $conexion->prepare("
    DELETE FROM aportes
    WHERE tipo_aporte=? AND esporadico_slot=? AND fecha=?
    LIMIT 1
  ");
  $del->bind_param("sis", $tipo, $slot, $fecha);
  $del->execute();
  $del->close();
  echo json_encode(["ok"=>true,"deleted"=>true]);
  exit;
}

// necesitas columna para nota (recomendado). Si NO la quieres, quita nota del SQL.
$conexion->query("ALTER TABLE aportes ADD COLUMN IF NOT EXISTS nota VARCHAR(255) NULL");

$up = $conexion->prepare("
  INSERT INTO aportes (id_jugador, fecha, aporte_principal, tipo_aporte, esporadico_slot, nota)
  VALUES (NULL, ?, ?, ?, ?, ?)
  ON DUPLICATE KEY UPDATE aporte_principal=VALUES(aporte_principal), nota=VALUES(nota)
");
$up->bind_param("sisis", $fecha, $valor, $tipo, $slot, $nota);
$up->execute();
$up->close();

echo json_encode(["ok"=>true]);