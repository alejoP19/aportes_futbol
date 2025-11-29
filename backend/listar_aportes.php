<?php
// backend/listar_aportes.php (versión actualizada según tus requerimientos)
// Devuelve HTML de la planilla mensual (solo Miércoles y Sábados)
// Usa ../conexion.php para conectarse

include "../conexion.php";
header("Content-Type: text/html; charset=utf-8");

$mes = isset($_GET['mes']) ? intval($_GET['mes']) : intval(date('n'));
$anio = isset($_GET['anio']) ? intval($_GET['anio']) : intval(date('Y'));

// --- calcular días que son miércoles (3) o sábado (6)
$days = [];
$days_count = cal_days_in_month(CAL_GREGORIAN, $mes, $anio);
for ($d = 1; $d <= $days_count; $d++) {
    $date = sprintf("%04d-%02d-%02d", $anio, $mes, $d);
    $w = date('N', strtotime($date)); // 1 (lun) .. 7 (dom)
    if ($w == 3 || $w == 6) $days[] = $d;
}

// obtener jugadores
$jug_res = $conexion->query("SELECT id, nombre FROM jugadores ORDER BY nombre ASC");
$jugadores = [];
while ($r = $jug_res->fetch_assoc()) $jugadores[] = $r;

// helpers
function get_aporte($conexion, $id_jugador, $fecha) {
    $stmt = $conexion->prepare("SELECT aporte_principal FROM aportes WHERE id_jugador=? AND fecha=?");
    $stmt->bind_param("is", $id_jugador, $fecha);
    $stmt->execute();
    $res = $stmt->get_result()->fetch_assoc();
    return $res ? intval($res['aporte_principal']) : 0;
}
function get_otros($conexion, $id_jugador, $mes, $anio) {
    $stmt = $conexion->prepare("SELECT tipo, valor FROM otros_aportes WHERE id_jugador=? AND mes=? AND anio=?");
    $stmt->bind_param("iii", $id_jugador, $mes, $anio);
    $stmt->execute();
    $res = $stmt->get_result();
    $list = [];
    while ($row = $res->fetch_assoc()) $list[] = $row;
    return $list;
}

// observaciones
$stmt = $conexion->prepare("SELECT texto FROM gastos_observaciones WHERE mes=? AND anio=? LIMIT 1");
$stmt->bind_param("ii", $mes, $anio);
$stmt->execute();
$obs_res = $stmt->get_result()->fetch_assoc();
$observaciones = $obs_res['texto'] ?? '';

// --- Construcción HTML ---
echo "<div class='monthly-sheet'>";
echo "<div class='month-header'>Mes: <strong>" . date('F', mktime(0,0,0,$mes,1)) . " $anio</strong></div>";

echo "<table class='planilla' style='width:100%;border-collapse:collapse'>";

/* ---------- THEAD ---------- */
/* Primera fila: Nombres | Dias de los juegos (days + Fecha Especial) | Otros aportes (colspan 2) */
echo "<thead>";
echo "<tr>";
echo "<th style='border:1px solid #eee;padding:6px'>Nombres</th>";

// colspan para "Días de los juegos": cantidad de days + 1 (para Fecha Especial)
$colspan_days = count($days) + 1;
echo "<th style='border:1px solid #eee;padding:6px;text-align:center' colspan='{$colspan_days}'>Días de los juegos</th>";

// Otros aportes (dos columnas)
echo "<th style='border:1px solid #eee;padding:6px;text-align:center' colspan='2'>Otros aportes</th>";

echo "</tr>";

/* Segunda fila: (vacía para Nombres) -> números de días -> Fecha Especial -> Tipo -> Valor */
echo "<tr>";
echo "<th style='border:1px solid #eee;padding:6px'></th>"; // espacio para nombres

// números de los días
foreach ($days as $d) {
    echo "<th style='border:1px solid #eee;padding:6px;text-align:center'>{$d}</th>";
}

// Fecha Especial (columna dentro 'Días de los juegos')
echo "<th style='border:1px solid #eee;padding:6px;text-align:center'>Fecha Especial</th>";

// Sub-headers para "Otros aportes"
echo "<th style='border:1px solid #eee;padding:6px;text-align:center'>Tipo</th>";
echo "<th style='border:1px solid #eee;padding:6px;text-align:center'>Valor</th>";

echo "</tr>";
echo "</thead>";

/* ---------- TBODY ---------- */
echo "<tbody>";

$totales_por_dia = array_fill(0, count($days), 0);
$total_otros_global = 0;

foreach ($jugadores as $jug) {

    $total_jugador_mes = 0;

    echo "<tr data-player='{$jug['id']}'>";
    // NOMBRE
    echo "<td style='border:1px solid #eee;padding:6px'>{$jug['nombre']}</td>";

    // CELDAS DE CADA DÍA (inputs cortos)
    foreach ($days as $idx => $d) {
        $fecha = sprintf("%04d-%02d-%02d", $anio, $mes, $d);
        $aporte = get_aporte($conexion, $jug['id'], $fecha);

        $total_jugador_mes += $aporte;
        $totales_por_dia[$idx] += $aporte;

        echo "<td style='border:1px solid #eee;padding:4px;text-align:center'>
                <input class='cell-aporte' data-player='{$jug['id']}' data-fecha='{$fecha}' type='number' min='0' value='{$aporte}' style='width:70px;padding:4px;font-size:13px'>
              </td>";
    }

    // FECHA ESPECIAL (antes de otros aportes)
    echo "<td style='border:1px solid #eee;padding:6px;text-align:center'>
            <input class='cell-especial' data-player='{$jug['id']}' type='text' placeholder='Fecha / nota' style='width:120px;padding:4px;font-size:13px'>
          </td>";

    // OTROS APORTES: mostrar tipos (line-break) y sumar valores
    $otros = get_otros($conexion, $jug['id'], $mes, $anio);
    $tipos = [];
    $valor_otros = 0;
    foreach ($otros as $o) {
        $tipos[] = htmlspecialchars($o['tipo']);
        $valor_otros += intval($o['valor']);
    }

    $total_jugador_mes += $valor_otros;
    $total_otros_global += $valor_otros;

    // Tipo(s)
    echo "<td style='border:1px solid #eee;padding:6px'>" . (empty($tipos) ? '' : implode('<br>', $tipos)) . "</td>";
    // Valor total de los otros aportes del jugador (en esta fila)
    echo "<td style='border:1px solid #eee;padding:6px;text-align:right'>" . ( $valor_otros ? number_format($valor_otros,0,',','.') : '' ) . "</td>";

    echo "</tr>";
}

echo "</tbody>";

/* ---------- TFOOT: Totales por día y Total Otros (no hay total mes) ---------- */
echo "<tfoot>";

// Totales por día (fila)
echo "<tr style='background:#fafafa'>";
echo "<td style='border:1px solid #eee;padding:6px'><strong>TOTAL DÍA</strong></td>";
foreach ($totales_por_dia as $td) {
    echo "<td style='border:1px solid #eee;padding:6px;text-align:right'><strong>" . number_format($td,0,',','.') . "</strong></td>";
}
// columna Fecha Especial (vacía en totales)
echo "<td style='border:1px solid #eee;padding:6px'></td>";

// "TOTAL OTROS" label under Tipo column
echo "<td style='border:1px solid #eee;padding:6px'><strong>TOTAL OTROS</strong></td>";
// total valor otros in Valor column
echo "<td style='border:1px solid #eee;padding:6px;text-align:right'><strong>" . number_format($total_otros_global,0,',','.') . "</strong></td>";

echo "</tr>";

echo "</tfoot>";

echo "</table>";


?>
