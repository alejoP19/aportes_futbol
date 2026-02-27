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
function pick_default_otro_dia($days, $days_count)
{
    if (!in_array(28, $days) && 28 <= $days_count) return 28;

    for ($d = 1; $d <= $days_count; $d++) {
        if (!in_array($d, $days)) return $d;
    }
    return 1;
}

// Permitir que el usuario cambie el día "otro" por GET ?otro=DD
$otroDia = isset($_GET['otro']) ? intval($_GET['otro']) : pick_default_otro_dia($days, $days_count);
if ($otroDia < 1 || $otroDia > $days_count) $otroDia = pick_default_otro_dia($days, $days_count);

// Validar que el otroDia NO sea miércoles/sábado
if (in_array($otroDia, $days, true)) {
    $otroDia = pick_default_otro_dia($days, $days_count);
}

$colspanDias = count($days) + 1; // +1 por "Otro juego"

// ✅ Todos los días NO miércoles/sábado del mes (posibles "Otro juego")
$otrosDias = [];
for ($d = 1; $d <= $days_count; $d++) {
    if (!in_array($d, $days, true)) $otrosDias[] = $d;
}

// --- obtener todos los jugadores (activos y eliminados)
$fechaCorteMes = date('Y-m-t', strtotime("$anio-$mes-01"));
$jug_res = $conexion->query("
    SELECT id, nombre, telefono, activo, fecha_baja
    FROM jugadores
    WHERE
      activo = 1
      OR (activo = 0 AND (fecha_baja IS NULL OR fecha_baja > '$fechaCorteMes'))
    ORDER BY nombre ASC
");

$jugadores = [];
while ($r = $jug_res->fetch_assoc()) $jugadores[] = $r;

/* ===========================
   HELPERS
=========================== */
function get_aporte_cash_cap($conexion, $id_jugador, $fecha, $tope = 3000)
{
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

function get_aporte_real($conexion, $id_jugador, $fecha)
{
    $stmt = $conexion->prepare("SELECT aporte_principal FROM aportes WHERE id_jugador=? AND fecha=? LIMIT 1");
    $stmt->bind_param("is", $id_jugador, $fecha);
    $stmt->execute();
    $res = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $res ? intval($res['aporte_principal']) : 0;
}

function get_consumo_saldo_target($conexion, $id_jugador, $fecha)
{
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

function get_otros($conexion, $id_jugador, $mes, $anio)
{
    $stmt = $conexion->prepare("SELECT tipo, valor FROM otros_aportes WHERE id_jugador=? AND mes=? AND anio=?");
    $stmt->bind_param("iii", $id_jugador, $mes, $anio);
    $stmt->execute();
    $res = $stmt->get_result();
    $list = [];
    while ($row = $res->fetch_assoc()) $list[] = $row;
    $stmt->close();
    return $list;
}

function get_saldo_acumulado($conexion, $id_jugador, $mes, $anio)
{
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
   FOOTER HELPERS (SQL)
   - Sirven para calcular el TFOOT por fecha (incluye eliminados)
   - No depende del array $jugadores
=========================== */
function total_efectivo_registrados_por_fecha($conexion, $fecha, $TOPE = 3000)
{
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

function total_esporadicos_por_fecha($conexion, $fecha)
{
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
   LISTA DE FECHAS DE DEUDAS (hasta el mes seleccionado)
=========================== */
$deudas_lista = [];
$fechaCorteDeuda = date('Y-m-t', strtotime("$anio-$mes-01"));

$resLista = $conexion->query("
    SELECT id_jugador, fecha
    FROM deudas_aportes
    WHERE fecha <= '$fechaCorteDeuda'
    ORDER BY fecha ASC
");

while ($row = $resLista->fetch_assoc()) {
    $jid = (int)$row['id_jugador'];
    $deudas_lista[$jid][] = date("d-m-Y", strtotime($row['fecha']));
}

/* ===========================
   HTML
=========================== */
$mesesEsp = [
    1 => "Enero", 2 => "Febrero", 3 => "Marzo", 4 => "Abril",
    5 => "Mayo", 6 => "Junio", 7 => "Julio", 8 => "Agosto",
    9 => "Septiembre", 10 => "Octubre", 11 => "Noviembre", 12 => "Diciembre"
];

// Select de "Otro juego"
$opcionesOtro = [];
for ($d = 1; $d <= $days_count; $d++) {
    if (!in_array($d, $days, true)) $opcionesOtro[] = $d;
}
$otroLabel = sprintf("%02d", $otroDia);

echo "
  <div class='month-header'>
    Mes: <strong>{$mesesEsp[$mes]} $anio</strong>
    <div>
      <div class='buscador-jugadores'>
        <span class='icono-buscar'>🔍</span>
        <input type='text' id='searchJugador' placeholder='Buscar aportante…' autocomplete='off'>
        <button type='button' id='clearSearch' title='Limpiar'>✕</button>
      </div>
    </div>

    <div class='otro-juego-picker'>
      <h6>Otro juego</h6>
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
";

echo "<table class='planilla'>";
echo "<thead>";
echo "<tr class='header-tr-one'>";
echo "<th>Nombres</th>";
echo "<th colspan='{$colspanDias}' data-group='dias'>Días de los juegos</th>";
echo "<th colspan='2' data-group='otros'>Otros aportes</th>";
echo "<th data-group='total'>Total Mes</th>";
echo "<th data-group='saldo'>Saldo</th>";
echo "<th>Acciones</th>";
echo "<th>Deudas</th>";
echo "<th></th>";
echo "</tr>";

echo "<tr class='header-tr-two'>";
echo "<th></th>";

foreach ($days as $d) echo "<th data-group='dias'>{$d}</th>";
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

// ✅ Inicializaciones correctas
$totales_por_dia = array_fill(0, count($days), 0);
$total_otro_col_all = 0;       // suma REAL de todos los otros días (no mié/sáb)
$total_otro_col_visible = 0;   // suma SOLO del día seleccionado en "Otro juego"
$total_otros_global = 0;
$total_saldo_global = 0;



/* ===========================
   ESPORÁDICOS (SUMAS POR FECHA)
   - solo afectan TFOOT (no por jugador)
=========================== */
$esp_por_fecha = [];
$stmt = $conexion->prepare("
  SELECT fecha, IFNULL(SUM(aporte_principal),0) AS s
  FROM aportes
  WHERE tipo_aporte='esporadico'
    AND YEAR(fecha)=?
    AND MONTH(fecha)=?
  GROUP BY fecha
");
$stmt->bind_param("ii", $anio, $mes);
$stmt->execute();
$res = $stmt->get_result();
while($r = $res->fetch_assoc()){
  $esp_por_fecha[$r['fecha']] = (int)$r['s'];
}
$stmt->close();

/* sumar esporádicos a:
   - totales_por_dia (solo fechas mié/sáb)
   - total_otro_col_all (fechas NO mié/sáb)
*/
$esp_total_mes = 0;
$esp_total_otro = 0;

foreach($esp_por_fecha as $f => $s){
  $esp_total_mes += $s;

  $dia = (int)date("j", strtotime($f));

  // si es mié/sáb (está en $days), súmalo al índice de ese día
  $idx = array_search($dia, $days, true);
  if ($idx !== false) {
    $totales_por_dia[$idx] += $s;
  } else {
    // si NO es mié/sáb, cuenta como "otro juego" (todos)
    $esp_total_otro += $s;
  }
}

// esto es CLAVE: el total "otro juego (todos)" ahora incluye esporádicos no mié/sáb
$total_otro_col_all += $esp_total_otro;


foreach ($jugadores as $jug) {
    $jugId = intval($jug['id']);
    $deudaDias = $deudas_totales[$jugId] ?? 0;
    $tieneDeuda = $deudaDias > 0;

    $total_jugador_mes = 0;

    $claseEliminado = ($jug['activo'] == 0) ? "eliminado" : "";
    echo "<tr data-player='{$jugId}' class='{$claseEliminado}'>";
    echo "<td>" . htmlspecialchars($jug['nombre']) . "</td>";

    // DÍAS NORMALES (miércoles/sábado)
    foreach ($days as $idx => $d) {
        $fecha = sprintf("%04d-%02d-%02d", $anio, $mes, $d);

        $cashCap = get_aporte_cash_cap($conexion, $jugId, $fecha, $TOPE);
        $real    = get_aporte_real($conexion, $jugId, $fecha);
        $consumo = get_consumo_saldo_target($conexion, $jugId, $fecha);

        $efectivo = min($cashCap + $consumo, $TOPE);

        $hayDeuda = isset($deudas_map[$jugId][$d]);

        $total_jugador_mes += $efectivo;
        $totales_por_dia[$idx] += $efectivo;

        $flag = ($real > $TOPE) ? "★" : "";

        $tooltip = "";
        $excedenteAttr = "";
        if ($real > $TOPE) {
            $excedenteAttr = "aporte-excedente";
            $tooltip = "data-real='{$real}' title='Aportó " . number_format($real, 0, ',', '.') . "'";
        }

        $saldoAttr = ($consumo > 0) ? " data-saldo-uso='{$consumo}' " : "";

        echo "
<td class='celda-dia {$excedenteAttr}' {$tooltip} {$saldoAttr}>
  <div class='aporte-wrapper'>
    <input class='cell-aporte'
           data-player='{$jugId}'
           data-fecha='{$fecha}'
           type='number'
           placeholder='$'
           value='" . ($efectivo > 0 ? $efectivo : "") . "'>
    <span class='saldo-flag " . ($flag ? "show" : "") . "'>★</span>
    <span class='saldo-uso-flag " . ($consumo > 0 ? "show" : "") . "'>✚</span>

    <label class='chk-deuda-label " . ($hayDeuda ? "con-deuda" : "") . "'>
      <input type='checkbox'
             class='chk-deuda'
             data-player='{$jugId}'
             data-fecha='{$fecha}'
             " . ($hayDeuda ? "checked" : "") . ">
    </label>
  </div>
</td>";
    }

    // ===========================
    // OTRO JUEGO:
    // - Columna visible: SOLO $otroDia
    // - Total mes: suma TODOS los días NO mié/sáb
    // ===========================
    $efectivoO_selected = 0;
    $totalOtrosJuegosJugador = 0;

    // capturas para tooltip/flags del día seleccionado
    $realO_sel = 0;
    $consumoO_sel = 0;

    foreach ($otrosDias as $dOther) {
        $fechaX = sprintf("%04d-%02d-%02d", $anio, $mes, $dOther);

        $cashCapX = get_aporte_cash_cap($conexion, $jugId, $fechaX, $TOPE);
        $realX    = get_aporte_real($conexion, $jugId, $fechaX);
        $consumoX = get_consumo_saldo_target($conexion, $jugId, $fechaX);

        $efectivoX = min($cashCapX + $consumoX, $TOPE);

        $totalOtrosJuegosJugador += $efectivoX;
        $total_otro_col_all      += $efectivoX;

        if ($dOther === $otroDia) {
            $efectivoO_selected = $efectivoX;
            $total_otro_col_visible += $efectivoX;

            $realO_sel = $realX;
            $consumoO_sel = $consumoX;
        }
    }

    // ✅ total jugador incluye TODOS los otros días, aunque no se vean
    $total_jugador_mes += $totalOtrosJuegosJugador;

    // imprimir celda visible del día seleccionado
    $fechaOtro = sprintf("%04d-%02d-%02d", $anio, $mes, $otroDia);
    $hayDeudaOtro = isset($deudas_map[$jugId][$otroDia]);

    $flagO = ($realO_sel > $TOPE) ? "★" : "";
    $tooltipO = "";
    $excedenteAttrO = "";
    if ($realO_sel > $TOPE) {
        $excedenteAttrO = "aporte-excedente";
        $tooltipO = "data-real='{$realO_sel}' title='Aportó " . number_format($realO_sel, 0, ',', '.') . "'";
    }
    $saldoAttrO = ($consumoO_sel > 0) ? " data-saldo-uso='{$consumoO_sel}' " : "";

    echo "
<td class='celda-dia {$excedenteAttrO}' {$tooltipO} {$saldoAttrO}>
  <div class='aporte-wrapper'>
    <input class='cell-aporte'
           data-player='{$jugId}'
           data-fecha='{$fechaOtro}'
           type='number'
           placeholder='$'
           value='" . ($efectivoO_selected > 0 ? $efectivoO_selected : "") . "'>
    <span class='saldo-flag " . ($flagO ? "show" : "") . "'>★</span>
    <span class='saldo-uso-flag " . ($consumoO_sel > 0 ? "show" : "") . "'>✚</span>

    <label class='chk-deuda-label " . ($hayDeudaOtro ? "con-deuda" : "") . "'>
      <input type='checkbox'
             class='chk-deuda'
             data-player='{$jugId}'
             data-fecha='{$fechaOtro}'
             " . ($hayDeudaOtro ? "checked" : "") . ">
    </label>
  </div>
</td>";

    // OTROS APORTES
    $otros = get_otros($conexion, $jugId, $mes, $anio);
    $tipos = [];
    $valor_otros = 0;

    foreach ($otros as $o) {
        $tipos[] = htmlspecialchars($o['tipo']) . " (" . number_format($o['valor'], 0, ',', '.') . ")";
        $valor_otros += intval($o['valor']);
    }

    $total_jugador_mes += $valor_otros;
    $total_otros_global += $valor_otros;

    echo "<td>" . (empty($tipos) ? '' : implode("<br>", $tipos)) . "</td>";
    echo "<td>" . ($valor_otros ? number_format($valor_otros, 0, ',', '.') : '') . "</td>";
    echo "<td><strong>" . number_format($total_jugador_mes, 0, ',', '.') . "</strong></td>";

    $saldoAcumulado = get_saldo_acumulado($conexion, $jugId, $mes, $anio);
    echo "<td><strong>" . number_format($saldoAcumulado, 0, ',', '.') . "</strong></td>";
    $total_saldo_global += $saldoAcumulado;

    $nombreSafe   = htmlspecialchars($jug['nombre'], ENT_QUOTES, 'UTF-8');
    $telefonoSafe = htmlspecialchars($jug['telefono'] ?? '', ENT_QUOTES, 'UTF-8');

    echo "<td class='acciones'>
            <div class='acciones-buttons'>
                <button class='btn-edit-player'
                        data-id='{$jugId}'
                        data-nombre='{$nombreSafe}'
                        data-telefono='{$telefonoSafe}'
                        title='Editar aportante'>✏️</button>
                <button class='btn-del-player'
                        data-id='{$jugId}'
                        title='Eliminar aportante'>🗑️</button>
            </div>
          </td>";

    $listaFechas = $deudas_lista[$jugId] ?? [];

    echo "<td class='estado-deuda'>
            <label class='chk-deuda-global " . ($tieneDeuda ? "con-deuda" : "") . "'>";
    if ($tieneDeuda) {
        echo "Deudas: {$deudaDias}";
        if (!empty($listaFechas)) {
            echo "<div class='deuda-desde' style='font-size:12px; color:#2e8b57; margin-top:4px;'>
                    " . implode("<br>", array_map("htmlspecialchars", $listaFechas)) . "
                  </div>";
        }
    }
    echo "  </label>
          </td>";

    $telefonoView = $jug['telefono'] ? htmlspecialchars($jug['telefono']) : "";
    echo "<td class='telefono-cell' data-full='{$telefonoView}'>{$telefonoView}</td>";

    echo "</tr>";
}

echo "</tbody>";


// ✅ TOTAL MES REAL: mié/sáb + TODOS los otros días + otros aportes
$totales_mes_actualizado = array_sum($totales_por_dia) + $total_otro_col_all + $total_otros_global;

/* ==========================================================
   ✅ FOOTER REAL POR SQL (INCLUYE ELIMINADOS)
   - Tu tabla NO muestra eliminados, pero el TFOOT debe sumar TODO lo del mes.
   - Calcula por fecha:
       total_dia = registrados_efectivo + esporadicos
       total_otro = suma de TODOS los dias NO mié/sáb (registrados + esporadicos)
       total_otros_aportes = otros_aportes + esporadico_otro
========================================================== */

// 1) Totales por cada día mié/sáb (registrados + esporádicos)
$totales_por_dia_footer = [];
foreach ($days as $d) {
    $f = sprintf("%04d-%02d-%02d", $anio, $mes, $d);
    $totales_por_dia_footer[] =
        total_efectivo_registrados_por_fecha($conexion, $f, $TOPE)
        + total_esporadicos_por_fecha($conexion, $f);
}

// 2) Total OTRO JUEGO (TODOS los días NO mié/sáb)
$total_otro_footer = 0;
foreach ($otrosDias as $dOther) {
    $f = sprintf("%04d-%02d-%02d", $anio, $mes, $dOther);
    $total_otro_footer +=
        total_efectivo_registrados_por_fecha($conexion, $f, $TOPE)
        + total_esporadicos_por_fecha($conexion, $f);
}

// 3) Total "Otros aportes" del mes (otros_aportes + esporadico_otro)
$total_otros_aportes_footer = 0;

// otros_aportes (tabla aparte)
$rowOA = $conexion->query("
    SELECT IFNULL(SUM(valor),0) AS s
    FROM otros_aportes
    WHERE mes=$mes AND anio=$anio
")->fetch_assoc();
$total_otros_aportes_footer += (int)($rowOA['s'] ?? 0);

// esporadico_otro (guardado en aportes)
$rowEO = $conexion->query("
    SELECT IFNULL(SUM(aporte_principal),0) AS s
    FROM aportes
    WHERE tipo_aporte='esporadico_otro'
      AND YEAR(fecha)=$anio AND MONTH(fecha)=$mes
")->fetch_assoc();
$total_otros_aportes_footer += (int)($rowEO['s'] ?? 0);

// 4) Total mes REAL (para footer): días mié/sáb + otros días + otros aportes
$totales_mes_footer =
    array_sum($totales_por_dia_footer)
    + $total_otro_footer
    + $total_otros_aportes_footer;

// 5) Saldo total (informativo) — aquí puedes dejar tu cálculo anterior si quieres,
//    pero ojo: el saldo NO es "recaudo"; es dinero ya contado en aportes anteriores.
//    (Si lo quieres mostrar igual, ok. Solo que no se suma al "total mes" de aportes.)
/* $total_saldo_global ya lo calculabas por jugador mostrado.
   Eso NO incluye eliminados. Si quieres saldo total real (incluye eliminados),
   lo mejor es calcularlo por SQL (similar a tu get_total.php).
*/
$saldo_total_footer = 0;
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
$saldo_total_footer = (int)($rowSaldo['saldo'] ?? 0);

// === PRINT FOOTER
echo "<tfoot><tr>";
echo "<td><strong>TOTAL DÍA</strong></td>";

foreach ($totales_por_dia_footer as $td) {
    echo "<td><strong>" . number_format($td, 0, ',', '.') . "</strong></td>";
}

// total otro (todos)
echo "<td style='background:#e8f7ef;'>
        <strong>" . number_format($total_otro_footer, 0, ',', '.') . "</strong>
      </td>";

// col "Tipo" vacía
echo "<td></td>";

// total otros aportes
echo "<td style='background:#eef3ff;'>
        <strong>" . number_format($total_otros_aportes_footer, 0, ',', '.') . "</strong>
      </td>";

// total mes
echo "<td style='background:#dff5e3;'>
        <strong>" . number_format($totales_mes_footer, 0, ',', '.') . "</strong>
      </td>";

// saldo
echo "<td><strong>" . number_format($saldo_total_footer, 0, ',', '.') . "</strong></td>";

// acciones/deudas/tel vacías
echo "<td></td><td></td><td></td>";
echo "</tr>";

// Fila explicativa
echo "<tr class='tfoot-info'>
  <td colspan='" . (count($days)+6) . "'></td>
  <td colspan='3' style='font-size:12px; color:#444; padding-top:6px;'>
    ✔ Otros juegos del mes (registrados + esporádicos) suman: <strong>$ "
    . number_format($total_otro_footer,0,',','.')
    . "</strong>
  </td>
</tr>";

echo "</tfoot>";

echo "</table>";