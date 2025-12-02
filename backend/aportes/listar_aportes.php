<?php
include "../../conexion.php";
header("Content-Type: text/html; charset=utf-8");

// --- Obtener mes y año ---
$mes = isset($_GET['mes']) ? intval($_GET['mes']) : intval(date('n'));
$anio = isset($_GET['anio']) ? intval($_GET['anio']) : intval(date('Y'));

// --- calcular días que son miércoles (3) o sábado (6)
$days = [];
$days_count = cal_days_in_month(CAL_GREGORIAN, $mes, $anio);
for ($d = 1; $d <= $days_count; $d++) {
    $date = sprintf("%04d-%02d-%02d", $anio, $mes, $d);
    $w = date('N', strtotime($date)); 
    if ($w == 3 || $w == 6) $days[] = $d;
}

// --- obtener todos los jugadores (activos y eliminados)
$jug_res = $conexion->query("SELECT id, nombre, activo FROM jugadores ORDER BY nombre ASC");
$jugadores = [];
while ($r = $jug_res->fetch_assoc()) $jugadores[] = $r;

// --- helpers
function get_aporte($conexion, $id_jugador, $fecha) {
    $stmt = $conexion->prepare("SELECT SUM(aporte_principal) AS total FROM aportes WHERE id_jugador=? AND fecha=?");
    $stmt->bind_param("is", $id_jugador, $fecha);
    $stmt->execute();
    $res = $stmt->get_result()->fetch_assoc();
    return $res ? intval($res['total']) : 0;
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

// --- observaciones
$stmt = $conexion->prepare("SELECT texto FROM gastos_observaciones WHERE mes=? AND anio=? LIMIT 1");
$stmt->bind_param("ii", $mes, $anio);
$stmt->execute();
$obs_res = $stmt->get_result()->fetch_assoc();
$observaciones = $obs_res['texto'] ?? '';

echo "<div class='monthly-sheet'>";
$mesesEsp = [
  1=>"Enero",2=>"Febrero",3=>"Marzo",4=>"Abril",5=>"Mayo",6=>"Junio",
  7=>"Julio",8=>"Agosto",9=>"Septiembre",10=>"Octubre",11=>"Noviembre",12=>"Diciembre"
];

echo "<div class='month-header'>Mes: <strong>{$mesesEsp[$mes]} $anio</strong></div>";

echo "<table class='planilla'>";

/* ---------- THEAD ---------- */
echo "<thead>";
echo "<tr>";
echo "<th>Nombres</th>";

$colspan_days = count($days) + 1;
echo "<th colspan='{$colspan_days}'>Días de los juegos</th>";

echo "<th colspan='2'>Otros aportes</th>";
echo "<th>Total Mes</th>";
echo "<th>Acciones</th>";
echo "</tr>";

echo "<tr>";
echo "<th></th>";

foreach ($days as $d) echo "<th>{$d}</th>";

echo "<th>Fecha Especial</th>";
echo "<th>Tipo</th>";
echo "<th>Valor</th>";
echo "<th>Por Jugador</th>";
echo "<th></th>"; 
echo "</tr>";
echo "</thead>";

/* ---------- TBODY ---------- */
echo "<tbody>";

$totales_por_dia = array_fill(0, count($days), 0);
$total_otros_global = 0;
$totales_mes = 0; // Total mes incluyendo eliminados

foreach ($jugadores as $jug) {
    $total_jugador_mes = 0;
    $row_class = $jug['activo'] ? '' : 'eliminado'; // clase para jugadores eliminados

    echo "<tr data-player='{$jug['id']}' class='{$row_class}'>";
    echo "<td>{$jug['nombre']}</td>";

    foreach ($days as $idx => $d) {
        $fecha = sprintf("%04d-%02d-%02d", $anio, $mes, $d);
        $aporte = get_aporte($conexion, $jug['id'], $fecha);
        $total_jugador_mes += $aporte;
        $totales_por_dia[$idx] += $aporte;
        echo "<td><input class='cell-aporte' data-player='{$jug['id']}' data-fecha='{$fecha}' type='number' placeholder='$' value='" . ($aporte>0?$aporte:"") . "'></td>";
    }

    // Fecha especial
    $fechaEspecial = sprintf("%04d-%02d-28", $anio, $mes);
    $aporteEspecial = get_aporte($conexion, $jug['id'], $fechaEspecial);
    $total_jugador_mes += $aporteEspecial;
    echo "<td><input class='cell-aporte' data-player='{$jug['id']}' data-fecha='{$fechaEspecial}' type='number' placeholder='$' value='" . ($aporteEspecial>0?$aporteEspecial:"") . "'></td>";

    // Otros aportes
    $otros = get_otros($conexion, $jug['id'], $mes, $anio);
    $tipos = [];
    $valor_otros = 0;
    foreach ($otros as $o) {
        $tipos[] = htmlspecialchars($o['tipo']) . " (" . number_format($o['valor'],0,',','.') . ")";
        $valor_otros += intval($o['valor']);
    }
    $total_jugador_mes += $valor_otros;
    $total_otros_global += $valor_otros;

    echo "<td>" . (empty($tipos)?'':implode('<br>',$tipos)) . "</td>";
    echo "<td>" . ($valor_otros?number_format($valor_otros,0,',','.'):'') . "</td>";
    echo "<td><strong>" . number_format($total_jugador_mes,0,',','.') . "</strong></td>";

    // Acciones
    echo "<td class='acciones'><button class='btn-del-player' data-id='{$jug['id']}'>🗑️</button></td>";

    echo "</tr>";

    $totales_mes += $total_jugador_mes; // sumamos al total global
}

echo "</tbody>";

/* ---------- TFOOT ---------- */
echo "<tfoot>";
echo "<tr>";
echo "<td><strong>TOTAL DÍA</strong></td>";

// Totales por días normales
foreach ($totales_por_dia as $td) {
    echo "<td><strong>" . number_format($td,0,',','.') . "</strong></td>";
}

// Fecha especial: totalizar
$fechaEspecial = sprintf("%04d-%02d-28", $anio, $mes);
$totEspecialRes = $conexion->query("SELECT SUM(aporte_principal) AS t FROM aportes WHERE fecha='{$fechaEspecial}'");
$totEspecial = ($totEspecialRes && $totEspecialRes->num_rows) ? intval($totEspecialRes->fetch_assoc()['t']) : 0;

// Mostrar total de la fecha especial
echo "<td><strong>" . number_format($totEspecial,0,',','.') . "</strong></td>";

// Totales de otros aportes
echo "<td><strong>TOTAL OTROS</strong></td>";
echo "<td><strong>" . number_format($total_otros_global,0,',','.') . "</strong></td>";

// Total mes incluyendo eliminados (ahora sumando totales por día + fecha especial + otros aportes)
$totales_mes_actualizado = array_sum($totales_por_dia) + $totEspecial + $total_otros_global;
echo "<td><strong>" . number_format($totales_mes_actualizado,0,',','.') . "</strong></td>";

echo "<td></td>"; 
echo "</tr>";
echo "</tfoot>";


echo "</table>";
?>
