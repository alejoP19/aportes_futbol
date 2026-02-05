<?php
include "../../conexion.php";
require_once __DIR__ . "/../auth/auth.php";
protegerAdmin();

header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");
header("Expires: Sat, 01 Jan 2000 00:00:00 GMT");
header("Content-Type: text/html; charset=utf-8");

// --- Obtener mes y año ---
$mes  = isset($_GET['mes'])  ? intval($_GET['mes'])  : intval(date('n'));
$anio = isset($_GET['anio']) ? intval($_GET['anio']) : intval(date('Y'));

$TOPE = 3000;

// --- calcular días que son miércoles (3) o sábado (6)
$days = [];
$days_count = cal_days_in_month(CAL_GREGORIAN, $mes, $anio);
for ($d = 1; $d <= $days_count; $d++) {
    $date = sprintf("%04d-%02d-%02d", $anio, $mes, $d);
    $w = date('N', strtotime($date));
    if ($w == 3 || $w == 6) $days[] = $d;
}

// --- "Otro juego" SIEMPRE existe (1 sola columna extra)
function pick_default_otro_dia($days, $days_count) {
    // preferir 28 si NO es miércoles/sábado (o sea, no está en $days)
    if (!in_array(28, $days) && 28 <= $days_count) return 28;

    // si 28 es día normal, usar el primer día NO miércoles/sábado
    for ($d = 1; $d <= $days_count; $d++) {
        if (!in_array($d, $days)) return $d;
    }
    // fallback (no debería pasar)
    return 1;
}

// Permitir que el usuario cambie el día "otro" por GET ?otro=DD
$otroDia = isset($_GET['otro']) ? intval($_GET['otro']) : pick_default_otro_dia($days, $days_count);
if ($otroDia < 1 || $otroDia > $days_count) $otroDia = pick_default_otro_dia($days, $days_count);
// Validar que el otroDia NO sea miércoles/sábado:
if (in_array($otroDia, $days)) {
    $otroDia = pick_default_otro_dia($days, $days_count);
}

$colspanDias = count($days) + 1; // +1 por "Otro juego"

// --- obtener todos los jugadores (activos y eliminados)
$jug_res = $conexion->query("SELECT id, nombre, telefono, activo FROM jugadores ORDER BY nombre ASC");
$jugadores = [];
while ($r = $jug_res->fetch_assoc()) $jugadores[] = $r;

/* ===========================
   HELPERS
=========================== */
function get_aporte_cash_cap($conexion, $id_jugador, $fecha, $tope = 3000) {
    $stmt = $conexion->prepare("
        SELECT LEAST(IFNULL(aporte_principal,0), ?) AS total
        FROM aportes
        WHERE id_jugador=? AND fecha=?
        LIMIT 1
    ");
    $stmt->bind_param("iis", $tope, $id_jugador, $fecha);
    $stmt->execute();
    $res = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $res ? intval($res['total']) : 0;
}

function get_aporte_real($conexion, $id_jugador, $fecha) {
    $stmt = $conexion->prepare("SELECT aporte_principal FROM aportes WHERE id_jugador=? AND fecha=? LIMIT 1");
    $stmt->bind_param("is", $id_jugador, $fecha);
    $stmt->execute();
    $res = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $res ? intval($res['aporte_principal']) : 0;
}

function get_consumo_saldo_target($conexion, $id_jugador, $fecha) {
    // consumo aplicado a ESTE día (target)
    $stmt = $conexion->prepare("
        SELECT IFNULL(SUM(m.amount),0) AS c
        FROM aportes a
        INNER JOIN aportes_saldo_moves m ON m.target_aporte_id = a.id
        WHERE a.id_jugador=? AND a.fecha=?
    ");
    $stmt->bind_param("is", $id_jugador, $fecha);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return (int)($row['c'] ?? 0);
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

function get_saldo_acumulado($conexion, $id_jugador, $mes, $anio) {
    $fechaCorte = date('Y-m-t', strtotime("$anio-$mes-01"));

    $q1 = $conexion->prepare("
        SELECT IFNULL(SUM(GREATEST(aporte_principal - 3000, 0)), 0) AS excedente
        FROM aportes
        WHERE id_jugador = ?
          AND fecha <= ?
    ");
    $q1->bind_param("is", $id_jugador, $fechaCorte);
    $q1->execute();
    $excedente = (int)($q1->get_result()->fetch_assoc()['excedente'] ?? 0);
    $q1->close();

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

/* ===========================
   DEUDAS POR DÍA (mes actual)
=========================== */
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

/* ===========================
   TOTALES DE DEUDA ACUMULADA
=========================== */
$deudas_totales = [];
$resTot = $conexion->query("
    SELECT id_jugador, COUNT(*) AS total
    FROM deudas_aportes
    WHERE (YEAR(fecha) < $anio)
       OR (YEAR(fecha) = $anio AND MONTH(fecha) <= $mes)
    GROUP BY id_jugador
");
while ($row = $resTot->fetch_assoc()) {
    $deudas_totales[$row['id_jugador']] = intval($row['total']);
}

/* ===========================
   HTML
=========================== */
echo "<div class='monthly-sheet'>";
echo "<div class='tabla-scroll-sync'>";
echo "<div class='table-scroll'>";

$mesesEsp = [
  1=>"Enero",2=>"Febrero",3=>"Marzo",4=>"Abril",5=>"Mayo",6=>"Junio",
  7=>"Julio",8=>"Agosto",9=>"Septiembre",10=>"Octubre",11=>"Noviembre",12=>"Diciembre"
];

// Select de "Otro juego"
$opcionesOtro = [];
for ($d=1; $d<=$days_count; $d++) {
    if (!in_array($d, $days)) $opcionesOtro[] = $d;
}
$otroLabel = sprintf("%02d", $otroDia);

echo "
<div class='tabla-toolbar'>
  <div class='month-header'>
    Mes: <strong>{$mesesEsp[$mes]} $anio</strong>
  </div>

  <div style='display:flex; gap:10px; align-items:center; flex-wrap:wrap;'>
    <div class='buscador-jugadores'>
      <span class='icono-buscar'>🔍</span>
      <input type='text' id='searchJugador' placeholder='Buscar aportante…' autocomplete='off'>
      <button type='button' id='clearSearch' title='Limpiar'>✕</button>
    </div>

    <div class='otro-juego-picker' style='display:flex; gap:6px; align-items:center;'>
      <span>Otro juego:</span>
      <select id='selectOtroDia'>
";

foreach ($opcionesOtro as $dopt) {
    $sel = ($dopt == $otroDia) ? "selected" : "";
    echo "<option value='{$dopt}' {$sel}>Día {$dopt}</option>";
}

echo "
      </select>
    </div>
  </div>
</div>
";

echo "<table class='planilla'>";
echo "<thead>";
echo "<tr>";
echo "<th>Nombres</th>";
echo "<th colspan='{$colspanDias}' data-group='dias'>Días de los juegos</th>";
echo "<th colspan='2' data-group='otros'>Otros aportes</th>";
echo "<th data-group='total'>Total Mes</th>";
echo "<th data-group='saldo'>Saldo</th>";
echo "<th>Acciones</th>";
echo "<th>Deudas</th>";
echo "<th></th>";
echo "</tr>";

echo "<tr>";
echo "<th></th>";

foreach ($days as $d) echo "<th data-group='dias'>{$d}</th>";
// ✅ TH "Otro juego" con id + data-dia para que JS lo actualice sin recargar
echo "<th id='thOtroJuego' data-group='otro' data-dia='{$otroDia}'>Otro juego ({$otroLabel})</th>";


echo "<th data-group='otros'>Tipo</th>";
echo "<th data-group='otros'>Valor</th>";
echo "<th data-group='total'>Por Jugador</th>";
echo "<th data-group='saldo'>Tu Saldo</th>";
echo "<th>Editar / Eliminar</th>";
echo "<th>Tu Deuda</th>";
echo "<th>Teléfono</th>";
echo "</tr>";
echo "</thead>";

echo "<tbody>";

$totales_por_dia = array_fill(0, count($days), 0);
$total_otro_col = 0;
$total_otros_global = 0;
$total_saldo_global = 0;

foreach ($jugadores as $jug) {
    $jugId = intval($jug['id']);
    $deudaDias = $deudas_totales[$jugId] ?? 0;
    $tieneDeuda = $deudaDias > 0;

    $total_jugador_mes = 0;

    $claseEliminado = ($jug['activo'] == 0) ? "eliminado" : "";
    echo "<tr data-player='{$jugId}' class='{$claseEliminado}'>";
    echo "<td>".htmlspecialchars($jug['nombre'])."</td>";

    // DÍAS NORMALES (miércoles/sábado)
    foreach ($days as $idx => $d) {
        $fecha = sprintf("%04d-%02d-%02d", $anio, $mes, $d);

        $cashCap = get_aporte_cash_cap($conexion, $jugId, $fecha, $TOPE);
        $real    = get_aporte_real($conexion, $jugId, $fecha);
        $consumo = get_consumo_saldo_target($conexion, $jugId, $fecha);

        // efectivo informativo (para sumar bien el mes)
        $efectivo = min($cashCap + $consumo, $TOPE);

        $hayDeuda = isset($deudas_map[$jugId][$d]);

        $total_jugador_mes += $efectivo;
        $totales_por_dia[$idx] += $efectivo;

        $flag = ($real > $TOPE) ? "★" : "";

        $tooltip = "";
        $excedenteAttr = "";
        if ($real > $TOPE) {
            $excedenteAttr = "aporte-excedente";
            $tooltip = "data-real='{$real}' title='Aportó ".number_format($real,0,',','.')."'";
        }

      // NO mostrar badge, solo dejar data-consumo para tooltip/marker
$saldoAttr = ($consumo > 0) ? " data-saldo-uso='{$consumo}' " : "";


        echo "
<td class='celda-dia {$excedenteAttr}' {$tooltip} {$saldoAttr}>
  <div class='aporte-wrapper'>
    <input class='cell-aporte'
           data-player='{$jugId}'
           data-fecha='{$fecha}'
           type='number'
           placeholder='$'
           value='".($cashCap>0?$cashCap:"")."'>
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

    // OTRO JUEGO (día NO miércoles/sábado)
    $fechaOtro = sprintf("%04d-%02d-%02d", $anio, $mes, $otroDia);

    $cashCapO = get_aporte_cash_cap($conexion, $jugId, $fechaOtro, $TOPE);
    $realO    = get_aporte_real($conexion, $jugId, $fechaOtro);
    $consumoO = get_consumo_saldo_target($conexion, $jugId, $fechaOtro);
    $efectivoO = min($cashCapO + $consumoO, $TOPE);

    $hayDeudaOtro = isset($deudas_map[$jugId][$otroDia]);

    $total_jugador_mes += $efectivoO;
    $total_otro_col += $efectivoO;

    $flagO = ($realO > $TOPE) ? "★" : "";
    $tooltipO = "";
    $excedenteAttrO = "";
    if ($realO > $TOPE) {
        $excedenteAttrO = "aporte-excedente";
        $tooltipO = "data-real='{$realO}' title='Aportó ".number_format($realO,0,',','.')."'";
    }

   // NO mostrar badge, solo dejar data-consumo para tooltip/marker
$saldoAttrO = ($consumoO > 0) ? " data-saldo-uso='{$consumoO}' " : "";




    echo "
<td class='celda-dia {$excedenteAttrO}' {$tooltipO} {$saldoAttrO}>
  <div class='aporte-wrapper'>
    <input class='cell-aporte'
           data-player='{$jugId}'
           data-fecha='{$fechaOtro}'
           type='number'
           placeholder='$'
           value='".($cashCapO>0?$cashCapO:"")."'>
    <span class='saldo-flag ".($flagO ? "show" : "")."'>★</span>

    <label class='chk-deuda-label ".($hayDeudaOtro ? "con-deuda" : "")."'>
      <input type='checkbox'
             class='chk-deuda'
             data-player='{$jugId}'
             data-fecha='{$fechaOtro}'
             ".($hayDeudaOtro ? "checked" : "").">
    </label>
  </div>
</td>";

    // OTROS APORTES
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
    $total_saldo_global += $saldoAcumulado;

    $nombreSafe   = htmlspecialchars($jug['nombre'], ENT_QUOTES, 'UTF-8');
    $telefonoSafe = htmlspecialchars($jug['telefono'] ?? '', ENT_QUOTES, 'UTF-8');

    echo "<td class='acciones'>
            <button class='btn-edit-player'
                    data-id='{$jugId}'
                    data-nombre='{$nombreSafe}'
                    data-telefono='{$telefonoSafe}'
                    title='Editar aportante'>✏️</button>
            <button class='btn-del-player'
                    data-id='{$jugId}'
                    title='Eliminar aportante'>🗑️</button>
          </td>";

    echo "<td class='estado-deuda'>
            <label class='chk-deuda-global ".($tieneDeuda ? "con-deuda" : "")."'>
              ".($tieneDeuda ? "Debe: {$deudaDias} días" : "")."
            </label>
          </td>";

    $telefonoView = $jug['telefono'] ? htmlspecialchars($jug['telefono']) : "";
    echo "<td class='telefono-cell' data-full='{$telefonoView}'>{$telefonoView}</td>";

    echo "</tr>";
}

echo "</tbody>";

// FOOTER
echo "<tfoot><tr>";
echo "<td><strong>TOTAL DÍA</strong></td>";

foreach ($totales_por_dia as $td) {
    echo "<td><strong>".number_format($td,0,',','.')."</strong></td>";
}

// Total de la columna "Otro juego"
echo "<td><strong>".number_format($total_otro_col,0,',','.')."</strong></td>";

echo "<td><strong>TOTAL OTROS</strong></td>";
echo "<td><strong>".number_format($total_otros_global,0,',','.')."</strong></td>";

$totales_mes_actualizado = array_sum($totales_por_dia) + $total_otro_col + $total_otros_global;
echo "<td><strong>".number_format($totales_mes_actualizado,0,',','.')."</strong></td>";

echo "<td><strong>".number_format($total_saldo_global,0,',','.')."</strong></td>";
echo "<td></td><td></td><td></td>";
echo "</tr></tfoot>";

echo "</table>";
echo "</div></div></div>";
?>
