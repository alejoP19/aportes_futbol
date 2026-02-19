<?php
header("Content-Type: application/json; charset=utf-8");
include "../../conexion.php";
require_once __DIR__ . "/../auth/auth.php";
protegerAdmin();

$mes  = intval($_GET['mes']  ?? date('n'));
$anio = intval($_GET['anio'] ?? date('Y'));

$fechaInicio = date('Y-m-01', strtotime("$anio-$mes-01"));
$fechaCorte  = date('Y-m-t', strtotime("$anio-$mes-01"));

// ========================
// APORTES EFECTIVOS (con cap 3000 + consumido_target)
// ========================
$month_total = (int)($conexion->query("
    SELECT IFNULL(SUM(
        LEAST(a.aporte_principal + IFNULL(t.consumido,0), 3000)
    ),0) AS total_mes
    FROM aportes a
    LEFT JOIN (
        SELECT target_aporte_id, SUM(amount) AS consumido
        FROM aportes_saldo_moves
        GROUP BY target_aporte_id
    ) t ON t.target_aporte_id = a.id
    WHERE MONTH(a.fecha) = $mes
      AND YEAR(a.fecha)  = $anio
")->fetch_assoc()['total_mes'] ?? 0);

$year_total = (int)($conexion->query("
    SELECT IFNULL(SUM(
        LEAST(a.aporte_principal + IFNULL(t.consumido,0), 3000)
    ),0) AS total_anio
    FROM aportes a
    LEFT JOIN (
        SELECT target_aporte_id, SUM(amount) AS consumido
        FROM aportes_saldo_moves
        GROUP BY target_aporte_id
    ) t ON t.target_aporte_id = a.id
    WHERE YEAR(a.fecha) = $anio
      AND MONTH(a.fecha) <= $mes
")->fetch_assoc()['total_anio'] ?? 0);

// ========================
// OTROS APORTES
// ========================
$otros_mes = (int)$conexion->query("
    SELECT IFNULL(SUM(valor),0)
    FROM otros_aportes
    WHERE mes=$mes AND anio=$anio
")->fetch_row()[0];

$otros_anio = (int)$conexion->query("
    SELECT IFNULL(SUM(valor),0)
    FROM otros_aportes
    WHERE anio=$anio AND mes<=$mes
")->fetch_row()[0];

// ========================
// GASTOS
// ========================
$gastos_mes = (int)$conexion->query("
    SELECT IFNULL(SUM(valor),0)
    FROM gastos
    WHERE mes=$mes AND anio=$anio
")->fetch_row()[0];

$gastos_anio = (int)$conexion->query("
    SELECT IFNULL(SUM(valor),0)
    FROM gastos
    WHERE anio=$anio AND mes<=$mes
")->fetch_row()[0];

// ========================
// SALDO TOTAL HASTA FECHA CORTE (incluye eliminados)
// ========================
$saldo_total = (int)($conexion->query("
    SELECT IFNULL(SUM(
        GREATEST(IFNULL(ex.excedente,0) - IFNULL(co.consumido,0), 0)
    ),0) AS saldo
    FROM jugadores j
    LEFT JOIN (
        SELECT id_jugador, SUM(GREATEST(aporte_principal - 3000, 0)) AS excedente
        FROM aportes
        WHERE fecha <= '$fechaCorte'
        GROUP BY id_jugador
    ) ex ON ex.id_jugador = j.id
    LEFT JOIN (
        SELECT id_jugador, SUM(amount) AS consumido
        FROM aportes_saldo_moves
        WHERE fecha_consumo <= '$fechaCorte'
        GROUP BY id_jugador
    ) co ON co.id_jugador = j.id
")->fetch_assoc()['saldo'] ?? 0);

// ========================
// ELIMINADOS (ids)
// ========================
function build_in_clause($ids){
    if (empty($ids)) return "NULL";
    return implode(",", array_map("intval", $ids));
}

// eliminados ESTE MES (para info del modal y para separar “parcial”)
$elimIdsMes = [];
$stmt = $conexion->prepare("
    SELECT id
    FROM jugadores
    WHERE activo=0
      AND fecha_baja IS NOT NULL
      AND fecha_baja >= ?
      AND fecha_baja <= ?
");
$stmt->bind_param("ss", $fechaInicio, $fechaCorte);
$stmt->execute();
$res = $stmt->get_result();
while($r = $res->fetch_assoc()) $elimIdsMes[] = (int)$r['id'];
$stmt->close();

// eliminados HASTA FECHA CORTE (para saldo eliminados y total año eliminados)
$elimIdsHasta = [];
$stmt = $conexion->prepare("
    SELECT id
    FROM jugadores
    WHERE activo=0
      AND fecha_baja IS NOT NULL
      AND fecha_baja <= ?
");
$stmt->bind_param("s", $fechaCorte);
$stmt->execute();
$res = $stmt->get_result();
while($r = $res->fetch_assoc()) $elimIdsHasta[] = (int)$r['id'];
$stmt->close();

$inMes   = build_in_clause($elimIdsMes);
$inHasta = build_in_clause($elimIdsHasta);

// aportes efectivos de eliminados (mes)
$eliminados_mes_total = (int)($conexion->query("
    SELECT IFNULL(SUM(
        LEAST(a.aporte_principal + IFNULL(t.consumido,0), 3000)
    ),0) AS total
    FROM aportes a
    LEFT JOIN (
        SELECT target_aporte_id, SUM(amount) AS consumido
        FROM aportes_saldo_moves
        GROUP BY target_aporte_id
    ) t ON t.target_aporte_id = a.id
    WHERE YEAR(a.fecha)=$anio AND MONTH(a.fecha)=$mes
      AND a.id_jugador IN ($inMes)
")->fetch_assoc()['total'] ?? 0);

// aportes efectivos de eliminados (año hasta mes)
$eliminados_anio_total = (int)($conexion->query("
    SELECT IFNULL(SUM(
        LEAST(a.aporte_principal + IFNULL(t.consumido,0), 3000)
    ),0) AS total
    FROM aportes a
    LEFT JOIN (
        SELECT target_aporte_id, SUM(amount) AS consumido
        FROM aportes_saldo_moves
        GROUP BY target_aporte_id
    ) t ON t.target_aporte_id = a.id
    WHERE YEAR(a.fecha)=$anio AND MONTH(a.fecha) <= $mes
      AND a.id_jugador IN ($inHasta)
")->fetch_assoc()['total'] ?? 0);

// saldo de eliminados (hasta corte)
$saldo_eliminados_total = (int)($conexion->query("
    SELECT IFNULL(SUM(
        GREATEST(IFNULL(ex.excedente,0) - IFNULL(co.consumido,0), 0)
    ),0) AS saldo
    FROM (
        SELECT id
        FROM jugadores
        WHERE id IN ($inHasta)
    ) j
    LEFT JOIN (
        SELECT id_jugador, SUM(GREATEST(aporte_principal - 3000, 0)) AS excedente
        FROM aportes
        WHERE fecha <= '$fechaCorte'
        GROUP BY id_jugador
    ) ex ON ex.id_jugador = j.id
    LEFT JOIN (
        SELECT id_jugador, SUM(amount) AS consumido
        FROM aportes_saldo_moves
        WHERE fecha_consumo <= '$fechaCorte'
        GROUP BY id_jugador
    ) co ON co.id_jugador = j.id
")->fetch_assoc()['saldo'] ?? 0);

// ========================
// TOTALES SEGÚN TU DEFINICIÓN
// ========================

// Parciales: “sin saldos, sin otros, sin eliminados”
$parcial_mes  = (int)($month_total - $eliminados_mes_total);
$parcial_anio = (int)($year_total  - $eliminados_anio_total);

// Estimados: “con saldo + otros + eliminados, sin gastos”
$estimado_mes  = (int)($month_total + $otros_mes + $saldo_total);
$estimado_anio = (int)($year_total  + $otros_anio + $saldo_total);

// Finales: “estimado - gastos”
$final_mes  = (int)($estimado_mes  - $gastos_mes);
$final_anio = (int)($estimado_anio - $gastos_anio);

// Limpieza de buffer por si algo imprimió espacios
if (ob_get_length()) { ob_clean(); }

echo json_encode([
    "ok" => true,

    // base (incluye eliminados)
    "month_total" => $month_total,
    "year_total"  => $year_total,

    // tu estructura clara
    "parcial_mes"  => $parcial_mes,
    "parcial_anio" => $parcial_anio,
    "otros_mes"    => $otros_mes,
    "otros_anio"   => $otros_anio,
    "saldo_total"  => $saldo_total,
    "estimado_mes" => $estimado_mes,
    "estimado_anio"=> $estimado_anio,
    "gastos_mes"   => $gastos_mes,
    "gastos_anio"  => $gastos_anio,
    "final_mes"    => $final_mes,
    "final_anio"   => $final_anio,

    // info eliminados (para modal y para explicar)
    "eliminados_mes_total"   => $eliminados_mes_total,
    "eliminados_anio_total"  => $eliminados_anio_total,
    "saldo_eliminados_total" => $saldo_eliminados_total,
], JSON_UNESCAPED_UNICODE);
