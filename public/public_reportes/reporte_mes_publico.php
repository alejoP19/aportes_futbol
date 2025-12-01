<?php
// public/public_reportes/reporte_mes_publico.php
include __DIR__ . "/../../conexion.php";
header("Content-Type: text/html; charset=utf-8");

// Obtener mes/año (desde $_GET o por defecto)
$mes  = isset($_GET['mes'])  ? intval($_GET['mes'])  : intval(date("n"));
$anio = isset($_GET['anio']) ? intval($_GET['anio']) : intval(date("Y"));

// Validaciones básicas
if ($mes < 1 || $mes > 12) $mes = intval(date('n'));
if ($anio < 1900 || $anio > 9999) $anio = intval(date('Y'));

$mesName = date('F', mktime(0, 0, 0, $mes, 1));

// días miércoles (3) y sábados (6)
$days = [];
$days_count = cal_days_in_month(CAL_GREGORIAN, $mes, $anio);
for ($d = 1; $d <= $days_count; $d++) {
    $w = date('N', strtotime(sprintf("%04d-%02d-%02d", $anio, $mes, $d)));
    if ($w == 3 || $w == 6) $days[] = $d;
}

// jugadores activos + eliminados
$jug_res = $conexion->query("
    SELECT id, nombre FROM jugadores
    UNION ALL
    SELECT id, nombre FROM jugadores_eliminados
    ORDER BY nombre ASC
");

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
    $stmt = $conexion->prepare("
        SELECT tipo, valor 
        FROM otros_aportes 
        WHERE id_jugador=? AND mes=? AND anio=?
    ");
    $stmt->bind_param("iii", $id_jugador, $mes, $anio);
    $stmt->execute();
    $res = $stmt->get_result();

    $list = [];
    while ($row = $res->fetch_assoc()) $list[] = $row;
    return $list;
}

function get_obs($conexion, $mes, $anio) {
    $stmt = $conexion->prepare("SELECT texto FROM gastos_observaciones WHERE mes=? AND anio=? LIMIT 1");
    $stmt->bind_param("ii", $mes, $anio);
    $stmt->execute();
    $r = $stmt->get_result()->fetch_assoc();
    return $r['texto'] ?? '';
}

$total_mes_global = 0;
$total_otros_global = 0;

?>
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<title>Reporte mensual - <?= htmlspecialchars($mesName . " " . $anio) ?></title>
<style>
body { font-family: DejaVu Sans, sans-serif; font-size: 12px; }
table { width: 100%; border-collapse: collapse; }
th { background: #2f6b5a; color: white; padding: 5px; border: 1px solid #ccc; }
td { padding: 5px; border: 1px solid #ccc; }
.section-title { font-size: 16px; margin: 10px 0; }
.total-box { margin-top: 20px; padding: 10px; background: #eee; border-radius: 4px; }
.page-break { page-break-after: always; }
</style>
</head>
<body>

<?php
// totales por día
$totalesDias = [];
foreach ($days as $d) {
    $fecha = sprintf("%04d-%02d-%02d", $anio, $mes, $d);
    $q = $conexion->query("SELECT COALESCE(SUM(aporte_principal),0) AS s FROM aportes WHERE fecha='$fecha'");
    $totalesDias[$d] = intval($q->fetch_assoc()['s'] ?? 0);
}
?>

<div class="section-title"><strong>Aportantes y Aportes Diarios</strong></div>

<table>
<thead>
<tr>
    <th style="text-align:center;">Jugador</th>
    <?php foreach ($days as $d): ?>
        <th><?= $d ?></th>
    <?php endforeach; ?>
    <th>Otros</th>
    <th>Total jugador</th>
</tr>
</thead>
<tbody>

<?php foreach ($jugadores as $jug): ?>
<tr>
    <td><?= htmlspecialchars($jug['nombre']) ?></td>

    <?php
        $totalJugador = 0;
        foreach ($days as $d) {
            $fecha = sprintf("%04d-%02d-%02d", $anio, $mes, $d);
            $ap = get_aporte($conexion, $jug['id'], $fecha);
            $totalJugador += $ap;
            echo "<td>" . ($ap ? number_format($ap, 0, ',', '.') : "") . "</td>";
        }

        $otros = get_otros($conexion, $jug['id'], $mes, $anio);
        $otros_html = [];
        $otros_val = 0;

        foreach ($otros as $o) {
            $otros_html[] = htmlspecialchars($o['tipo']) . " / " . number_format($o['valor'], 0, ',', '.');
            $otros_val += intval($o['valor']);
        }

        $totalJugador += $otros_val;
        $total_mes_global += $totalJugador;
        $total_otros_global += $otros_val;
    ?>

    <td><?= $otros_html ? implode("<br>", $otros_html) : "" ?></td>
    <td><strong><?= number_format($totalJugador, 0, ',', '.') ?></strong></td>
</tr>
<?php endforeach; ?>

<tr style="background:#b4e7c7; font-weight:bold;">
    <td style="text-align:center;">Total por día</td>
    <?php foreach ($days as $d): ?>
        <td><?= number_format($totalesDias[$d] ?? 0, 0, ',', '.') ?></td>
    <?php endforeach; ?>
    <td></td>
    <td><strong><?= number_format($total_mes_global - $total_otros_global, 0, ',', '.') ?></strong></td>
</tr>

</tbody>
</table>

<div class="page-break"></div>

<div class="section-title"><strong>Otros aportes (resumen)</strong></div>

<table>
<thead>
<tr>
    <th>Jugador</th>
    <th>Tipo</th>
    <th>Valor</th>
</tr>
</thead>
<tbody>

<?php
$stmt = $conexion->prepare("
    SELECT o.tipo,o.valor,j.nombre
    FROM otros_aportes o
    JOIN jugadores j ON j.id=o.id_jugador
    WHERE o.mes=? AND o.anio=?
    ORDER BY j.nombre
");
$stmt->bind_param("ii", $mes, $anio);
$stmt->execute();
$res = $stmt->get_result();

while ($row = $res->fetch_assoc()) {
    echo "<tr>
        <td>" . htmlspecialchars($row['nombre']) . "</td>
        <td>" . htmlspecialchars($row['tipo']) . "</td>
        <td>" . number_format($row['valor'], 0, ',', '.') . "</td>
    </tr>";
}
?>

</tbody>
<tfoot>
<tr>
    <td colspan="2" style="text-align:right;"><strong>Total otros:</strong></td>
    <td><strong><?= number_format($total_otros_global, 0, ',', '.') ?></strong></td>
</tr>
</tfoot>
</table>

<div>
    <h4>Observaciones</h4>
    <div><?= nl2br(htmlspecialchars(get_obs($conexion, $mes, $anio))) ?></div>
</div>

<div class="total-box">
<strong>Total ingresado en <?= htmlspecialchars($mesName . " " . $anio) ?>:</strong>
<span style="float:right;"><strong><?= number_format($total_mes_global, 0, ',', '.') ?></strong></span>
</div>

</body>
</html>
