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

echo "<table class='planilla'>";

/* ---------- THEAD ---------- */
/* Primera fila: Nombres | Dias de los juegos (days + Fecha Especial) | Otros aportes (colspan 2) */
echo "<thead>";
echo "<tr>";
echo "<th>Nombres</th>";

// colspan para "Días de los juegos": cantidad de days + 1 (para Fecha Especial)
$colspan_days = count($days) + 1;
echo "<th  colspan='{$colspan_days}'>Días de los juegos</th>";

// Otros aportes (dos columnas)
echo "<th colspan='2'>Otros aportes</th>";
// Aportes mensuales totales por jugador
echo "<th>Total Mes</th>";

echo "<tr></tr>";

/* Segunda fila: (vacía para Nombres) -> números de días -> Fecha Especial -> Tipo -> Valor */
echo "<tr>";
echo "<th></th>"; // espacio para nombres

// números de los días
foreach ($days as $d) {
    echo "<th>{$d}</th>";
}

// Fecha Especial (columna dentro 'Días de los juegos')
echo "<th>Fecha Especial</th>";

// Sub-headers para "Otros aportes"
echo "<th>Tipo</th>";
echo "<th>Valor</th>";
echo "<th>Por Jugador</th>";
echo "</tr>";

echo "</thead>";

/* ---------- TBODY (CORREGIDO) ---------- */
echo "<tbody>";

$totales_por_dia = array_fill(0, count($days), 0);
$total_otros_global = 0;

foreach ($jugadores as $jug) {

    $total_jugador_mes = 0;

    echo "<tr data-player='{$jug['id']}'>";

    // NOMBRE
    echo "<td>{$jug['nombre']}</td>";

    // CELDAS DE LOS DÍAS NORMALES
    foreach ($days as $idx => $d) {

        $fecha = sprintf("%04d-%02d-%02d", $anio, $mes, $d);
        $aporte = get_aporte($conexion, $jug['id'], $fecha);

        $total_jugador_mes += $aporte;
        $totales_por_dia[$idx] += $aporte;

        echo "<td>
                <input class='cell-aporte' 
                       data-player='{$jug['id']}' 
                       data-fecha='{$fecha}' 
                       type='number' 
                       placeholder='$'
                       value='" . ($aporte > 0 ? $aporte : "") . "'>
              </td>";
    }

    /* ===== COLUMNA FECHA ESPECIAL (única, bien colocada) ===== */
    $fechaEspecial = sprintf("%04d-%02d-28", $anio, $mes);
    $aporteEspecial = get_aporte($conexion, $jug['id'], $fechaEspecial);
    $total_jugador_mes += $aporteEspecial;

    echo "<td>
            <input class='cell-aporte' 
                   data-player='{$jug['id']}' 
                   data-fecha='{$fechaEspecial}' 
                   type='number' 
                   placeholder='$'
                   value='" . ($aporteEspecial > 0 ? $aporteEspecial : "") . "'>
          </td>";

    /* ===== OTROS APORTES ===== */
    $otros = get_otros($conexion, $jug['id'], $mes, $anio);
    $tipos = [];
    $valor_otros = 0;

    foreach ($otros as $o) {
        $tipos[] = htmlspecialchars($o['tipo']) . " (" . number_format($o['valor'], 0, ',', '.') . ")";
        $valor_otros += intval($o['valor']);
    }

    $total_jugador_mes += $valor_otros;
    $total_otros_global += $valor_otros;

    echo "<td>" . (empty($tipos) ? '' : implode('<br>', $tipos)) . "</td>";
    echo "<td>" . ($valor_otros ? number_format($valor_otros, 0, ',', '.') : '') . "</td>";
    echo "<td><strong>" . number_format($total_jugador_mes, 0, ',', '.') . "</strong></td>";

    echo "</tr>";
}

echo "</tbody>";

/* ---------- TFOOT (CORREGIDO) ---------- */
echo "<tfoot>";

echo "<tr>";

echo "<td ><strong>TOTAL DÍA</strong></td>";

/* Totales de días normales */
foreach ($totales_por_dia as $td) {
    echo "<td>
            <strong>" . number_format($td, 0, ',', '.') . "</strong>
          </td>";
}

/* TOTAL FECHA ESPECIAL */
$fechaEspecial = sprintf('%04d-%02d-28', $anio, $mes);
$totEspecialRes = $conexion->query("
    SELECT SUM(aporte_principal) AS t 
    FROM aportes 
    WHERE fecha = '{$fechaEspecial}'
");
$totEspecial = ($totEspecialRes && $totEspecialRes->num_rows) 
    ? intval($totEspecialRes->fetch_assoc()['t']) 
    : 0;

echo "<td>
        <strong>" . number_format($totEspecial, 0, ',', '.') . "</strong>
      </td>";

/* Total Otros Aportes (global) */
echo "<td><strong>TOTAL OTROS</strong></td>";
echo "<td>
        <strong>" . number_format($total_otros_global, 0, ',', '.') . "</strong>
      </td>";

echo "<td></td>";

echo "</tr>";

echo "</tfoot>";

echo "</table>";


?>
