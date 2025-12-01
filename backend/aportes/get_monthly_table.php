<?php
include "../../conexion.php";

$mes = date("m");
$anio = date("Y");
$diasMes = cal_days_in_month(CAL_GREGORIAN, $mes, $anio);

$jugadores = $conexion->query("SELECT * FROM jugadores ORDER BY nombre ASC");

echo "<table class='monthly-table'>";
echo "<tr><th>Jugador</th>";

for ($d = 1; $d <= $diasMes; $d++) {
    echo "<th>$d</th>";
}

echo "<th>Total</th></tr>";

while ($j = $jugadores->fetch_assoc()) {
    $idJugador = $j["id"];

    echo "<tr>";
    echo "<td>{$j['nombre']}</td>";

    $totalJugador = 0;

    for ($d = 1; $d <= $diasMes; $d++) {
        $fecha = "$anio-$mes-" . str_pad($d, 2, "0", STR_PAD_LEFT);

        $sqlAporte = $conexion->prepare("
            SELECT aporte_principal, otro_aporte 
            FROM aportes 
            WHERE id_jugador = ? AND fecha = ?
        ");
        $sqlAporte->bind_param("is", $idJugador, $fecha);
        $sqlAporte->execute();
        $res = $sqlAporte->get_result()->fetch_assoc();

        $aporte = $res["aporte_principal"] ?? 0;
        $otro = $res["otro_aporte"] ?? 0;
        $diaTotal = $aporte + $otro;

        $totalJugador += $diaTotal;

        echo "<td>
            <input type='number' 
                   class='aporte-input' 
                   data-id='$idJugador'
                   data-fecha='$fecha'
                   data-campo='aporte_principal'
                   value='$aporte'
                   min='0'
            >
            <input type='number'
                   class='aporte-input'
                   data-id='$idJugador'
                   data-fecha='$fecha'
                   data-campo='otro_aporte'
                   value='$otro'
                   min='0'
            >
        </td>";
    }

    echo "<td><strong>$totalJugador</strong></td>";
    echo "</tr>";
}

echo "</table>";
