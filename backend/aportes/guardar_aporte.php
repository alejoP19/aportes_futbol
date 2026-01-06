<?php
header("Content-Type: application/json");
include "../../conexion.php";

/* ======================================================
   SALDO HASTA UN MES/AÑO (corte al último día del mes)
   saldo = (excedente generado hasta corte) - (consumo hasta corte)
   excedente = SUM(max(aporte_principal - 2000, 0))
   consumo   = SUM(amount) en aportes_saldo_moves con fecha_consumo <= corte
   ====================================================== */
function get_saldo_acumulado_hasta_mes($conexion, $id_jugador, $mes, $anio) {
    $fechaCorte = date('Y-m-t', strtotime("$anio-$mes-01"));

    // Excedente generado hasta la fecha de corte
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

    // Consumo de saldo hasta la fecha de corte (por fecha_consumo real)
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

/* ======================================================
   DISPONIBLE DE UNA FILA "SOURCE" (EXCEDENTE NO CONSUMIDO)
   disponible_source = (aporte_principal - 2000) - sum(moves.amount de ese source)
   ====================================================== */
function get_disponible_source($conexion, $source_id) {
    $q = $conexion->prepare("
        SELECT
          GREATEST(a.aporte_principal - 2000, 0) - IFNULL(SUM(m.amount), 0) AS disponible
        FROM aportes a
        LEFT JOIN aportes_saldo_moves m ON m.source_aporte_id = a.id
        WHERE a.id = ?
        GROUP BY a.id
        LIMIT 1
    ");
    $q->bind_param("i", $source_id);
    $q->execute();
    $row = $q->get_result()->fetch_assoc();
    $q->close();
    return (int)($row['disponible'] ?? 0);
}

$input = file_get_contents("php://input");
$data  = json_decode($input, true);

$id_jugador = (int)($data['id_jugador'] ?? 0);
$fecha      = (string)($data['fecha'] ?? '');
$valor      = array_key_exists('valor', $data) ? $data['valor'] : null;

if (!$id_jugador || !$fecha) {
    echo json_encode(['ok' => false, 'msg' => 'datos invalidos']);
    exit;
}

// Normalizar valor
if ($valor === "" || $valor === null) $valor = null;

// Validar fecha
$dt = strtotime($fecha);
if ($dt === false) {
    echo json_encode(['ok' => false, 'msg' => 'fecha_invalida']);
    exit;
}

$mes  = (int)date('n', $dt);
$anio = (int)date('Y', $dt);

$conexion->begin_transaction();

try {

    /* ======================================================
       1) Si valor es null => BORRAR aporte + borrar movimientos destino
       ====================================================== */
    if ($valor === null) {

        // Buscar id del aporte (si existe)
        $sel = $conexion->prepare("SELECT id FROM aportes WHERE id_jugador=? AND fecha=? LIMIT 1");
        $sel->bind_param("is", $id_jugador, $fecha);
        $sel->execute();
        $row = $sel->get_result()->fetch_assoc();
        $sel->close();

        if (!$row) {
            $conexion->commit();
            echo json_encode(['ok' => true, 'action' => 'deleted', 'msg' => 'no_row']);
            exit;
        }

        $target_id = (int)$row['id'];

        // Borrar movimientos que consumieron saldo para este día
        $delMoves = $conexion->prepare("DELETE FROM aportes_saldo_moves WHERE target_aporte_id=?");
        $delMoves->bind_param("i", $target_id);
        $delMoves->execute();
        $delMoves->close();

        // Borrar el aporte
        $del = $conexion->prepare("DELETE FROM aportes WHERE id=?");
        $del->bind_param("i", $target_id);
        $del->execute();
        $del->close();

        $conexion->commit();

        $saldo_actual = get_saldo_acumulado_hasta_mes($conexion, $id_jugador, $mes, $anio);

        echo json_encode([
            'ok' => true,
            'action' => 'deleted',
            'saldo' => $saldo_actual
        ]);
        exit;
    }

    /* ======================================================
       2) INSERT/UPDATE del aporte (guardar el valor real escrito)
       ====================================================== */
    $valor = (int)$valor;
    if ($valor < 0) $valor = 0;

    // UPSERT
    $stmtIns = $conexion->prepare("
        INSERT INTO aportes (id_jugador, fecha, aporte_principal)
        VALUES (?, ?, ?)
        ON DUPLICATE KEY UPDATE aporte_principal = VALUES(aporte_principal)
    ");
    $stmtIns->bind_param("isi", $id_jugador, $fecha, $valor);
    $stmtIns->execute();
    $stmtIns->close();

    // Obtener target_id
    $selId = $conexion->prepare("SELECT id FROM aportes WHERE id_jugador=? AND fecha=? LIMIT 1");
    $selId->bind_param("is", $id_jugador, $fecha);
    $selId->execute();
    $rowId = $selId->get_result()->fetch_assoc();
    $selId->close();

    if (!$rowId || !isset($rowId['id'])) {
        throw new Exception("no_target_id_after_upsert");
    }
    $target_id = (int)$rowId['id'];

    /* ======================================================
       3) Si este día se re-edita, primero borramos sus moves previos
          (para recalcular consumo correctamente)
       ====================================================== */
    $delMoves = $conexion->prepare("DELETE FROM aportes_saldo_moves WHERE target_aporte_id=?");
    $delMoves->bind_param("i", $target_id);
    $delMoves->execute();
    $delMoves->close();

  /* ======================================================
   4) Consumir saldo SOLO si el aporte del día es EXACTAMENTE 2000
      - valor > 2000 → NO consume saldo, solo genera excedente.
      - valor = 2000 → consume 2000 de saldo (si hay disponible).
   ====================================================== */
$to_consume = 0;

// Si el aporte del día es exactamente 2000, este día "gasta" un saldo de 2000
if ($valor === 2000) {
    $to_consume = 2000;
}

if ($to_consume > 0) {

    // Candidatos source: aportes anteriores con excedente (>2000)
    $q = $conexion->prepare("
        SELECT id
        FROM aportes
        WHERE id_jugador = ?
          AND fecha < ?
          AND aporte_principal > 2000
        ORDER BY fecha ASC, id ASC
    ");
    $q->bind_param("is", $id_jugador, $fecha);
    $q->execute();
    $sources = $q->get_result()->fetch_all(MYSQLI_ASSOC);
    $q->close();

    foreach ($sources as $s) {
        if ($to_consume <= 0) break;

        $source_id = (int)$s['id'];
        $disp = get_disponible_source($conexion, $source_id);
        if ($disp <= 0) continue;

        // Nunca vamos a consumir más de lo disponible en ese aporte
        $dec = min($disp, $to_consume);

        $insMove = $conexion->prepare("
            INSERT INTO aportes_saldo_moves
              (id_jugador, source_aporte_id, target_aporte_id, amount, fecha_consumo)
            VALUES
              (?, ?, ?, ?, ?)
        ");
        $insMove->bind_param("iiiis", $id_jugador, $source_id, $target_id, $dec, $fecha);
        $insMove->execute();
        $insMove->close();

        $to_consume -= $dec;
    }
}


    $conexion->commit();

    $saldo_actual = get_saldo_acumulado_hasta_mes($conexion, $id_jugador, $mes, $anio);

    echo json_encode([
        'ok' => true,
        'action' => 'upsert',
        'target_id' => $target_id,
        'tope' => min($valor, 2000),
        'saldo' => $saldo_actual
    ]);
    exit;

} catch (Exception $ex) {
    $conexion->rollback();
    echo json_encode([
        'ok' => false,
        'msg' => 'exception',
        'error' => $ex->getMessage()
    ]);
    exit;
}
