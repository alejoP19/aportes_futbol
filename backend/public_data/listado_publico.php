<?php
// backend/public_data/listar_publico.php
include "../../conexion.php";
header("Content-Type: application/json; charset=utf-8");

$mes  = isset($_GET['mes'])  ? intval($_GET['mes'])  : intval(date("n"));
$anio = isset($_GET['anio']) ? intval($_GET['anio']) : intval(date("Y"));

// último día del mes (YYYY-MM-DD)
$lastDay = date("Y-m-t", strtotime(sprintf("%04d-%02d-01", $anio, $mes)));

// 1) Días válidos: solo miércoles (N=3) y sábado (N=6)
$dias_validos = [];
$daysInMonth = cal_days_in_month(CAL_GREGORIAN, $mes, $anio);
for ($d = 1; $d <= $daysInMonth; $d++) {
    $fecha = sprintf("%04d-%02d-%02d", $anio, $mes, $d);
    $dow = date("N", strtotime($fecha)); // 1(lun) .. 7(dom)
    if ($dow == 3 || $dow == 6) {
        $dias_validos[] = $d;
    }
}
// añadir "Fecha Especial" como columna fija (ej: día 28) — se maneja por separado al render
$fecha_especial = 28;
if (!in_array($fecha_especial, $dias_validos)) {
    // la fecha especial no se debe duplicar en el array de días
}

// 2) Jugadores a mostrar en la vista pública
// regla: excluir jugadores que hayan sido eliminados con fecha_eliminacion <= lastDay
// asumimos tabla jugadores_eliminados con campos: id_jugador, fecha_eliminacion
$players = [];
$stmt = $conexion->prepare("
    SELECT j.id, j.nombre
    FROM jugadores j
    LEFT JOIN jugadores_eliminados de ON de.id = j.id
    WHERE j.activo = 1
      AND de.id IS NULL
    ORDER BY j.nombre ASC
");


$stmt->execute();
$res = $stmt->get_result();
while ($r = $res->fetch_assoc()) {
    $players[] = $r;
}
$stmt->close();

// 3) Recuperar aportes del mes (miércoles/sábado + fecha especial) para esos jugadores
// Traemos todos los aportes para el mes para esos jugadores (map para rapidez)
$playerIds = array_map(function($p){ return intval($p['id']); }, $players);
$aportes_map = []; // [playerId][fecha] = aporte_principal
if (count($playerIds) > 0) {
    // preparar lista para IN (...)
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

// 4) Recuperar "otros_aportes" del mes para esos jugadores (tipo/valor)
$otros_map = []; // [playerId] = [ ['tipo'=>..., 'valor'=>...], ... ]
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

// 5) Construir la estructura de salida: jugadores[], dias_validos[], rows con aportes (solo días válidos), especial, otros y total_por_jugador
$rows = [];
foreach ($players as $p) {
    $pid = intval($p['id']);
    $nombre = $p['nombre'];
    $fila = [
        "id" => $pid,
        "nombre" => $nombre,
        "dias" => [], // aportes en dias_validos (preservando orden)
        "especial" => 0, // aporte en fecha especial (YYYY-MM-28)
        "otros" => $otros_map[$pid] ?? [],
        "total_mes" => 0
    ];

    $total_por_jugador = 0;

    // aportar por cada día válido
    foreach ($dias_validos as $d) {
        $fecha = sprintf("%04d-%02d-%02d", $anio, $mes, $d);
        $v = isset($aportes_map[$pid][$fecha]) ? intval($aportes_map[$pid][$fecha]) : 0;
        $fila['dias'][] = $v;
        $total_por_jugador += $v;
    }

    // fecha especial (28)
    $fechaEsp = sprintf("%04d-%02d-%02d", $anio, $mes, $fecha_especial);
    $vEsp = isset($aportes_map[$pid][$fechaEsp]) ? intval($aportes_map[$pid][$fechaEsp]) : 0;
    $fila['especial'] = $vEsp;
    $total_por_jugador += $vEsp;

    // sumar otros aportes
    if (!empty($fila['otros'])) {
        foreach ($fila['otros'] as $o) {
            $total_por_jugador += intval($o['valor']);
        }
    }

    $fila['total_mes'] = $total_por_jugador;

    $rows[] = $fila;
}

// 6) Totales generales: today, month_total, year_total (igual que antes)
$qTot = $conexion->prepare("
    SELECT 
        (SELECT COALESCE(SUM(aporte_principal),0) FROM aportes WHERE fecha = CURDATE()) AS today,
        (SELECT COALESCE(SUM(aporte_principal),0) FROM aportes WHERE MONTH(fecha)=? AND YEAR(fecha)=?) AS month_total,
        (SELECT COALESCE(SUM(aporte_principal),0) FROM aportes WHERE YEAR(fecha)=?) AS year_total
");
$qTot->bind_param("iii", $mes, $anio, $anio);
$qTot->execute();
$totales = $qTot->get_result()->fetch_assoc();
$qTot->close();

// 7) Observaciones (si ya tienes endpoint separado puedes usarlo; aquí lo traemos)
$qObs = $conexion->prepare("SELECT texto FROM gastos_observaciones WHERE mes=? AND anio=? LIMIT 1");
$qObs->bind_param("ii", $mes, $anio);
$qObs->execute();
$resObs = $qObs->get_result()->fetch_assoc();
$observaciones = $resObs['texto'] ?? "";
$qObs->close();

// 8) Respuesta
$response = [
    "mes" => $mes,
    "anio" => $anio,
    "dias_validos" => $dias_validos,          // ej [1,5,8,12,...]
    "fecha_especial" => $fecha_especial,
    "jugadores" => array_map(function($p){ return ["id"=>$p['id'],"nombre"=>$p['nombre']]; }, $players),
    "rows" => $rows,
    "totales" => [
        "today" => intval($totales['today'] ?? 0),
        "month_total" => intval($totales['month_total'] ?? 0),
        "year_total" => intval($totales['year_total'] ?? 0)
    ],
    "observaciones" => $observaciones
];

echo json_encode($response, JSON_UNESCAPED_UNICODE|JSON_NUMERIC_CHECK);
exit;
