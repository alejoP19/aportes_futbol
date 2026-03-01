<?php
header("Content-Type: application/json");
include "../../conexion.php";
require_once __DIR__ . "/../auth/auth.php";
protegerAdmin();



$mes  = intval($_GET['mes']  ?? date('n'));
$anio = intval($_GET['anio'] ?? date('Y'));

if ($mes < 1 || $mes > 12) $mes = (int)date('n');
if ($anio < 1900) $anio = (int)date('Y');

$TOPE = 3000;

$fechaInicio = date('Y-m-01', strtotime("$anio-$mes-01"));
$fechaCorte  = date('Y-m-t', strtotime("$anio-$mes-01"));

/**
 * ✅ LÓGICA NUEVA (para que NO sea confuso):
 * - month_total: registrados efectivo
 * - esporadicos_mes: suma completa
 * - otros_mes: otros_aportes + esporadico_otro
 * - saldo_total: saldo foto al corte del mes
 * - gastos_mes
 *
 * ✅ parcial_mes = month_total + esporadicos_mes + otros_mes
 * ✅ estimado_mes = parcial_mes + saldo_total
 * ✅ final_mes = estimado_mes - gastos_mes
 */
function calcular_totales_mes($conexion, $anio, $mes, $TOPE = 3000) {
    $fechaCorteLocal = date('Y-m-t', strtotime("$anio-$mes-01"));

    // 1) REGISTRADOS EFECTIVO
    $month_total = (int)($conexion->query("
        SELECT IFNULL(SUM(
            LEAST(
                LEAST(IFNULL(a.aporte_principal,0), $TOPE) + IFNULL(t.consumido,0),
                $TOPE
            )
        ),0) AS total_mes
        FROM aportes a
        LEFT JOIN (
            SELECT target_aporte_id, SUM(amount) AS consumido
            FROM aportes_saldo_moves
            GROUP BY target_aporte_id
        ) t ON t.target_aporte_id = a.id
        WHERE YEAR(a.fecha) = $anio
          AND MONTH(a.fecha) = $mes
          AND a.id_jugador IS NOT NULL
          AND a.tipo_aporte IS NULL
    ")->fetch_assoc()['total_mes'] ?? 0);

    // 2) ESPORÁDICOS
    $esporadicos_mes = (int)($conexion->query("
        SELECT IFNULL(SUM(aporte_principal),0) AS s
        FROM aportes
        WHERE tipo_aporte='esporadico'
          AND YEAR(fecha)=$anio AND MONTH(fecha)=$mes
    ")->fetch_assoc()['s'] ?? 0);

    // 3) OTROS APORTES (otros_aportes + esporadico_otro)
    $otros_mes = (int)($conexion->query("
        SELECT IFNULL(SUM(valor),0) AS s
        FROM otros_aportes
        WHERE mes=$mes AND anio=$anio
    ")->fetch_assoc()['s'] ?? 0);

    $otros_esporadicos_mes = (int)($conexion->query("
        SELECT IFNULL(SUM(aporte_principal),0) AS s
        FROM aportes
        WHERE tipo_aporte='esporadico_otro'
          AND YEAR(fecha)=$anio AND MONTH(fecha)=$mes
    ")->fetch_assoc()['s'] ?? 0);

    $otros_mes += $otros_esporadicos_mes;

    // 4) GASTOS DEL MES
    $gastos_mes = (int)($conexion->query("
        SELECT IFNULL(SUM(valor),0) AS s
        FROM gastos
        WHERE mes=$mes AND anio=$anio
    ")->fetch_assoc()['s'] ?? 0);

    // 5) SALDO TOTAL (foto al corte del mes)
    $saldo_total = (int)($conexion->query("
        SELECT IFNULL(SUM(
            GREATEST(IFNULL(ex.excedente,0) - IFNULL(co.consumido,0), 0)
        ),0) AS saldo
        FROM jugadores j
        LEFT JOIN (
            SELECT id_jugador, SUM(GREATEST(aporte_principal - $TOPE, 0)) AS excedente
            FROM aportes
            WHERE fecha <= '$fechaCorteLocal'
              AND id_jugador IS NOT NULL
              AND tipo_aporte IS NULL
            GROUP BY id_jugador
        ) ex ON ex.id_jugador = j.id
        LEFT JOIN (
            SELECT id_jugador, SUM(amount) AS consumido
            FROM aportes_saldo_moves
            WHERE fecha_consumo <= '$fechaCorteLocal'
            GROUP BY id_jugador
        ) co ON co.id_jugador = j.id
    ")->fetch_assoc()['saldo'] ?? 0);

    // ✅ NUEVO
    $parcial_mes  = (int)($month_total + $esporadicos_mes + $otros_mes);
    $estimado_mes = (int)($parcial_mes + $saldo_total);
    $final_mes    = (int)($estimado_mes - $gastos_mes);

    return [
        "month_total"      => $month_total,
        "esporadicos_mes"  => $esporadicos_mes,
        "otros_mes"        => $otros_mes,
        "gastos_mes"       => $gastos_mes,
        "saldo_total"      => $saldo_total,
        "parcial_mes"      => $parcial_mes,
        "estimado_mes"     => $estimado_mes,
        "final_mes"        => $final_mes,
    ];
}

// =====================================================
// ELIMINADOS (informativo) ids eliminados en mes / hasta fechaCorte
// =====================================================
function build_in_clause($ids){
    if (empty($ids)) return "NULL";
    return implode(",", array_map("intval", $ids));
}

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

// eliminados mes (registrados efectivo)
$eliminados_mes_total = (int)($conexion->query("
    SELECT IFNULL(SUM(
        LEAST(LEAST(IFNULL(a.aporte_principal,0), $TOPE) + IFNULL(t.consumido,0), $TOPE)
    ),0) AS total
    FROM aportes a
    LEFT JOIN (
        SELECT target_aporte_id, SUM(amount) AS consumido
        FROM aportes_saldo_moves
        GROUP BY target_aporte_id
    ) t ON t.target_aporte_id = a.id
    WHERE YEAR(a.fecha)=$anio AND MONTH(a.fecha)=$mes
      AND a.id_jugador IN ($inMes)
      AND a.tipo_aporte IS NULL
")->fetch_assoc()['total'] ?? 0);

// eliminados año (registrados efectivo)
$eliminados_anio_total = (int)($conexion->query("
    SELECT IFNULL(SUM(
        LEAST(LEAST(IFNULL(a.aporte_principal,0), $TOPE) + IFNULL(t.consumido,0), $TOPE)
    ),0) AS total
    FROM aportes a
    LEFT JOIN (
        SELECT target_aporte_id, SUM(amount) AS consumido
        FROM aportes_saldo_moves
        GROUP BY target_aporte_id
    ) t ON t.target_aporte_id = a.id
    WHERE YEAR(a.fecha)=$anio AND MONTH(a.fecha) <= $mes
      AND a.id_jugador IN ($inHasta)
      AND a.tipo_aporte IS NULL
")->fetch_assoc()['total'] ?? 0);

// =====================================================
// MES ACTUAL
// =====================================================
$mesActual = calcular_totales_mes($conexion, $anio, $mes, $TOPE);

// =====================================================
// AÑO (coherente): parcial_anio = suma de parciales; estimado_anio = parcial_anio + saldo_actual; final_anio = estimado_anio - gastos_anio
// =====================================================
$year_total = 0;
$esporadicos_anio = 0;
$otros_anio = 0;
$gastos_anio = 0;

// ✅ parcial_anio = suma(parcial_mes) => month_total + esporadicos + otros
$parcial_anio = 0;

for ($m = 1; $m <= $mes; $m++) {
    $tmp = calcular_totales_mes($conexion, $anio, $m, $TOPE);

    $year_total       += (int)$tmp["month_total"];
    $esporadicos_anio += (int)$tmp["esporadicos_mes"];
    $otros_anio       += (int)$tmp["otros_mes"];
    $gastos_anio      += (int)$tmp["gastos_mes"];

    $parcial_anio     += (int)$tmp["parcial_mes"];
}

// saldo_total del año = foto a corte del mes seleccionado (NO sumarlo 12 veces)
$saldo_total = (int)$mesActual["saldo_total"];

// ✅ NUEVO: estimado_anio coherente
$estimado_anio = (int)($parcial_anio + $saldo_total);
$final_anio    = (int)($estimado_anio - $gastos_anio);

// variables mes actual
$month_total     = (int)$mesActual["month_total"];
$esporadicos_mes = (int)$mesActual["esporadicos_mes"];
$otros_mes       = (int)$mesActual["otros_mes"];
$gastos_mes      = (int)$mesActual["gastos_mes"];
$parcial_mes     = (int)$mesActual["parcial_mes"];
$estimado_mes    = (int)$mesActual["estimado_mes"];
$final_mes       = (int)$mesActual["final_mes"];

// =====================================================
// RESPUESTA
// =====================================================
echo json_encode([
    "ok" => true,

    "month_total" => $month_total,
    "year_total"  => $year_total,

    "esporadicos_mes"  => $esporadicos_mes,
    "esporadicos_anio" => $esporadicos_anio,

    // ✅ parciales ahora incluyen otros aportes
    "parcial_mes"  => $parcial_mes,
    "parcial_anio" => $parcial_anio,

    "otros_mes"    => $otros_mes,
    "otros_anio"   => $otros_anio,

    "saldo_total"  => $saldo_total,

    "eliminados_mes_total"  => $eliminados_mes_total,
    "eliminados_anio_total" => $eliminados_anio_total,

    // ✅ estimados nuevos coherentes
    "estimado_mes" => $estimado_mes,
    "estimado_anio"=> $estimado_anio,

    "gastos_mes"   => $gastos_mes,
    "gastos_anio"  => $gastos_anio,

    "final_mes"    => $final_mes,
    "final_anio"   => $final_anio,

    "debug" => [
        "fecha_inicio" => $fechaInicio,
        "fecha_corte"  => $fechaCorte
    ]
], JSON_UNESCAPED_UNICODE);


