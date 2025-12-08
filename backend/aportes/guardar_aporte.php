<?php
header("Content-Type: application/json");
include "../auth/auth.php";
protegerAdmin();
include "../../conexion.php";

$input = file_get_contents("php://input");
$data = json_decode($input, true);

$id_jugador = intval($data['id_jugador'] ?? 0);
$fecha = $data['fecha'] ?? '';
$valor = array_key_exists('valor', $data) ? $data['valor'] : null;

if (!$id_jugador || !$fecha) {
    echo json_encode(['ok' => false, 'msg' => 'datos invalidos']);
    exit;
}

// convertir valor nulo/ vacío a null real
if ($valor === "" ) $valor = null;

// iniciamos transacción para mantener consistencia en operaciones compuestas
$conexion->begin_transaction();

try {

    // ---------------------------
    // BORRAR APORTE (valor === null)
    // ---------------------------
    if ($valor === null) {
        // buscar id de la fila a borrar (si existe)
        $sel = $conexion->prepare("SELECT id FROM aportes WHERE id_jugador = ? AND fecha = ? LIMIT 1");
        $sel->bind_param("is", $id_jugador, $fecha);
        $sel->execute();
        $res = $sel->get_result()->fetch_assoc();
        $sel->close();

        if (!$res) {
            // nada que borrar: commit y OK (idempotente)
            $conexion->commit();
            echo json_encode(['ok'=>true, 'action'=>'deleted', 'msg'=>'no_row']);
            exit;
        }

        $target_id = intval($res['id']);

        // 1) Revertir movimientos registrados donde target_aporte_id = $target_id
        $q = $conexion->prepare("SELECT id, source_aporte_id, amount FROM aportes_saldo_moves WHERE target_aporte_id = ?");
        $q->bind_param("i", $target_id);
        $q->execute();
        $moves_res = $q->get_result();
        $moves = $moves_res->fetch_all(MYSQLI_ASSOC);
        $q->close();

        foreach ($moves as $m) {
            $source_id = intval($m['source_aporte_id']);
            $amt = intval($m['amount']);
            if ($amt <= 0) continue;

            // devolver el valor a la fila source (sumarle lo que le habíamos quitado)
            $upd = $conexion->prepare("UPDATE aportes SET aporte_principal = aporte_principal + ? WHERE id = ?");
            $upd->bind_param("ii", $amt, $source_id);
            if (!$upd->execute()) {
                $err = $conexion->error;
                $upd->close();
                throw new Exception("error_restore_source: " . $err);
            }
            $upd->close();
        }

        // 2) Borrar los registros de moves relacionados
        $delMoves = $conexion->prepare("DELETE FROM aportes_saldo_moves WHERE target_aporte_id = ?");
        $delMoves->bind_param("i", $target_id);
        if (!$delMoves->execute()) {
            $err = $conexion->error;
            $delMoves->close();
            throw new Exception("error_deleting_moves: " . $err);
        }
        $delMoves->close();

        // 3) Eliminar la fila objetivo
        $del = $conexion->prepare("DELETE FROM aportes WHERE id = ?");
        $del->bind_param("i", $target_id);
        if (!$del->execute()) {
            $err = $conexion->error;
            $del->close();
            throw new Exception("delete_error: " . $err);
        }
        $del->close();

        $conexion->commit();
        echo json_encode(['ok' => true, 'action' => 'deleted', 'reverted_moves' => count($moves)]);
        exit;
    }

    // ---------------------------
    // INSERT / UPDATE (valor numérico)
    // ---------------------------
    $valor = intval($valor);

    // validar fecha
    $dt = strtotime($fecha);
    if ($dt === false) {
        $conexion->rollback();
        echo json_encode(['ok' => false, 'msg' => 'fecha_invalida']);
        exit;
    }
    $mes = intval(date('n', $dt));
    $anio = intval(date('Y', $dt));

    // 1) Upsert la fila objetivo (id_jugador, fecha) -> para obtener su id
    $stmtIns = $conexion->prepare("
        INSERT INTO aportes (id_jugador, fecha, aporte_principal)
        VALUES (?, ?, ?)
        ON DUPLICATE KEY UPDATE aporte_principal = VALUES(aporte_principal)
    ");
    if (!$stmtIns) {
        throw new Exception("prepare_error_insert: " . $conexion->error);
    }
    $stmtIns->bind_param("isi", $id_jugador, $fecha, $valor);
    if (!$stmtIns->execute()) {
        $err = $stmtIns->error;
        $stmtIns->close();
        throw new Exception("execute_error_insert: " . $err);
    }
    $stmtIns->close();

    // obtener el id de la fila objetivo (garantizado por la clave unica id_jugador+fecha)
    $selId = $conexion->prepare("SELECT id FROM aportes WHERE id_jugador = ? AND fecha = ? LIMIT 1");
    $selId->bind_param("is", $id_jugador, $fecha);
    $selId->execute();
    $rowId = $selId->get_result()->fetch_assoc();
    $selId->close();

    if (!$rowId || !isset($rowId['id'])) {
        throw new Exception("no_target_id_after_upsert");
    }
    $target_id = intval($rowId['id']);

    // 2) Consumir excedentes previos (aportes > 2000) del mismo mes/año (order FIFO por fecha)
    $to_consume = $valor;

    if ($to_consume > 0) {
        $q = $conexion->prepare("
            SELECT id, aporte_principal
            FROM aportes
            WHERE id_jugador = ? AND MONTH(fecha) = ? AND YEAR(fecha) = ? AND aporte_principal > 2000 AND id <> ?
            ORDER BY fecha ASC, id ASC
        ");
        $q->bind_param("iiii", $id_jugador, $mes, $anio, $target_id);
        $q->execute();
        $res = $q->get_result();
        $rows = $res->fetch_all(MYSQLI_ASSOC);
        $q->close();

        foreach ($rows as $r) {
            if ($to_consume <= 0) break;
            $id_row = intval($r['id']);
            $current = intval($r['aporte_principal']);
            $excedente = $current - 2000;
            if ($excedente <= 0) continue;

            $dec = min($excedente, $to_consume); // cuánto reducimos en esta fila
            $new_val = $current - $dec;

            // actualizar esa fila
            $upd = $conexion->prepare("UPDATE aportes SET aporte_principal = ? WHERE id = ?");
            $upd->bind_param("ii", $new_val, $id_row);
            if (!$upd->execute()) {
                $err = $conexion->error;
                $upd->close();
                throw new Exception("error_updating_row: " . $err);
            }
            $upd->close();

            // registrar el movimiento para poder revertirlo si borran la fila target
            $insMove = $conexion->prepare("
                INSERT INTO aportes_saldo_moves (id_jugador, source_aporte_id, target_aporte_id, mes, anio, amount)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            $insMove->bind_param("iiiiii", $id_jugador, $id_row, $target_id, $mes, $anio, $dec);
            if (!$insMove->execute()) {
                $err = $insMove->error;
                $insMove->close();
                throw new Exception("error_insert_move: " . $err);
            }
            $insMove->close();

            $to_consume -= $dec;
        }
    }

    $conexion->commit();

    $consumed = max(0, $valor - $to_consume);
    echo json_encode(['ok' => true, 'action' => 'upsert', 'target_id' => $target_id, 'consumed_from_saldo' => intval($consumed), 'remaining_unapplied' => intval($to_consume)]);
    exit;

} catch (Exception $ex) {
    $conexion->rollback();
    echo json_encode(['ok' => false, 'msg' => 'exception', 'error' => $ex->getMessage()]);
    exit;
}
