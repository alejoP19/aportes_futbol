<?php
// backend/public_data/listado_publico.php
include "../../conexion.php";
header("Content-Type: application/json; charset=utf-8");

$mes  = isset($_GET['mes'])  ? intval($_GET['mes'])  : intval(date("n"));
$anio = isset($_GET['anio']) ? intval($_GET['anio']) : intval(date("Y"));

// Último día del mes
$lastDay = date("Y-m-t", strtotime(sprintf("%04d-%02d-01", $anio, $mes)));

// 1) Días válidos: miércoles (3) y sábado (6)
$dias_validos = [];
$daysInMonth = cal_days_in_month(CAL_GREGORIAN, $mes, $anio);
for ($d = 1; $d <= $daysInMonth; $d++) {
    $fecha = sprintf("%04d-%02d-%02d", $anio, $mes, $d);
    $dow = date("N", strtotime($fecha)); // 1=lunes ... 7=domingo
    if ($dow == 3 || $dow == 6) {
        $dias_validos[] = $d;
    }
}

// Fecha especial (fija, ejemplo: 28)
$fecha_especial = 28;
if (!in_array($fecha_especial, $dias_validos)) {
    // no duplicar
}

// 2) Jugadores (activos + eliminados mostrar sombreado luego)
$players = [];
$stmt = $conexion->prepare("
    SELECT j.id, j.nombre, j.activo, de.id AS eliminado
    FROM jugadores j
    LEFT JOIN jugadores_eliminados de ON de.id = j.id
    ORDER BY j.nombre ASC
");
$stmt->execute();
$res = $stmt->get_result();
while ($r = $res->fetch_assoc()) {
    $players[] = $r;
}
$stmt->close();

// 3) Aportes principales del mes para esos jugadores
$playerIds = array_map(fn($p)=>intval($p['id']), $players);
$aportes_map = [];
if (count($playerIds) > 0) {
    $in = implode(",", $playerIds);
    $q = $conexion->query("
        SELECT id_jugador, fecha, aporte_principal
        FROM aportes
        WHERE id_jugador IN ($in)
          AND MONTH(fecha) = $mes
          AND YEAR(fecha) = $anio
    ");
    while ($row = $q->fetch_assoc()) {
        $pid = intval($row['id_jugador']);
        $fecha = $row['fecha']; // YYYY-MM-DD
        $aportes_map[$pid][$fecha] = intval($row['aporte_principal']);
    }
}

// 4) Otros aportes del mes
$otros_map = [];
if (count($playerIds) > 0) {
    $in = implode(",", $playerIds);
    $q2 = $conexion->query("
        SELECT id_jugador, tipo, valor
        FROM otros_aportes
        WHERE id_jugador IN ($in)
          AND mes = $mes
          AND anio = $anio
    ");
    while ($r2 = $q2->fetch_assoc()) {
        $pid = intval($r2['id_jugador']);
        $otros_map[$pid][] = [
            "tipo" => $r2['tipo'],
            "valor" => intval($r2['valor'])
        ];
    }
}

// 5) Construir rows para la tabla pública
$rows = [];
foreach ($players as $p) {
    $pid = intval($p['id']);
    $fila = [
        "id" => $pid,
        "nombre" => $p['nombre'],
        "activo" => intval($p['activo']),
        "eliminado" => $p['eliminado'] ? 1 : 0,
        "dias" => [],
        "especial" => 0,
        "otros" => $otros_map[$pid] ?? [],
        "total_mes" => 0
    ];

    $total_por_jugador = 0;

    foreach ($dias_validos as $d) {
        $fecha = sprintf("%04d-%02d-%02d", $anio, $mes, $d);
        $v = $aportes_map[$pid][$fecha] ?? 0;
        $fila['dias'][] = $v;
        $total_por_jugador += $v;
    }

    // Fecha especial
    $fechaEsp = sprintf("%04d-%02d-%02d", $anio, $mes, $fecha_especial);
    $vEsp = $aportes_map[$pid][$fechaEsp] ?? 0;
    $fila['especial'] = $vEsp;
    $total_por_jugador += $vEsp;

    // Otros aportes
    if (!empty($fila['otros'])) {
        foreach ($fila['otros'] as $o) {
            $total_por_jugador += intval($o['valor']);
        }
    }

    $fila['total_mes'] = $total_por_jugador;

    $rows[] = $fila;
}
// 6) Totales generales (today, month_total, year_total) incluyendo otros aportes
$total_otros_mes = array_sum(array_map(fn($r)=>array_sum(array_column($r['otros'],'valor')), $rows));
$month_total = array_sum(array_column($rows, 'total_mes'));
$qDay = $conexion->query("SELECT COALESCE(SUM(aporte_principal),0) AS t FROM aportes WHERE fecha=CURDATE()");
$today_total = intval($qDay->fetch_assoc()['t'] ?? 0);

// Total año incluyendo aportes principales + otros aportes
$year_total = 0;
$qYearAportes = $conexion->query("SELECT id_jugador, fecha, aporte_principal FROM aportes WHERE YEAR(fecha) = $anio");
$aportesAnio = [];
while($r = $qYearAportes->fetch_assoc()) {
    $pid = intval($r['id_jugador']);
    $aportesAnio[$pid][] = intval($r['aporte_principal']);
}
$qYearOtros = $conexion->query("SELECT id_jugador, valor FROM otros_aportes WHERE anio = $anio");
while($r = $qYearOtros->fetch_assoc()) {
    $pid = intval($r['id_jugador']);
    $aportesAnio[$pid][] = intval($r['valor']);
}
foreach($aportesAnio as $arr) {
    $year_total += array_sum($arr);
}

$totales = [
    "today" => $today_total,
    "month_total" => $month_total,
    "year_total" => $year_total,
    "otros_mes_total" => $total_otros_mes
];


// 7) Observaciones
$qObs = $conexion->prepare("SELECT texto FROM gastos_observaciones WHERE mes=? AND anio=? LIMIT 1");
$qObs->bind_param("ii", $mes, $anio);
$qObs->execute();
$resObs = $qObs->get_result()->fetch_assoc();
$observaciones = $resObs['texto'] ?? "";
$qObs->close();

// 8) Respuesta JSON
$response = [
    "mes" => $mes,
    "anio" => $anio,
    "dias_validos" => $dias_validos,
    "fecha_especial" => $fecha_especial,
    "jugadores" => array_map(fn($p)=>["id"=>$p['id'],"nombre"=>$p['nombre'],"activo"=>$p['activo'],"eliminado"=>$p['eliminado'] ? 1 : 0], $players),
    "rows" => $rows,
    "totales" => $totales,
    "observaciones" => $observaciones
];

echo json_encode($response, JSON_UNESCAPED_UNICODE|JSON_NUMERIC_CHECK);
exit;
