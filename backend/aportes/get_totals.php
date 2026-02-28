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
 * Calcula los totales del MES (según tu lógica actual):
 * - month_total: registrados efectivo (cap 3000 + consumido_target, cap final 3000)
 * - esporadicos_mes: suma completa
 * - otros_mes: otros_aportes + esporadico_otro
 * - gastos_mes: gastos del mes
 * - saldo_total: saldo acumulado a corte del mes (incluye eliminados)
 * - parcial_mes = month_total + esporadicos_mes  (incluye eliminados)
 * - estimado_mes = parcial_mes + otros_mes + saldo_total
 * - final_mes = estimado_mes - gastos_mes
 */
function calcular_totales_mes($conexion, $anio, $mes, $TOPE = 3000) {
    $fechaCorte  = date('Y-m-t', strtotime("$anio-$mes-01"));

    // 1) REGISTRADOS EFECTIVO (incluye eliminados porque vienen en aportes)
    $month_total = (int)($conexion->query("
        SELECT IFNULL(SUM(
            LEAST(
                LEAST(IFNULL(a.aporte_principal,0), $TOPE) + IFNULL(t.consumido,0),
            $TOPE)
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

    // 2) ESPORÁDICOS (suma completa)
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

    // 5) SALDO TOTAL A CORTE DEL MES (incluye eliminados)
    $saldo_total = (int)($conexion->query("
        SELECT IFNULL(SUM(
            GREATEST(IFNULL(ex.excedente,0) - IFNULL(co.consumido,0), 0)
        ),0) AS saldo
        FROM jugadores j
        LEFT JOIN (
            SELECT id_jugador, SUM(GREATEST(aporte_principal - $TOPE, 0)) AS excedente
            FROM aportes
            WHERE fecha <= '$fechaCorte'
              AND id_jugador IS NOT NULL
              AND tipo_aporte IS NULL
            GROUP BY id_jugador
        ) ex ON ex.id_jugador = j.id
        LEFT JOIN (
            SELECT id_jugador, SUM(amount) AS consumido
            FROM aportes_saldo_moves
            WHERE fecha_consumo <= '$fechaCorte'
            GROUP BY id_jugador
        ) co ON co.id_jugador = j.id
    ")->fetch_assoc()['saldo'] ?? 0);

    // Totales del mes según tu definición
    $parcial_mes  = (int)($month_total + $esporadicos_mes);
    $estimado_mes = (int)($parcial_mes + $otros_mes + $saldo_total);
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
// ELIMINADOS (SOLO INFORMATIVO) - ids eliminados en mes / hasta fechaCorte
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

// total aportes efectivos de eliminados (MES)
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

// total aportes efectivos de eliminados (AÑO hasta mes)
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
// CALCULAR MES ACTUAL
// =====================================================
$mesActual = calcular_totales_mes($conexion, $anio, $mes, $TOPE);

// =====================================================
// CALCULAR AÑO COMO SUMA DE MESES (1..mes)  ✅ TU VALIDACIÓN
// =====================================================
$year_total = 0;          // registrados efectivo acumulado
$esporadicos_anio = 0;
$otros_anio = 0;
$gastos_anio = 0;
$estimado_anio = 0;
$final_anio = 0;

// saldo_total del año: informativo = saldo a corte del mes seleccionado
// (NO se suma 12 veces porque es una "foto", pero tú sí lo usas en el estimado_mes mensual)
$saldo_total = (int)$mesActual["saldo_total"];

for ($m = 1; $m <= $mes; $m++) {
    $tmp = calcular_totales_mes($conexion, $anio, $m, $TOPE);

    $year_total       += (int)$tmp["month_total"];
    $esporadicos_anio += (int)$tmp["esporadicos_mes"];
    $otros_anio       += (int)$tmp["otros_mes"];
    $gastos_anio      += (int)$tmp["gastos_mes"];

    $estimado_anio    += (int)$tmp["estimado_mes"];
    $final_anio       += (int)$tmp["final_mes"];
}

// parcial_anio = suma de parciales mes a mes
$parcial_anio = (int)($year_total + $esporadicos_anio);

// variables del mes actual
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

    // base registrados efectivo
    "month_total" => $month_total,
    "year_total"  => $year_total,

    // esporadicos
    "esporadicos_mes"  => $esporadicos_mes,
    "esporadicos_anio" => $esporadicos_anio,

    // parciales
    "parcial_mes"  => $parcial_mes,
    "parcial_anio" => $parcial_anio,

    // otros aportes
    "otros_mes"    => $otros_mes,
    "otros_anio"   => $otros_anio,

    // saldo (informativo del corte del mes seleccionado)
    "saldo_total"  => $saldo_total,

    // eliminados informativo
    "eliminados_mes_total"  => $eliminados_mes_total,
    "eliminados_anio_total" => $eliminados_anio_total,

    // estimados y finales (AÑO = SUMA DE MESES)
    "estimado_mes" => $estimado_mes,
    "estimado_anio"=> $estimado_anio,

    "gastos_mes"   => $gastos_mes,
    "gastos_anio"  => $gastos_anio,

    "final_mes"    => $final_mes,
    "final_anio"   => $final_anio,

    // debug útil
    "debug" => [
        "fecha_inicio" => $fechaInicio,
        "fecha_corte"  => $fechaCorte
    ]
], JSON_UNESCAPED_UNICODE);