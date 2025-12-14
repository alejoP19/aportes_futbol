<?php
include "../../conexion.php";
header("Content-Type: text/html; charset=utf-8");

// --- Obtener mes y año ---
$mes  = isset($_GET['mes'])  ? intval($_GET['mes'])  : intval(date('n'));
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


// ===========================
// HELPERS
// ===========================
function get_aporte($conexion, $id_jugador, $fecha) {
    $stmt = $conexion->prepare("
        SELECT LEAST(IFNULL(aporte_principal,0), 2000) AS total 
        FROM aportes 
        WHERE id_jugador=? AND fecha=? 
        LIMIT 1
    ");
    $stmt->bind_param("is", $id_jugador, $fecha);
    $stmt->execute();
    $res = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $res ? intval($res['total']) : 0;
}

function get_otros($conexion, $id_jugador, $mes, $anio) {
    $stmt = $conexion->prepare("SELECT tipo, valor FROM otros_aportes WHERE id_jugador=? AND mes=? AND anio=?");
    $stmt->bind_param("iii", $id_jugador, $mes, $anio);
    $stmt->execute();
    $res = $stmt->get_result();
    $list = [];
    while ($row = $res->fetch_assoc()) $list[] = $row;
    $stmt->close();
    return $list;
}

function get_aporte_real($conexion, $id_jugador, $fecha) {
    $stmt = $conexion->prepare("SELECT aporte_principal FROM aportes WHERE id_jugador=? AND fecha=? LIMIT 1");
    $stmt->bind_param("is", $id_jugador, $fecha);
    $stmt->execute();
    $res = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $res ? intval($res['aporte_principal']) : 0;
}

function get_saldo_acumulado($conexion, $id_jugador, $mes, $anio) {

    // Fecha de corte = último día del mes que estoy viendo
    $fechaCorte = date('Y-m-t', strtotime("$anio-$mes-01"));

    // 1️⃣ Excedente generado hasta esa fecha
    $q1 = $conexion->prepare("
        SELECT IFNULL(SUM(GREATEST(aporte_principal - 2000, 0)), 0) AS excedente
        FROM aportes
        WHERE id_jugador = ?
          AND fecha <= ?
    ");
    $q1->bind_param("is", $id_jugador, $fechaCorte);
    $q1->execute();
    $excedente = (int)($q1->get_result()->fetch_assoc()['excedente'] ?? 0);
    $q1->close();

    // 2️⃣ Consumo del saldo hasta esa fecha (CLAVE)
    $q2 = $conexion->prepare("
        SELECT IFNULL(SUM(amount), 0) AS consumido
        FROM aportes_saldo_moves
        WHERE id_jugador = ?
          AND fecha_consumo <= ?
    ");
    $q2->bind_param("is", $id_jugador, $fechaCorte);
    $q2->execute();
    $consumido = (int)($q2->get_result()->fetch_assoc()['consumido'] ?? 0);
    $q2->close();

    $saldo = $excedente - $consumido;
    return ($saldo > 0) ? $saldo : 0;
}

// ===========================
// CARGAR DEUDAS POR DÍA (opción A)
// ===========================
$deudas_map = [];

$deudas_res = $conexion->query("
    SELECT id_jugador, fecha
    FROM deudas_aportes
    WHERE MONTH(fecha) = $mes AND YEAR(fecha) = $anio
");


while ($row = $deudas_res->fetch_assoc()) {
    $dia = intval(date("j", strtotime($row['fecha'])));
    $jid = intval($row['id_jugador']);
    $deudas_map[$jid][$dia] = true;
}



// ===========================
// HTML
// ===========================
echo "<div class='monthly-sheet'>";

$mesesEsp = [
  1=>"Enero",2=>"Febrero",3=>"Marzo",4=>"Abril",5=>"Mayo",6=>"Junio",
  7=>"Julio",8=>"Agosto",9=>"Septiembre",10=>"Octubre",11=>"Noviembre",12=>"Diciembre"
];

echo "<div class='month-header'>Mes: <strong>{$mesesEsp[$mes]} $anio</strong></div>";

echo "<table class='planilla'>";

echo "<thead>";
echo "<tr>";
echo "<th>Nombres</th>";
echo "<th colspan='".(count($days)+1)."'>Días de los juegos</th>";
echo "<th colspan='2'>Otros aportes</th>";
echo "<th>Total Mes</th>";
echo "<th>Saldo</th>";
echo "<th>Acciones</th>";
echo "<th>Deudas</th>";
echo "</tr>";

echo "<tr>";
echo "<th></th>";
foreach ($days as $d) echo "<th>{$d}</th>";
echo "<th>Fecha Especial</th>";
echo "<th>Tipo</th>";
echo "<th>Valor</th>";
echo "<th>Por Jugador</th>";
echo "<th></th>";
echo "<th></th>";
echo "<th>Tu Deuda</th>";

echo "</tr>";
echo "</thead>";

echo "<tbody>";

$totales_por_dia = array_fill(0, count($days), 0);
$total_otros_global = 0;
// B) CARGAR TOTAL DE DEUDAS HASTA EL MES MOSTRADO
$deudas_totales = [];

$resTot = $conexion->query("
    SELECT id_jugador, COUNT(*) AS total
    FROM deudas_aportes
    WHERE 
        (YEAR(fecha) < $anio)
        OR (YEAR(fecha) = $anio AND MONTH(fecha) <= $mes)
    GROUP BY id_jugador
");

while ($row = $resTot->fetch_assoc()) {
    $deudas_totales[$row['id_jugador']] = intval($row['total']);
}



while ($row = $resTot->fetch_assoc()) {
    $deudas_totales[$row['id_jugador']] = $row['total'];
}

foreach ($jugadores as $jug) {

    $jugId = intval($jug['id']);
   $deudaDias = $deudas_totales[$jugId] ?? 0;

    $tieneDeuda = $deudaDias > 0;
    $total_jugador_mes = 0;

   $claseEliminado = ($jug['activo'] == 0) ? "eliminado" : "";
   echo "<tr data-player='{$jugId}' class='{$claseEliminado}'>";
   echo "<td>".htmlspecialchars($jug['nombre'])."</td>";

    // ======================
    // DÍAS NORMALES
    // ======================
    foreach ($days as $idx => $d) {

        $fecha = sprintf("%04d-%02d-%02d", $anio, $mes, $d);

        $aporte     = get_aporte($conexion, $jugId, $fecha);
        $aporteReal = get_aporte_real($conexion, $jugId, $fecha);

        $hayDeuda = isset($deudas_map[$jugId][$d]);

        $total_jugador_mes += $aporte;
        $totales_por_dia[$idx] += $aporte;
        $flag = ($aporteReal > 2000) ? "★" : "";
        echo "
<td class='celda-dia'>
    <div class='aporte-wrapper'>
       <input class='cell-aporte'
       data-player='{$jugId}'
       data-fecha='{$fecha}'
       type='number'
       placeholder='$'
       value='".($aporte>0?$aporte:"")."'
       title='Aportó: " . number_format($aporteReal,0,',','.') . " COP'>

<span class='saldo-flag ".($flag ? "show" : "")."'>★</span>

<label class='chk-deuda-label ".($hayDeuda?"con-deuda":"")."'>
    <input type='checkbox'
           class='chk-deuda'
           data-player='{$jugId}'
           data-fecha='{$fecha}'
           ".($hayDeuda?"checked":"").">
  
</label>

    </div>
</td>";
    }

    // ======================
    // FECHA ESPECIAL (28)
    // ======================
    $diaEspecial = 28;
    $fechaEspecial = sprintf("%04d-%02d-%02d", $anio, $mes, $diaEspecial);

    $aporteEsp = get_aporte($conexion, $jugId, $fechaEspecial);
    $aporteEspReal = get_aporte_real($conexion, $jugId, $fechaEspecial);

    $hayDeudaEsp = isset($deudas_map[$jugId][$diaEspecial]);

    $total_jugador_mes += $aporteEsp;

    echo "
<td class='celda-dia'>
    <div class='aporte-wrapper'>
        <input class='cell-aporte'
               data-player='{$jugId}'
               data-fecha='{$fechaEspecial}'
               type='number'
               placeholder='$'
               value='".($aporteEsp>0?$aporteEsp:"")."'>

        <label class='chk-deuda-label ".($hayDeudaEsp?"con-deuda":"")."'>
            <input type='checkbox'
                   class='chk-deuda'
                   data-player='{$jugId}'
                   data-fecha='{$fechaEspecial}'
                   ".($hayDeudaEsp?"checked":"").">
            
        </label>
    </div>
</td>";

    // =====================
    // OTROS APORTES
    // =====================
    $otros = get_otros($conexion, $jugId, $mes, $anio);
    $tipos = [];
    $valor_otros = 0;

    foreach ($otros as $o) {
        $tipos[] = htmlspecialchars($o['tipo'])." (".number_format($o['valor'],0,',','.').")";
        $valor_otros += intval($o['valor']);
    }

    $total_jugador_mes += $valor_otros;
    $total_otros_global += $valor_otros;

    echo "<td>".(empty($tipos)?'':implode("<br>", $tipos))."</td>";
    echo "<td>".($valor_otros?number_format($valor_otros,0,',','.'):'')."</td>";
    echo "<td><strong>".number_format($total_jugador_mes,0,',','.')."</strong></td>";

    $saldoAcumulado = get_saldo_acumulado($conexion, $jugId, $mes, $anio);
    echo "<td><strong>".number_format($saldoAcumulado,0,',','.')."</strong></td>";

    echo "<td class='acciones'>
          <button class='btn-del-player' data-id='{$jugId}'>🗑️</button>
          </td>";

          echo "<td class='estado-deuda'>
        <label class='chk-deuda-global ".($tieneDeuda ? "con-deuda" : "")."'>
           
            ".($tieneDeuda ? "Debe: {$deudaDias} días" : "")."
        </label>
      </td>";

}

echo "</tbody>";

// =====================
// FOOTER
// =====================
echo "<tfoot><tr>";
echo "<td><strong>TOTAL DÍA</strong></td>";

foreach ($totales_por_dia as $td)
    echo "<td><strong>".number_format($td,0,',','.')."</strong></td>";

$fechaEspecial = sprintf("%04d-%02d-28", $anio, $mes);
$totEspecialRes = $conexion->query("
    SELECT IFNULL(SUM(LEAST(aporte_principal,2000)),0) AS t 
    FROM aportes 
    WHERE fecha='{$fechaEspecial}'
");
$totEspecial = intval($totEspecialRes->fetch_assoc()['t'] ?? 0);

echo "<td><strong>".number_format($totEspecial,0,',','.')."</strong></td>";

echo "<td><strong>TOTAL OTROS</strong></td>";
echo "<td><strong>".number_format($total_otros_global,0,',','.')."</strong></td>";

$totales_mes_actualizado = array_sum($totales_por_dia) + $totEspecial + $total_otros_global;
echo "<td><strong>".number_format($totales_mes_actualizado,0,',','.')."</strong></td>";

$totSaldoRes = $conexion->query("
    SELECT IFNULL(SUM(aporte_principal - LEAST(aporte_principal,2000)),0) AS t 
    FROM aportes 
    WHERE (YEAR(fecha) < $anio OR (YEAR(fecha) = $anio AND MONTH(fecha) <= $mes))
");
$totSaldo = intval($totSaldoRes->fetch_assoc()['t'] ?? 0);

echo "<td><strong>".number_format($totSaldo,0,',','.')."</strong></td>";

echo "<td></td>";
echo "</tr></tfoot>";

echo "</table></div>";
?>
