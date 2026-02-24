<?php
header("Content-Type: application/json");
include "../../conexion.php";
require_once __DIR__ . "/../auth/auth.php";
protegerAdmin();
/* ======================================================
   CONFIG
====================================================== */
// ✅ [NUEVO] Permitir consumir saldo de aportes "futuros" dentro del mismo mes (solo para pruebas).
// En producción puedes dejarlo en false si quieres mantener la lógica estricta.
$ALLOW_FUTURE_SOURCES_IN_MONTH = true;

$TOPE = 3000;

/* ======================================================
   SALDO HASTA UN MES/AÑO (corte al último día del mes)
   saldo = excedente - consumido
   excedente = SUM(max(aporte_principal - 3000, 0))
   consumido = SUM(amount) en aportes_saldo_moves
====================================================== */
function get_saldo_acumulado_hasta_mes($conexion, $id_jugador, $mes, $anio) {
    $fechaCorte = date('Y-m-t', strtotime("$anio-$mes-01"));

    // Excedente generado (con base en valor REAL guardado)
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

    // Consumo acumulado (por fecha_consumo real)
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
   DISPONIBLE DE UNA FILA SOURCE
   disponible_source = (aporte_principal - 3000) - sum(moves.amount de ese source)
====================================================== */
function get_disponible_source($conexion, $source_id) {
    $q = $conexion->prepare("
        SELECT
          GREATEST(a.aporte_principal - 3000, 0) - IFNULL(SUM(m.amount), 0) AS disponible
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

/* ======================================================
   INPUT
====================================================== */
$input = file_get_contents("php://input");
$data  = json_decode($input, true);

$id_jugador = (int)($data['id_jugador'] ?? 0);
$fecha      = (string)($data['fecha'] ?? '');
$valor      = array_key_exists('valor', $data) ? $data['valor'] : null;

if (!$id_jugador || !$fecha) {
    echo json_encode(['ok' => false, 'msg' => 'datos invalidos']);
    exit;
}

if ($valor === "" || $valor === null) $valor = null;

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
       1) BORRAR aporte + borrar movimientos destino
    ====================================================== */
    if ($valor === null) {

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

        // borrar consumo aplicado a este día
        $delMoves = $conexion->prepare("DELETE FROM aportes_saldo_moves WHERE target_aporte_id=?");
        $delMoves->bind_param("i", $target_id);
        $delMoves->execute();
        $delMoves->close();

        // borrar aporte
        $del = $conexion->prepare("DELETE FROM aportes WHERE id=?");
        $del->bind_param("i", $target_id);
        $del->execute();
        $del->close();

        $conexion->commit();

        $saldo_actual = get_saldo_acumulado_hasta_mes($conexion, $id_jugador, $mes, $anio);

        echo json_encode([
            'ok' => true,
            'action' => 'deleted',
            'consumido_target' => 0,
            'aporte_efectivo'  => 0,
            'saldo' => $saldo_actual,
            'valor_real' => $valor   // ✅ NUEVO
        ]);
        exit;
    }

    /* ======================================================
       2) UPSERT del aporte (GUARDA VALOR REAL DIGITADO)
          - Aquí NO hacemos cap a 3000 porque el saldo depende del real.
          - El cap a 3000 se hace al MOSTRAR (listar_aportes.php) con LEAST().
    ====================================================== */
    $valor = (int)$valor;
    if ($valor < 0) $valor = 0;

    $stmtIns = $conexion->prepare("
        INSERT INTO aportes (id_jugador, fecha, aporte_principal)
        VALUES (?, ?, ?)
        ON DUPLICATE KEY UPDATE aporte_principal = VALUES(aporte_principal)
    ");
    $stmtIns->bind_param("isi", $id_jugador, $fecha, $valor);
    $stmtIns->execute();
    $stmtIns->close();

    // target_id
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
       3) Si el día se re-edita, borrar moves previos de ese día
    ====================================================== */
    $delMoves = $conexion->prepare("DELETE FROM aportes_saldo_moves WHERE target_aporte_id=?");
    $delMoves->bind_param("i", $target_id);
    $delMoves->execute();
    $delMoves->close();

    /* ======================================================
       4) CONSUMIR SALDO PARA COMPLETAR HASTA 3000
          - Si valor < 3000 => consume (3000 - valor) si hay saldo disponible.
          - Si valor >= 3000 => NO consume (si es 5000 genera excedente 2000).
    ====================================================== */
    $to_consume = 0;
    if ($valor > 0 && $valor < $TOPE) {
        $to_consume = $TOPE - $valor;
    }

    $consumido_target = 0;

    if ($to_consume > 0) {

 

// ✅ [NUEVO] Si el target es un día "anterior" (p.ej. Otro juego (01)),
// podemos permitir consumir excedentes del mismo mes hasta el último día del mes.
// Esto evita que el "otro juego" no complete a 3000 cuando hay excedente en días posteriores.
$fechaIniMes   = sprintf("%04d-%02d-01", $anio, $mes);
$fechaCorteMes = date('Y-m-t', strtotime($fechaIniMes));

if (!empty($ALLOW_FUTURE_SOURCES_IN_MONTH)) {

    $q = $conexion->prepare("
        SELECT id
        FROM aportes
        WHERE id_jugador = ?
          AND fecha <= ?              -- ✅ permite excedentes hasta fin de mes
          AND aporte_principal > 3000
          AND id <> ?                 -- ✅ evita usar el mismo target como source (por seguridad)
        ORDER BY fecha ASC, id ASC
    ");
    $q->bind_param("isi", $id_jugador, $fechaCorteMes, $target_id);

} else {

    // comportamiento original (estricto): solo excedentes anteriores al día target
    $q = $conexion->prepare("
        SELECT id
        FROM aportes
        WHERE id_jugador = ?
          AND fecha < ?
          AND aporte_principal > 3000
          AND id <> ?
        ORDER BY fecha ASC, id ASC
    ");
    $q->bind_param("isi", $id_jugador, $fecha, $target_id);
}

$q->execute();
$sources = $q->get_result()->fetch_all(MYSQLI_ASSOC);
$q->close();


      

        foreach ($sources as $s) {
            if ($to_consume <= 0) break;

            $source_id = (int)$s['id'];
            $disp = get_disponible_source($conexion, $source_id);
            if ($disp <= 0) continue;

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
            $consumido_target += $dec;
        }
    }

    $conexion->commit();

    $saldo_actual = get_saldo_acumulado_hasta_mes($conexion, $id_jugador, $mes, $anio);

    // aporte efectivo de ese día (cash + saldo_consumido), cap 3000
    $aporte_efectivo = min($valor + $consumido_target, $TOPE);

    echo json_encode([
        'ok' => true,
        'action' => 'upsert',
        'target_id' => $target_id,
        'consumido_target' => $consumido_target,
        'aporte_efectivo' => $aporte_efectivo,
        'saldo' => $saldo_actual,
        'valor_real' => $valor // ✅ NUEVO: valor real guardado
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
