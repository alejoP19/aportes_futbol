<?php
include "../../conexion.php";
header("Content-Type: application/json; charset=utf-8");

$sql = "
  SELECT 
    id,
    nombre,
    telefono,
    fecha_baja,
    activo,
    YEAR(fecha_baja)  AS anio_baja,
    MONTH(fecha_baja) AS mes_baja
  FROM jugadores
  WHERE activo = 0
    AND fecha_baja IS NOT NULL
  ORDER BY fecha_baja DESC, nombre ASC
";

$res = $conexion->query($sql);

if (!$res) {
  echo json_encode([
    "ok" => false,
    "msg" => "Error consultando eliminados"
  ]);
  exit;
}

$items = [];
while ($row = $res->fetch_assoc()) {
  $items[] = [
    "id" => (int)$row["id"],
    "nombre" => $row["nombre"] ?? "",
    "telefono" => $row["telefono"] ?? "",
    "fecha_baja" => $row["fecha_baja"] ?? "",
    "activo" => (int)($row["activo"] ?? 0),
    "anio_baja" => isset($row["anio_baja"]) ? (int)$row["anio_baja"] : null,
    "mes_baja"  => isset($row["mes_baja"]) ? (int)$row["mes_baja"] : null,
  ];
}

echo json_encode([
  "ok" => true,
  "items" => $items
]);