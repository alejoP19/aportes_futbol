<?php
include "../../conexion.php";
header("Content-Type: application/json; charset=utf-8");

$mes  = isset($_GET['mes'])  ? intval($_GET['mes'])  : intval(date("n"));
$anio = isset($_GET['anio']) ? intval($_GET['anio']) : intval(date("Y"));

if ($mes < 1 || $mes > 12) $mes = (int)date("n");
if ($anio < 1900) $anio = (int)date("Y");

$TOPE = 3000;

/* =========================================================
   HELPERS
========================================================= */
function pick_default_otro_dia($days, $days_count) {
    if (!in_array(28, $days, true) && 28 <= $days_count) return 28;
    for ($d = 1; $d <= $days_count; $d++) {
        if (!in_array($d, $days, true)) return $d;
    }
    return 1;
}

function get_saldo_hasta_mes($conexion, $id_jugador, $mes, $anio, $TOPE = 3000) {
    $fechaCorte = date('Y-m-t', strtotime("$anio-$mes-01"));

    $q1 = $conexion->prepare("
        SELECT IFNULL(SUM(GREATEST(aporte_principal - ?, 0)),0) AS excedente
        FROM aportes
        WHERE id_jugador = ?
          AND fecha <= ?
          AND id_jugador IS NOT NULL
          AND tipo_aporte IS NULL
    ");
    $q1->bind_param("iis", $TOPE, $id_jugador, $fechaCorte);
    $q1->execute();
    $excedente = (int)($q1->get_result()->fetch_assoc()['excedente'] ?? 0);
    $q1->close();

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

function total_efectivo_registrados_por_fecha($conexion, $fecha, $TOPE = 3000) {
    $stmt = $conexion->prepare("
        SELECT IFNULL(SUM(
            LEAST(
                LEAST(IFNULL(a.aporte_principal,0), ?) + IFNULL(t.consumido,0),
                ?
            )
        ),0) AS s
        FROM aportes a
        LEFT JOIN (
            SELECT target_aporte_id, SUM(amount) AS consumido
            FROM aportes_saldo_moves
            GROUP BY target_aporte_id
        ) t ON t.target_aporte_id = a.id
        WHERE a.fecha = ?
          AND a.id_jugador IS NOT NULL
          AND a.tipo_aporte IS NULL
    ");
    $stmt->bind_param("iis", $TOPE, $TOPE, $fecha);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return (int)($row['s'] ?? 0);
}

function total_esporadicos_por_fecha($conexion, $fecha) {
    $stmt = $conexion->prepare("
        SELECT IFNULL(SUM(aporte_principal),0) AS s
        FROM aportes
        WHERE fecha = ?
          AND tipo_aporte='esporadico'
    ");
    $stmt->bind_param("s", $fecha);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return (int)($row['s'] ?? 0);
}

function calcular_excedente_mes(mysqli $cx, int $anio, int $mes, int $TOPE): int {
    $sql = "
        SELECT IFNULL(SUM(GREATEST(aporte_principal - $TOPE, 0)),0) AS excedente_mes
        FROM aportes
        WHERE YEAR(fecha) = $anio
          AND MONTH(fecha) = $mes
          AND id_jugador IS NOT NULL
          AND tipo_aporte IS NULL
    ";
    $res = $cx->query($sql);
    return (int)($res->fetch_assoc()['excedente_mes'] ?? 0);
}

function calcular_saldo_acumulado_real(mysqli $cx, string $fechaCorte, int $TOPE): int {
    $sql = "
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
              AND id_jugador IS NOT NULL
            GROUP BY id_jugador
        ) co ON co.id_jugador = j.id
    ";

    $res = $cx->query($sql);
    return (int)($res->fetch_assoc()['saldo'] ?? 0);
}

function calcular_totales_mes(mysqli $cx, int $anio, int $mes, int $TOPE = 3000): array {
    $fechaCorte = date('Y-m-t', strtotime("$anio-$mes-01"));

    $month_total = (int)($cx->query("
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

    $esporadicos_mes = (int)($cx->query("
        SELECT IFNULL(SUM(aporte_principal),0) AS s
        FROM aportes
        WHERE tipo_aporte='esporadico'
          AND YEAR(fecha)=$anio AND MONTH(fecha)=$mes
    ")->fetch_assoc()['s'] ?? 0);

    $otros_mes = (int)($cx->query("
        SELECT IFNULL(SUM(valor),0) AS s
        FROM otros_aportes
        WHERE mes=$mes AND anio=$anio
    ")->fetch_assoc()['s'] ?? 0);

    $otros_esporadicos_mes = (int)($cx->query("
        SELECT IFNULL(SUM(aporte_principal),0) AS s
        FROM aportes
        WHERE tipo_aporte='esporadico_otro'
          AND YEAR(fecha)=$anio AND MONTH(fecha)=$mes
    ")->fetch_assoc()['s'] ?? 0);

    $otros_mes += $otros_esporadicos_mes;

    $gastos_mes = (int)($cx->query("
        SELECT IFNULL(SUM(valor),0) AS s
        FROM gastos
        WHERE mes=$mes AND anio=$anio
    ")->fetch_assoc()['s'] ?? 0);

    $saldo_mes   = calcular_excedente_mes($cx, $anio, $mes, $TOPE);
    $saldo_total = calcular_saldo_acumulado_real($cx, $fechaCorte, $TOPE);

    $parcial_mes    = (int)($month_total + $esporadicos_mes + $otros_mes);
    $final_neto_mes = (int)($parcial_mes - $gastos_mes);

    return [
        "fecha_corte"     => $fechaCorte,
        "month_total"     => $month_total,
        "esporadicos_mes" => $esporadicos_mes,
        "otros_mes"       => $otros_mes,
        "gastos_mes"      => $gastos_mes,
        "parcial_mes"     => $parcial_mes,
        "final_neto_mes"  => $final_neto_mes,
        "saldo_mes"       => (int)$saldo_mes,
        "saldo_total"     => (int)$saldo_total,
    ];
}

/* =========================================================
   1) DÍAS VÁLIDOS Y OTRO DÍA
========================================================= */
$dias_validos = [];
$daysInMonth  = cal_days_in_month(CAL_GREGORIAN, $mes, $anio);

for ($d = 1; $d <= $daysInMonth; $d++) {
    $fecha = sprintf("%04d-%02d-%02d", $anio, $mes, $d);
    $dow   = date("N", strtotime($fecha));
    if ($dow == 3 || $dow == 6) $dias_validos[] = $d;
}

$otroDia = isset($_GET['otro']) ? intval($_GET['otro']) : pick_default_otro_dia($dias_validos, $daysInMonth);
if ($otroDia < 1 || $otroDia > $daysInMonth) $otroDia = pick_default_otro_dia($dias_validos, $daysInMonth);
if (in_array($otroDia, $dias_validos, true)) $otroDia = pick_default_otro_dia($dias_validos, $daysInMonth);

$otrosDias = [];
for ($d = 1; $d <= $daysInMonth; $d++) {
    if (!in_array($d, $dias_validos, true)) $otrosDias[] = $d;
}

/* =========================================================
   2) JUGADORES VISIBLES EN PLANILLA PÚBLICA
========================================================= */
$fechaCorteMes = date('Y-m-t', strtotime("$anio-$mes-01"));

$players = [];
$sqlPlayers = "
    SELECT id, nombre, activo, fecha_baja
    FROM jugadores
    WHERE
      activo = 1
      OR (activo = 0 AND (fecha_baja IS NULL OR fecha_baja > '$fechaCorteMes'))
    ORDER BY nombre ASC
";
$res = $conexion->query($sqlPlayers);
while ($r = $res->fetch_assoc()) $players[] = $r;

$playerIds = array_map(fn($p) => intval($p['id']), $players);

/* =========================================================
   3) MAPAS: APORTES, CONSUMO, OTROS, DEUDAS
========================================================= */
$aportes_map  = [];
$consumo_map  = [];
$otros_map    = [];
$deudas_mes   = [];
$deudas_lista = [];

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

/* =========================================================
   4) ROWS PÚBLICAS
   - Por Jugador = SOLO días normales + todos los otros juegos
   - NO suma otros_aportes (igual Admin)
========================================================= */
$rows = [];

foreach ($players as $p) {
    $pid = (int)$p['id'];

    $fila = [
        "id"                => $pid,
        "nombre"            => $p['nombre'],
        "activo"            => (int)$p['activo'],
        "dias"              => [],
        "real_dias"         => [],
        "consumo_dias"      => [],
        "efectivo_dias"     => [],
        "especial"          => 0,
        "real_especial"     => 0,
        "consumo_especial"  => 0,
        "efectivo_especial" => 0,
        "otros"             => $otros_map[$pid] ?? [],
        "saldo"             => 0,
        "total_mes"         => 0,
        "deudas"            => $deudas_mes[$pid] ?? [],
        "deudas_lista"      => $deudas_lista[$pid] ?? [],
        "total_deudas"      => isset($deudas_lista[$pid]) ? count($deudas_lista[$pid]) : 0,
        "otro_dia"          => $otroDia,
    ];

    $total_dias_normales = 0;
    $total_otros_juegos_jugador = 0;

    foreach ($dias_validos as $d) {
        $f = sprintf("%04d-%02d-%02d", $anio, $mes, $d);

        $real     = (int)($aportes_map[$pid][$f] ?? 0);
        $cashCap  = min($real, $TOPE);
        $consumo  = (int)($consumo_map[$pid][$f] ?? 0);
        $efectivo = min($cashCap + $consumo, $TOPE);

        $fila["dias"][]          = $cashCap;
        $fila["real_dias"][]     = $real;
        $fila["consumo_dias"][]  = $consumo;
        $fila["efectivo_dias"][] = $efectivo;

        $total_dias_normales += $efectivo;
    }

    foreach ($otrosDias as $dOther) {
        $fOther = sprintf("%04d-%02d-%02d", $anio, $mes, $dOther);

        $realOther     = (int)($aportes_map[$pid][$fOther] ?? 0);
        $cashCapOther  = min($realOther, $TOPE);
        $consOther     = (int)($consumo_map[$pid][$fOther] ?? 0);
        $efecOther     = min($cashCapOther + $consOther, $TOPE);

        $total_otros_juegos_jugador += $efecOther;

        if ($dOther === $otroDia) {
            $fila["especial"]          = $cashCapOther;
            $fila["real_especial"]     = $realOther;
            $fila["consumo_especial"]  = $consOther;
            $fila["efectivo_especial"] = $efecOther;
        }
    }

    $fila["total_mes"] = $total_dias_normales + $total_otros_juegos_jugador;
    $fila["saldo"]     = get_saldo_hasta_mes($conexion, $pid, $mes, $anio, $TOPE);

    $rows[] = $fila;
}

/* =========================================================
   5) TFOOT IGUAL ADMIN
========================================================= */
$totales_por_dia_footer = [];
$total_otro_footer = 0;
$total_otros_aportes_footer = 0;

foreach ($dias_validos as $d) {
    $f = sprintf("%04d-%02d-%02d", $anio, $mes, $d);
    $totales_por_dia_footer[] =
        total_efectivo_registrados_por_fecha($conexion, $f, $TOPE)
        + total_esporadicos_por_fecha($conexion, $f);
}

foreach ($otrosDias as $dOther) {
    $f = sprintf("%04d-%02d-%02d", $anio, $mes, $dOther);
    $total_otro_footer +=
        total_efectivo_registrados_por_fecha($conexion, $f, $TOPE)
        + total_esporadicos_por_fecha($conexion, $f);
}

$totales_parcial_footer = array_sum($totales_por_dia_footer) + $total_otro_footer;

$rowOA = $conexion->query("
    SELECT IFNULL(SUM(valor),0) AS s
    FROM otros_aportes
    WHERE mes=$mes AND anio=$anio
")->fetch_assoc();
$total_otros_aportes_footer += (int)($rowOA["s"] ?? 0);

$rowEO = $conexion->query("
    SELECT IFNULL(SUM(aporte_principal),0) AS s
    FROM aportes
    WHERE tipo_aporte='esporadico_otro'
      AND YEAR(fecha)=$anio AND MONTH(fecha)=$mes
")->fetch_assoc();
$total_otros_aportes_footer += (int)($rowEO["s"] ?? 0);

$totales_final_con_otros_footer = (int)($totales_parcial_footer + $total_otros_aportes_footer);

$inicioMes = sprintf("%04d-%02d-01", $anio, $mes);
$finMes    = date("Y-m-t", strtotime($inicioMes));

$elimIdsMes = [];
$stmt = $conexion->prepare("
    SELECT id
    FROM jugadores
    WHERE activo=0
      AND fecha_baja IS NOT NULL
      AND fecha_baja >= ?
      AND fecha_baja <= ?
");
$stmt->bind_param("ss", $inicioMes, $finMes);
$stmt->execute();
$resE = $stmt->get_result();
while ($r = $resE->fetch_assoc()) $elimIdsMes[] = (int)$r["id"];
$stmt->close();

$eliminados_mes_total_footer = 0;
if (!empty($elimIdsMes)) {
    $inMes = implode(",", array_map("intval", $elimIdsMes));

    $eliminados_mes_total_footer = (int)($conexion->query("
        SELECT IFNULL(SUM(
            LEAST(LEAST(IFNULL(a.aporte_principal,0), $TOPE) + IFNULL(t.consumido,0), $TOPE)
        ),0) AS s
        FROM aportes a
        LEFT JOIN (
            SELECT target_aporte_id, SUM(amount) AS consumido
            FROM aportes_saldo_moves
            GROUP BY target_aporte_id
        ) t ON t.target_aporte_id = a.id
        WHERE YEAR(a.fecha)=$anio
          AND MONTH(a.fecha)=$mes
          AND a.tipo_aporte IS NULL
          AND a.id_jugador IN ($inMes)
    ")->fetch_assoc()["s"] ?? 0);
}

$totales_base_sin_eliminados_footer = (int)max(0, $totales_parcial_footer - $eliminados_mes_total_footer);

$rowSaldo = $conexion->query("
    SELECT IFNULL(SUM(
        GREATEST(IFNULL(ex.excedente,0) - IFNULL(co.consumido,0), 0)
    ),0) AS saldo
    FROM jugadores j
    LEFT JOIN (
        SELECT id_jugador, SUM(GREATEST(aporte_principal - $TOPE, 0)) AS excedente
        FROM aportes
        WHERE fecha <= '$fechaCorteMes'
          AND id_jugador IS NOT NULL
          AND tipo_aporte IS NULL
        GROUP BY id_jugador
    ) ex ON ex.id_jugador = j.id
    LEFT JOIN (
        SELECT id_jugador, SUM(amount) AS consumido
        FROM aportes_saldo_moves
        WHERE fecha_consumo <= '$fechaCorteMes'
        GROUP BY id_jugador
    ) co ON co.id_jugador = j.id
")->fetch_assoc();

$saldo_total_footer = (int)($rowSaldo["saldo"] ?? 0);

/* =========================================================
   6) TOTALES LATERALES IGUAL ADMIN
========================================================= */
$mesActual = calcular_totales_mes($conexion, $anio, $mes, $TOPE);

$parcial_anio    = 0;
$otros_anio      = 0;
$gastos_anio     = 0;
$final_anio_neto = 0;

for ($m = 1; $m <= $mes; $m++) {
    $tmp = calcular_totales_mes($conexion, $anio, $m, $TOPE);
    $parcial_anio    += (int)$tmp["parcial_mes"];
    $otros_anio      += (int)$tmp["otros_mes"];
    $gastos_anio     += (int)$tmp["gastos_mes"];
    $final_anio_neto += (int)$tmp["final_neto_mes"];
}

$saldo_total_cards = (int)$mesActual["saldo_total"];
$total_real_hasta_fecha = (int)($final_anio_neto + $saldo_total_cards);

/* =========================================================
   7) GASTOS, OTROS, OBSERVACIONES
========================================================= */
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
        "valor"  => (int)$g["valor"]
    ];
}
$qDet->close();

$otros_detalle = [];

/* -----------------------------------------
   1) otros_aportes normales
----------------------------------------- */
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
        "tipo"  => (string)$o["tipo"],
        "valor" => (int)$o["valor"],
        "origen" => "normal"
    ];
}
$qOtrosDet->close();

/* -----------------------------------------
   2) otros aportes esporádicos (meta)
   tipo_aporte = esporadico_otro
----------------------------------------- */
$fechaMeta = sprintf("%04d-%02d-01", $anio, $mes);

$qEspOtros = $conexion->prepare("
    SELECT esporadico_slot, IFNULL(aporte_principal,0) AS valor, IFNULL(nota,'') AS nota
    FROM aportes
    WHERE tipo_aporte = 'esporadico_otro'
      AND fecha = ?
    ORDER BY esporadico_slot ASC
");
$qEspOtros->bind_param("s", $fechaMeta);
$qEspOtros->execute();
$resEspOtros = $qEspOtros->get_result();

while ($eo = $resEspOtros->fetch_assoc()) {
    $slot = (int)($eo["esporadico_slot"] ?? 0);
    $valor = (int)($eo["valor"] ?? 0);
    $nota = trim((string)($eo["nota"] ?? ""));

    if ($valor <= 0) continue;

    $label = $nota !== ""
        ? "Esporádico {$slot} - {$nota}"
        : "Esporádico {$slot}";

    $otros_detalle[] = [
        "tipo"   => $label,
        "valor"  => $valor,
        "origen" => "esporadico_otro"
    ];
}
$qEspOtros->close();

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

/* =========================================================
   RESPUESTA
========================================================= */
echo json_encode([
    "ok"            => true,
    "mes"           => $mes,
    "anio"          => $anio,
    "tope"          => $TOPE,
    "dias_validos"  => $dias_validos,
    "otro_dia"      => $otroDia,
    "rows"          => $rows,
    "observaciones" => $observaciones,
    "gastos_detalle"=> $gastos_detalle,
    "otros_detalle" => $otros_detalle,

    "tfoot" => [
        "totales_por_dia_footer"             => $totales_por_dia_footer,
        "total_otro_footer"                  => (int)$total_otro_footer,
        "total_otros_aportes_footer"         => (int)$total_otros_aportes_footer,
        "totales_parcial_footer"             => (int)$totales_parcial_footer,
        "eliminados_mes_total_footer"        => (int)$eliminados_mes_total_footer,
        "totales_base_sin_eliminados_footer" => (int)$totales_base_sin_eliminados_footer,
        "totales_final_con_otros_footer"     => (int)$totales_final_con_otros_footer,
        "saldo_total_footer"                 => (int)$saldo_total_footer
    ],

    "totales" => [
        "parcial_mes"            => (int)$mesActual["parcial_mes"],
        "parcial_anio"           => (int)$parcial_anio,
        "otros_mes"              => (int)$mesActual["otros_mes"],
        "otros_anio"             => (int)$otros_anio,
        "gastos_mes"             => (int)$mesActual["gastos_mes"],
        "gastos_anio"            => (int)$gastos_anio,
        "final_neto_mes"         => (int)$mesActual["final_neto_mes"],
        "final_anio_neto"        => (int)$final_anio_neto,
        "saldo_mes"              => (int)$mesActual["saldo_mes"],
        "saldo_total"            => (int)$saldo_total_cards,
        "total_real_hasta_fecha" => (int)$total_real_hasta_fecha
    ]
], JSON_UNESCAPED_UNICODE);

exit;