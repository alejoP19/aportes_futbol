<?php
// backend/public_data/listado_publico.php
include "../../conexion.php";
header("Content-Type: application/json; charset=utf-8");

$mes  = isset($_GET['mes'])  ? intval($_GET['mes'])  : intval(date("n"));
$anio = isset($_GET['anio']) ? intval($_GET['anio']) : intval(date("Y"));

// -------------------------------------------
// 1) Días válidos (miércoles=3, sábado=6)
// -------------------------------------------
$dias_validos = [];
$daysInMonth  = cal_days_in_month(CAL_GREGORIAN, $mes, $anio);

for ($d = 1; $d <= $daysInMonth; $d++) {
    $fecha = sprintf("%04d-%02d-%02d", $anio, $mes, $d);
    $dow   = date("N", strtotime($fecha)); // 1=lunes ... 7=domingo
    if ($dow == 3 || $dow == 6) {
        $dias_validos[] = $d;
    }
}

// Fecha especial fija
$fecha_especial = 28;

// -------------------------------------------
// 2) Jugadores + SALDO calculado (acumulado)
// -------------------------------------------
$players = [];

$sqlPlayers = "
    SELECT 
        j.id,
        j.nombre,
        j.activo,
        de.id AS eliminado,
        COALESCE(SUM(a.aporte_principal), 0) AS total_aportes,
        COALESCE(COUNT(a.id), 0) AS total_juegos
    FROM jugadores j
    LEFT JOIN jugadores_eliminados de 
        ON de.id = j.id
    LEFT JOIN aportes a 
        ON a.id_jugador = j.id
       AND (
            YEAR(a.fecha) <  ?
         OR (YEAR(a.fecha) = ? AND MONTH(a.fecha) <= ?)
       )
    GROUP BY j.id
    ORDER BY j.nombre ASC
";

$stmt = $conexion->prepare($sqlPlayers);
$stmt->bind_param("iii", $anio, $anio, $mes);
$stmt->execute();
$res = $stmt->get_result();
while ($r = $res->fetch_assoc()) {
    $total_aportes = intval($r['total_aportes']);
    $total_juegos  = intval($r['total_juegos']);
    $saldo_calc    = $total_aportes - ($total_juegos * 2000);
    if ($saldo_calc < 0) $saldo_calc = 0;

    $r['saldo'] = $saldo_calc;
    $players[]  = $r;
}
$stmt->close();

$playerIds = array_map(fn($p) => intval($p['id']), $players);

// -------------------------------------------
// 3) Aportes del mes seleccionado (por día)
// -------------------------------------------
$aportes_map = [];
if (!empty($playerIds)) {
    $in = implode(",", $playerIds);
    $qAportes = $conexion->query("
        SELECT id_jugador, fecha, aporte_principal
        FROM aportes
        WHERE id_jugador IN ($in)
          AND MONTH(fecha) = $mes
          AND YEAR(fecha)  = $anio
    ");
    while ($row = $qAportes->fetch_assoc()) {
        $pid   = intval($row['id_jugador']);
        $fecha = $row['fecha'];
        $aportes_map[$pid][$fecha] = intval($row['aporte_principal']);
    }
}

// -------------------------------------------
// 4) Otros aportes del mes
// -------------------------------------------
$otros_map = [];
if (!empty($playerIds)) {
    $in = implode(",", $playerIds);
    $qOtros = $conexion->query("
        SELECT id_jugador, tipo, valor
        FROM otros_aportes
        WHERE id_jugador IN ($in)
          AND mes = $mes
          AND anio = $anio
    ");
    while ($r2 = $qOtros->fetch_assoc()) {
        $pid = intval($r2['id_jugador']);
        $otros_map[$pid][] = [
            "tipo"  => $r2['tipo'],
            "valor" => intval($r2['valor'])
        ];
    }
}
// -------------------------------------------
// (helper) saldo hasta el mes consultado (CORRECTO)
// -------------------------------------------
function get_saldo_hasta_mes($conexion, $id_jugador, $mes, $anio) {

    $fechaCorte = date('Y-m-t', strtotime("$anio-$mes-01"));

    // 1️⃣ Excedentes generados hasta la fecha
    $q1 = $conexion->prepare("
        SELECT IFNULL(SUM(aporte_principal - 2000),0) AS excedente
        FROM aportes
        WHERE id_jugador = ?
          AND aporte_principal > 2000
          AND fecha <= ?
    ");
    $q1->bind_param("is", $id_jugador, $fechaCorte);
    $q1->execute();
    $excedente = (int)$q1->get_result()->fetch_assoc()['excedente'];
    $q1->close();

    // 2️⃣ Consumo de saldo hasta la fecha
    $q2 = $conexion->prepare("
        SELECT IFNULL(SUM(amount),0) AS consumido
        FROM aportes_saldo_moves
        WHERE id_jugador = ?
          AND fecha_consumo <= ?
    ");
    $q2->bind_param("is", $id_jugador, $fechaCorte);
    $q2->execute();
    $consumido = (int)$q2->get_result()->fetch_assoc()['consumido'];
    $q2->close();

    return max(0, $excedente - $consumido);
}



// -------------------------------------------
// 2.1) Cargar deudas reales por jugador y día
// -------------------------------------------
// =====================================================
// CARGAR TODAS LAS DEUDAS PARA MOSTRAR EN TABLA PUBLICA
// =====================================================
// =====================================================
// DEUDAS EN TODA LA BD (para totales acumulados)
// =====================================================
$deudas_all = [];

$qDeu = $conexion->query("
    SELECT id_jugador, fecha
    FROM deudas_aportes
");

while ($d = $qDeu->fetch_assoc()) {
    $pid = intval($d['id_jugador']);
    $dia = intval(date("j", strtotime($d['fecha'])));
    $mesDeuda = intval(date("n", strtotime($d['fecha'])));
    $anioDeuda = intval(date("Y", strtotime($d['fecha'])));

    $deudas_all[$pid][] = [
        "dia"  => $dia,
        "mes"  => $mesDeuda,
        "anio" => $anioDeuda
    ];
}
// =====================================================
// DEUDAS DEL MES ACTUAL (solo para mostrar X en tabla)
// =====================================================
$deudas_mes = [];

foreach ($deudas_all as $pid => $lista) {
    foreach ($lista as $d) {
        if ($d["mes"] == $mes && $d["anio"] == $anio) {
            $deudas_mes[$pid][$d["dia"]] = true;
        }
    }
}
// =====================================================
// TOTAL DE DEUDAS ACUMULADAS HASTA ESTE MES
// =====================================================
$deudas_acum_cnt = [];

foreach ($deudas_all as $pid => $lista) {
    $count = 0;
    foreach ($lista as $d) {
        if ($d["anio"] < $anio || ($d["anio"] == $anio && $d["mes"] <= $mes)) {
            $count++;
        }
    }
    $deudas_acum_cnt[$pid] = $count;
}

// -------------------------------------------
// 5) Construir filas (rows) para la tabla pública
// -------------------------------------------
$rows = [];

foreach ($players as $p) {

    $pid = intval($p['id']);

    $fila = [
        "id"            => $pid,
        "nombre"        => $p['nombre'],
        "activo"        => intval($p['activo']),
        "eliminado"     => $p['eliminado'] ? 1 : 0,
        "dias"          => [],
        "real_dias"     => [],
        "especial"      => 0,
        "real_especial" => 0,
        "otros"         => $otros_map[$pid] ?? [],
        "saldo"         => 0,
        "total_mes"     => 0,
        "deudas"        => $deudas_mes[$pid] ?? [],      // SOLO del mes actual (para mostrar X)
        "total_deudas"  => $deudas_acum_cnt[$pid] ?? 0   // TOTAL acumulado hasta este mes
    ];

    $total_jugador = 0;
    $total_otros   = 0;

    // -------- DÍAS DEL MES --------
    foreach ($dias_validos as $d) {
        $f     = sprintf("%04d-%02d-%02d", $anio, $mes, $d);
        $real  = $aportes_map[$pid][$f] ?? 0;
        $vis   = min($real, 2000);

        $fila['dias'][]      = $vis;
        $fila['real_dias'][] = $real;

        $total_jugador += $vis;
    }

    // -------- FECHA ESPECIAL --------
    $fEsp    = sprintf("%04d-%02d-%02d", $anio, $mes, $fecha_especial);
    $realEsp = $aportes_map[$pid][$fEsp] ?? 0;
    $visEsp  = min($realEsp, 2000);

    $fila['especial']      = $visEsp;
    $fila['real_especial'] = $realEsp;
    $total_jugador        += $visEsp;

    // -------- OTROS APORTES --------
    if (!empty($fila['otros'])) {
        foreach ($fila['otros'] as $o) {
            $val = intval($o['valor']);
            $total_otros += $val;
        }
    }

    $fila['total_mes'] = $total_jugador + $total_otros;
  $fila['saldo'] = get_saldo_hasta_mes($conexion, $pid, $mes, $anio);


    $rows[] = $fila;
}

// -------------------------------------------
// 6) Totales generales + gastos
// -------------------------------------------

$today = $conexion->query("
    SELECT IFNULL(SUM(aporte_principal),0) AS s 
    FROM aportes 
    WHERE fecha = CURDATE()
")->fetch_assoc()['s'];

$month_total = $conexion->query("
    SELECT IFNULL(SUM(aporte_principal),0) AS s 
    FROM aportes 
    WHERE MONTH(fecha) = $mes
      AND YEAR(fecha)  = $anio
")->fetch_assoc()['s'];

$year_total = $conexion->query("
    SELECT IFNULL(SUM(aporte_principal),0) AS s 
    FROM aportes 
    WHERE YEAR(fecha)  = $anio
      AND MONTH(fecha) <= $mes
")->fetch_assoc()['s'];

$otros_mes = $conexion->query("
    SELECT IFNULL(SUM(valor),0) AS s 
    FROM otros_aportes 
    WHERE mes  = $mes 
      AND anio = $anio
")->fetch_assoc()['s'];

$otros_year = $conexion->query("
    SELECT IFNULL(SUM(valor),0) AS s 
    FROM otros_aportes 
    WHERE anio = $anio
      AND mes <= $mes
")->fetch_assoc()['s'];

$gastos_mes = $conexion->query("
    SELECT IFNULL(SUM(valor),0) s 
    FROM gastos 
    WHERE mes = $mes AND anio = $anio
")->fetch_assoc()['s'];

$gastos_anio = $conexion->query("
    SELECT IFNULL(SUM(valor),0) s 
    FROM gastos 
    WHERE anio = $anio AND mes <= $mes
")->fetch_assoc()['s'];

$month_total_final = $month_total + $otros_mes - $gastos_mes;
$year_total_final  = $year_total + $otros_year - $gastos_anio;

$totales = [
    "today"           => intval($today),
    "month_total"     => intval($month_total_final),
    "year_total"      => intval($year_total_final),
    "otros_mes_total" => intval($otros_mes),
    "gastos_mes"      => intval($gastos_mes),
    "gastos_anio"     => intval($gastos_anio)
];

// detalle de gastos
$gastos_detalle = [];
$qDet = $conexion->prepare("
    SELECT nombre, valor 
    FROM gastos 
    WHERE mes = ? AND anio = ?
    ORDER BY id ASC
");
$qDet->bind_param("ii", $mes, $anio);
$qDet->execute();
$resDet = $qDet->get_result();
while ($g = $resDet->fetch_assoc()) {
    $gastos_detalle[] = [
        "nombre" => $g["nombre"],
        "valor"  => intval($g["valor"])
    ];
}
$qDet->close();

// observaciones
$qObs = $conexion->prepare("
    SELECT texto 
    FROM gastos_observaciones 
    WHERE mes = ? AND anio = ? 
    LIMIT 1
");
$qObs->bind_param("ii", $mes, $anio);
$qObs->execute();
$resObs        = $qObs->get_result()->fetch_assoc();
$observaciones = $resObs['texto'] ?? "";
$qObs->close();

echo json_encode([
    "mes"            => $mes,
    "anio"           => $anio,
    "dias_validos"   => $dias_validos,
    "fecha_especial" => $fecha_especial,
    "jugadores"      => $players,
    "rows"           => $rows,
    "totales"        => $totales,
    "observaciones"  => $observaciones,
    "gastos_detalle" => $gastos_detalle
], JSON_UNESCAPED_UNICODE | JSON_NUMERIC_CHECK);

exit;
