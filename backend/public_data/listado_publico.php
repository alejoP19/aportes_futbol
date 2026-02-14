<?php
// backend/public_data/listado_publico.php
include "../../conexion.php";
header("Content-Type: application/json; charset=utf-8");

$mes  = isset($_GET['mes'])  ? intval($_GET['mes'])  : intval(date("n"));
$anio = isset($_GET['anio']) ? intval($_GET['anio']) : intval(date("Y"));

$TOPE = 3000;

// -------------------------------------------
// 1) Días válidos (miércoles=3, sábado=6)
// -------------------------------------------
$dias_validos = [];
$daysInMonth  = cal_days_in_month(CAL_GREGORIAN, $mes, $anio);

for ($d = 1; $d <= $daysInMonth; $d++) {
    $fecha = sprintf("%04d-%02d-%02d", $anio, $mes, $d);
    $dow   = date("N", strtotime($fecha)); // 1=lunes ... 7=domingo
    if ($dow == 3 || $dow == 6) $dias_validos[] = $d;
}

// -------------------------------------------
// 1.1) "Otro juego" igual admin (no miércoles/sábado)
// -------------------------------------------
function pick_default_otro_dia($days, $days_count) {
    if (!in_array(28, $days) && 28 <= $days_count) return 28;
    for ($d = 1; $d <= $days_count; $d++) {
        if (!in_array($d, $days)) return $d;
    }
    return 1;
}

// Permitir ?otro=DD
$otroDia = isset($_GET['otro']) ? intval($_GET['otro']) : pick_default_otro_dia($dias_validos, $daysInMonth);
if ($otroDia < 1 || $otroDia > $daysInMonth) $otroDia = pick_default_otro_dia($dias_validos, $daysInMonth);
if (in_array($otroDia, $dias_validos)) $otroDia = pick_default_otro_dia($dias_validos, $daysInMonth);

// -------------------------------------------
// helper saldo hasta mes (TOPE 3000 + moves)
// -------------------------------------------
function get_saldo_hasta_mes($conexion, $id_jugador, $mes, $anio, $TOPE = 3000) {
    $fechaCorte = date('Y-m-t', strtotime("$anio-$mes-01"));

    // excedente generado
    $q1 = $conexion->prepare("
        SELECT IFNULL(SUM(GREATEST(aporte_principal - ?, 0)),0) AS excedente
        FROM aportes
        WHERE id_jugador = ?
          AND fecha <= ?
    ");
    $q1->bind_param("iis", $TOPE, $id_jugador, $fechaCorte);
    $q1->execute();
    $excedente = (int)($q1->get_result()->fetch_assoc()['excedente'] ?? 0);
    $q1->close();

    // consumo
    $q2 = $conexion->prepare("
        SELECT IFNULL(SUM(amount),0) AS consumido
        FROM aportes_saldo_moves
        WHERE id_jugador = ?
          AND fecha_consumo <= ?
    ");
    $q2->bind_param("is", $id_jugador, $fechaCorte);
    $q2->execute();
    $consumido = (int)($q2->get_result()->fetch_assoc()['consumido'] ?? 0);
    $q2->close();

    return max(0, $excedente - $consumido);
}
function get_saldo_anio_hasta_mes($conexion, $id_jugador, $mes, $anio, $TOPE = 3000) {
    $fechaCorte = date('Y-m-t', strtotime("$anio-$mes-01"));

    // excedente generado SOLO en el año
    $q1 = $conexion->prepare("
        SELECT IFNULL(SUM(GREATEST(aporte_principal - ?, 0)),0) AS excedente
        FROM aportes
        WHERE id_jugador = ?
          AND YEAR(fecha) = ?
          AND fecha <= ?
    ");
    $q1->bind_param("iiis", $TOPE, $id_jugador, $anio, $fechaCorte);
    $q1->execute();
    $excedente = (int)($q1->get_result()->fetch_assoc()['excedente'] ?? 0);
    $q1->close();

    // consumo SOLO en el año (por fecha_consumo)
    $q2 = $conexion->prepare("
        SELECT IFNULL(SUM(amount),0) AS consumido
        FROM aportes_saldo_moves
        WHERE id_jugador = ?
          AND YEAR(fecha_consumo) = ?
          AND fecha_consumo <= ?
    ");
    $q2->bind_param("iis", $id_jugador, $anio, $fechaCorte);
    $q2->execute();
    $consumido = (int)($q2->get_result()->fetch_assoc()['consumido'] ?? 0);
    $q2->close();

    return max(0, $excedente - $consumido);
}


// -------------------------------------------
// 2) Jugadores
// -------------------------------------------
$players = [];
$sqlPlayers = "
    SELECT id, nombre, activo
    FROM jugadores
    ORDER BY nombre ASC
";
$res = $conexion->query($sqlPlayers);
while ($r = $res->fetch_assoc()) $players[] = $r;

$playerIds = array_map(fn($p) => intval($p['id']), $players);

// -------------------------------------------
// 3) Aportes del mes (real)
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
        $pid   = (int)$row['id_jugador'];
        $fecha = $row['fecha'];
        $aportes_map[$pid][$fecha] = (int)$row['aporte_principal'];
    }
}

// -------------------------------------------
// 3.1) Consumo por TARGET del mes (para mostrar ✚ y sumar efectivo)
// -------------------------------------------
$consumo_map = []; // [$pid][$fecha] = consumido
if (!empty($playerIds)) {
    $in = implode(",", $playerIds);
    $qCons = $conexion->query("
        SELECT a.id_jugador, a.fecha, IFNULL(SUM(m.amount),0) AS consumido
        FROM aportes a
        INNER JOIN aportes_saldo_moves m ON m.target_aporte_id = a.id
        WHERE a.id_jugador IN ($in)
          AND MONTH(a.fecha) = $mes
          AND YEAR(a.fecha) = $anio
        GROUP BY a.id_jugador, a.fecha
    ");
    while ($r = $qCons->fetch_assoc()) {
        $pid = (int)$r['id_jugador'];
        $f   = $r['fecha'];
        $consumo_map[$pid][$f] = (int)$r['consumido'];
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
        $pid = (int)$r2['id_jugador'];
        $otros_map[$pid][] = [
            "tipo"  => $r2['tipo'],
            "valor" => (int)$r2['valor']
        ];
    }
}

// =====================================================
// DEUDAS (X del mes + lista acumulada hasta mes)
// =====================================================
$deudas_mes = [];
$deudas_lista = []; // [$pid] = ["dd-mm-YYYY", ...] hasta mes seleccionado

if (!empty($playerIds)) {
    $in = implode(",", $playerIds);

    // deudas del mes para X
    $qMes = $conexion->query("
        SELECT id_jugador, fecha
        FROM deudas_aportes
        WHERE id_jugador IN ($in)
          AND YEAR(fecha) = $anio
          AND MONTH(fecha) = $mes
    ");
    while ($d = $qMes->fetch_assoc()) {
        $pid = (int)$d['id_jugador'];
        $dia = (int)date("j", strtotime($d['fecha']));
        $deudas_mes[$pid][$dia] = true;
    }

    // lista acumulada hasta mes (para “Debe: N” + fechas)
    $qList = $conexion->query("
        SELECT id_jugador, fecha
        FROM deudas_aportes
        WHERE id_jugador IN ($in)
          AND (
              YEAR(fecha) < $anio
           OR (YEAR(fecha) = $anio AND MONTH(fecha) <= $mes)
          )
        ORDER BY fecha ASC
    ");
    while ($d = $qList->fetch_assoc()) {
        $pid = (int)$d['id_jugador'];
        $deudas_lista[$pid][] = date("d-m-Y", strtotime($d['fecha']));
    }
}

// -------------------------------------------
// 5) Construir rows
// -------------------------------------------
$rows = [];
foreach ($players as $p) {
    $pid = (int)$p['id'];

    $fila = [
        "id"              => $pid,
        "nombre"          => $p['nombre'],
        "activo"          => (int)$p['activo'],
        "dias"            => [],   // cashCap por día (lo que se ve en input)
        "real_dias"       => [],   // real para tooltip ⭐
        "consumo_dias"    => [],   // consumo para tooltip ✚
        "especial"        => 0,    // cashCap del otro juego
        "real_especial"   => 0,
        "consumo_especial"=> 0,
        "otros"           => $otros_map[$pid] ?? [],
        "saldo"           => 0,
        "total_mes"       => 0,    // total efectivo (cap+consumo) + otros
        "deudas"          => $deudas_mes[$pid] ?? [],
        "deudas_lista"    => $deudas_lista[$pid] ?? [],
        "total_deudas"    => isset($deudas_lista[$pid]) ? count($deudas_lista[$pid]) : 0,
        "otro_dia"        => $otroDia,
    ];

    $total_efectivo = 0;
    $total_otros    = 0;

    // Días normales
    foreach ($dias_validos as $d) {
        $f = sprintf("%04d-%02d-%02d", $anio, $mes, $d);
        $real    = (int)($aportes_map[$pid][$f] ?? 0);
        $cashCap = min($real, $TOPE);
        $consumo = (int)($consumo_map[$pid][$f] ?? 0);
        $efectivo = min($cashCap + $consumo, $TOPE);

        $fila['dias'][]         = $cashCap;
        $fila['real_dias'][]    = $real;
        $fila['consumo_dias'][] = $consumo;

        $total_efectivo += $efectivo;
    }

    // Otro juego (especial)
    $fEsp = sprintf("%04d-%02d-%02d", $anio, $mes, $otroDia);
    $realEsp    = (int)($aportes_map[$pid][$fEsp] ?? 0);
    $cashCapEsp = min($realEsp, $TOPE);
    $consEsp    = (int)($consumo_map[$pid][$fEsp] ?? 0);
    $efecEsp    = min($cashCapEsp + $consEsp, $TOPE);

    $fila['especial']         = $cashCapEsp;
    $fila['real_especial']    = $realEsp;
    $fila['consumo_especial'] = $consEsp;

    $total_efectivo += $efecEsp;

    // Otros
    if (!empty($fila['otros'])) {
        foreach ($fila['otros'] as $o) $total_otros += (int)$o['valor'];
    }

    $fila['total_mes'] = $total_efectivo + $total_otros;
    $fila['saldo']     = get_saldo_hasta_mes($conexion, $pid, $mes, $anio, $TOPE);

    $rows[] = $fila;
}

// saldo total mes
$saldo_total_mes = 0;
foreach ($rows as $r) $saldo_total_mes += (int)($r['saldo'] ?? 0);

$saldo_total_anio = 0;
foreach ($rows as $r) {
    $saldo_total_anio += (int)get_saldo_anio_hasta_mes($conexion, (int)$r["id"], $mes, $anio, $TOPE);
}


// -------------------------------------------
// 6) Totales generales (igual admin: cap + consumo)
// -------------------------------------------
$month_total = $conexion->query("
    SELECT IFNULL(SUM(
        LEAST(a.aporte_principal + IFNULL(t.consumido,0), $TOPE)
    ),0) AS s
    FROM aportes a
    LEFT JOIN (
        SELECT target_aporte_id, SUM(amount) AS consumido
        FROM aportes_saldo_moves
        GROUP BY target_aporte_id
    ) t ON t.target_aporte_id = a.id
    WHERE YEAR(a.fecha) = $anio
      AND MONTH(a.fecha) = $mes
")->fetch_assoc()['s'] ?? 0;

$year_total = $conexion->query("
    SELECT IFNULL(SUM(
        LEAST(a.aporte_principal + IFNULL(t.consumido,0), $TOPE)
    ),0) AS s
    FROM aportes a
    LEFT JOIN (
        SELECT target_aporte_id, SUM(amount) AS consumido
        FROM aportes_saldo_moves
        GROUP BY target_aporte_id
    ) t ON t.target_aporte_id = a.id
    WHERE YEAR(a.fecha) = $anio
      AND MONTH(a.fecha) <= $mes
")->fetch_assoc()['s'] ?? 0;

$otros_mes = $conexion->query("
    SELECT IFNULL(SUM(valor),0) AS s
    FROM otros_aportes
    WHERE mes=$mes AND anio=$anio
")->fetch_assoc()['s'] ?? 0;

$otros_year = $conexion->query("
    SELECT IFNULL(SUM(valor),0) AS s
    FROM otros_aportes
    WHERE anio=$anio AND mes <= $mes
")->fetch_assoc()['s'] ?? 0;

$gastos_mes = $conexion->query("
    SELECT IFNULL(SUM(valor),0) s
    FROM gastos
    WHERE mes = $mes AND anio = $anio
")->fetch_assoc()['s'] ?? 0;

$gastos_anio = $conexion->query("
    SELECT IFNULL(SUM(valor),0) s
    FROM gastos
    WHERE anio = $anio AND mes <= $mes
")->fetch_assoc()['s'] ?? 0;

$month_total_final = (int)$month_total + (int)$otros_mes - (int)$gastos_mes;
$year_total_final  = (int)$year_total  + (int)$otros_year - (int)$gastos_anio;

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
    $gastos_detalle[] = ["nombre"=>$g["nombre"], "valor"=>(int)$g["valor"]];
}
$qDet->close();


// detalle de otros aportes del mes (lista cada registro)
$otros_detalle = [];
$qOtrosDet = $conexion->prepare("
    SELECT tipo, valor
    FROM otros_aportes
    WHERE mes = ? AND anio = ?
    ORDER BY id ASC
");
$qOtrosDet->bind_param("ii", $mes, $anio);
$qOtrosDet->execute();
$resOD = $qOtrosDet->get_result();
while ($o = $resOD->fetch_assoc()) {
    $otros_detalle[] = [
        "tipo"  => $o["tipo"],
        "valor" => (int)$o["valor"],
    ];
}
$qOtrosDet->close();


// // detalle de otros aportes (agrupado por tipo)
// $otros_detalle = [];
// $qOtrosDet = $conexion->prepare("
//     SELECT tipo, valor
//     FROM otros_aportes
//     WHERE mes = ? AND anio = ?
//     ORDER BY id ASC
// ");
// $qOtrosDet->bind_param("ii", $mes, $anio);
// $qOtrosDet->execute();
// $resOtrosDet = $qOtrosDet->get_result();
// while ($o = $resOtrosDet->fetch_assoc()) {
//     $otros_detalle[] = 
//     [
//         "tipo" => $o["tipo"], 
//         "valor" => (int)$o["valor"]

//     ];
// }
// $qOtrosDet->close();



// detalle de otros aportes del mes (SIN agrupar, trae cada fila)
// $otros_detalle = [];
// $qOtrosDet = $conexion->prepare("
//     SELECT tipo, valor
//     FROM otros_aportes
//     WHERE mes = ? AND anio = ?
//     ORDER BY id ASC
// ");
// $qOtrosDet->bind_param("ii", $mes, $anio);
// $qOtrosDet->execute();
// $resOD = $qOtrosDet->get_result();
// while ($o = $resOD->fetch_assoc()) {
//     $otros_detalle[] = [
//         "tipo"  => $o["tipo"],
//         "valor" => (int)$o["valor"],
//     ];
// }
// $qOtrosDet->close();



// observaciones
$qObs = $conexion->prepare("
    SELECT texto
    FROM gastos_observaciones
    WHERE mes = ? AND anio = ?
    LIMIT 1
");
$qObs->bind_param("ii", $mes, $anio);
$qObs->execute();
$resObs = $qObs->get_result()->fetch_assoc();
$observaciones = $resObs['texto'] ?? "";
$qObs->close();


// =======================================
// OTROS PARTIDOS INFO (no miércoles/sábado)
// =======================================
$otros_dias = [];
for ($d = 1; $d <= $daysInMonth; $d++) {
    if (!in_array($d, $dias_validos, true)) $otros_dias[] = $d;
}

$otros_items = [];
$total_general = 0;

if (!empty($playerIds) && !empty($otros_dias)) {
    $in = implode(",", $playerIds);

    foreach ($otros_dias as $d) {
        $fecha = sprintf("%04d-%02d-%02d", $anio, $mes, $d);

        // suma efectivo del día = LEAST(aporte_principal + consumido_target, TOPE)
        $efectivo_total = $conexion->query("
            SELECT IFNULL(SUM(
                LEAST(a.aporte_principal + IFNULL(t.consumido,0), $TOPE)
            ),0) AS s
            FROM aportes a
            LEFT JOIN (
                SELECT target_aporte_id, SUM(amount) AS consumido
                FROM aportes_saldo_moves
                GROUP BY target_aporte_id
            ) t ON t.target_aporte_id = a.id
            WHERE a.fecha = '$fecha'
              AND a.id_jugador IN ($in)
        ")->fetch_assoc()['s'] ?? 0;

        $efectivo_total = (int)$efectivo_total;

        if ($efectivo_total > 0) {
            $otros_items[] = [
                "fecha" => $fecha,
                "fecha_label" => date("d-m-Y", strtotime($fecha)),
                "efectivo_total" => $efectivo_total
            ];
            $total_general += $efectivo_total;
        }
    }
}

$otros_partidos_info = [
    "cantidad" => count($otros_items),
    "total_general" => (int)$total_general,
    "items" => $otros_items
];


echo json_encode([
    "mes"           => $mes,
    "anio"          => $anio,
    "tope"          => $TOPE,

    "dias_validos"  => $dias_validos,
    "otro_dia"      => $otroDia,

    "rows"          => $rows,
    "observaciones" => $observaciones,
    "gastos_detalle"=> $gastos_detalle,
    "otros_detalle" => $otros_detalle,
       // ✅ AQUÍ EN RAÍZ:
    "otros_partidos_info" => $otros_partidos_info,

    "totales" => [
        "month_total"       => (int)$month_total_final,
        "year_total"        => (int)$year_total_final,
        "gastos_mes"        => (int)$gastos_mes,
        "gastos_anio"       => (int)$gastos_anio,
        "saldo_mes"         => (int)$saldo_total_mes,

        // totales extra
        "saldo_vigente_total" => (int)$saldo_total_mes,
        "saldo_total_anio"  => (int)$saldo_total_anio,

        // (si quieres conservarlo también aquí no pasa nada)
        "otros_mes_total"   => (int)$otros_mes,
    

        
    ]
]);
exit;
