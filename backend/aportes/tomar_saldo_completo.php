<?php
header("Content-Type: application/json; charset=utf-8");
include "../../conexion.php";
require_once __DIR__ . "/../auth/auth.php";
protegerAdmin();

$TOPE = 3000;

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

function get_saldo_acumulado_hasta_mes($conexion, $id_jugador, $mes, $anio) {
    $fechaCorte = date('Y-m-t', strtotime("$anio-$mes-01"));

    $q1 = $conexion->prepare("
        SELECT IFNULL(SUM(GREATEST(aporte_principal - 3000, 0)), 0) AS excedente
        FROM aportes
        WHERE id_jugador = ?
          AND fecha <= ?
          AND (tipo_aporte IS NULL OR tipo_aporte = '')
    ");
    $q1->bind_param("is", $id_jugador, $fechaCorte);
    $q1->execute();
    $excedente = (int)($q1->get_result()->fetch_assoc()['excedente'] ?? 0);
    $q1->close();

    $q2 = $conexion->prepare("
        SELECT IFNULL(SUM(m.amount), 0) AS consumido
        FROM aportes_saldo_moves m
        INNER JOIN aportes s ON s.id = m.source_aporte_id
        WHERE m.id_jugador = ?
          AND m.fecha_consumo <= ?
          AND s.aporte_principal > 3000
          AND (s.tipo_aporte IS NULL OR s.tipo_aporte = '')
    ");
    $q2->bind_param("is", $id_jugador, $fechaCorte);
    $q2->execute();
    $consumido = (int)($q2->get_result()->fetch_assoc()['consumido'] ?? 0);
    $q2->close();

    $saldo = $excedente - $consumido;
    return ($saldo > 0) ? $saldo : 0;
}

$input = file_get_contents("php://input");
$data  = json_decode($input, true);

$id_jugador = (int)($data['id_jugador'] ?? 0);
$fecha      = trim((string)($data['fecha'] ?? ''));
$activar    = !empty($data['activar']);

if ($id_jugador <= 0 || !$fecha) {
    echo json_encode(["ok" => false, "msg" => "Datos inválidos"]);
    exit;
}

$dt = strtotime($fecha);
if ($dt === false) {
    echo json_encode(["ok" => false, "msg" => "Fecha inválida"]);
    exit;
}

$mes  = (int)date('n', $dt);
$anio = (int)date('Y', $dt);

$conexion->begin_transaction();

try {
    // Buscar o crear target del día
    $q = $conexion->prepare("
        SELECT id, IFNULL(aporte_principal,0) AS aporte_principal
        FROM aportes
        WHERE id_jugador = ? AND fecha = ?
        LIMIT 1
    ");
    $q->bind_param("is", $id_jugador, $fecha);
    $q->execute();
    $row = $q->get_result()->fetch_assoc();
    $q->close();

    if ($row) {
        $target_id  = (int)$row['id'];
        $valor_real = (int)$row['aporte_principal'];
    } else {
        $ins = $conexion->prepare("
            INSERT INTO aportes (id_jugador, fecha, aporte_principal, tipo_aporte)
            VALUES (?, ?, 0, NULL)
        ");
        $ins->bind_param("is", $id_jugador, $fecha);
        $ins->execute();
        $target_id = (int)$conexion->insert_id;
        $ins->close();
        $valor_real = 0;
    }

    // ✅ NORMALIZAR EL TARGET EXISTENTE O NUEVO COMO APORTE NORMAL
    $fixTarget = $conexion->prepare("
        UPDATE aportes
        SET tipo_aporte = NULL
        WHERE id = ?
    ");
    $fixTarget->bind_param("i", $target_id);
    $fixTarget->execute();
    $fixTarget->close();

    if ($activar) {
        // No permitir si ya hay aporte real > 0
        if ($valor_real > 0) {
            throw new Exception("Este día ya tiene un aporte real. Quita primero ese valor para usar saldo completo.");
        }

        // Borrar consumo previo del target para rehacer limpio
        $del = $conexion->prepare("DELETE FROM aportes_saldo_moves WHERE target_aporte_id = ?");
        $del->bind_param("i", $target_id);
        $del->execute();
        $del->close();

        $faltante = 3000;
        $fechaCorteMes = date('Y-m-t', strtotime("$anio-$mes-01"));

        $src = $conexion->prepare("
            SELECT id
            FROM aportes
            WHERE id_jugador = ?
              AND fecha <= ?
              AND aporte_principal > 3000
              AND (tipo_aporte IS NULL OR tipo_aporte = '')
              AND id <> ?
            ORDER BY fecha ASC, id ASC
        ");
        $src->bind_param("isi", $id_jugador, $fechaCorteMes, $target_id);
        $src->execute();
        $sources = $src->get_result()->fetch_all(MYSQLI_ASSOC);
        $src->close();

        $consumido_target = 0;

        foreach ($sources as $s) {
            if ($faltante <= 0) break;

            $source_id = (int)$s['id'];
            $disp = get_disponible_source($conexion, $source_id);
            if ($disp <= 0) continue;

            $usar = min($disp, $faltante);

            $mov = $conexion->prepare("
                INSERT INTO aportes_saldo_moves
                (id_jugador, source_aporte_id, target_aporte_id, amount, fecha_consumo)
                VALUES (?, ?, ?, ?, ?)
            ");
            $mov->bind_param("iiiis", $id_jugador, $source_id, $target_id, $usar, $fecha);
            $mov->execute();
            $mov->close();

            $faltante -= $usar;
            $consumido_target += $usar;
        }

        if ($consumido_target < 3000) {
            // revertir lo que alcanzó a poner
            $del2 = $conexion->prepare("DELETE FROM aportes_saldo_moves WHERE target_aporte_id = ?");
            $del2->bind_param("i", $target_id);
            $del2->execute();
            $del2->close();

            throw new Exception("El aportante no tiene saldo suficiente para cubrir 3000");
        }

        // asegurar que el aporte real del target quede en 0 y siga siendo aporte normal
        $up = $conexion->prepare("
            UPDATE aportes
            SET aporte_principal = 0,
                tipo_aporte = NULL
            WHERE id = ?
        ");
        $up->bind_param("i", $target_id);
        $up->execute();
        $up->close();

        $conexion->commit();

        $saldo_actual = get_saldo_acumulado_hasta_mes($conexion, $id_jugador, $mes, $anio);

        echo json_encode([
            "ok" => true,
            "action" => "saldo_full_on",
            "target_id" => $target_id,
            "aporte_efectivo" => 3000,
            "consumido_target" => 3000,
            "saldo" => $saldo_actual,
            "valor_real" => 0
        ]);
        exit;
    }

    // DESACTIVAR
    $del = $conexion->prepare("DELETE FROM aportes_saldo_moves WHERE target_aporte_id = ?");
    $del->bind_param("i", $target_id);
    $del->execute();
    $del->close();

    // Si el target no tiene aporte real, lo borramos
    if ($valor_real <= 0) {
        $delA = $conexion->prepare("DELETE FROM aportes WHERE id = ?");
        $delA->bind_param("i", $target_id);
        $delA->execute();
        $delA->close();

        $aporte_efectivo = 0;
    } else {
        $aporte_efectivo = min($valor_real, $TOPE);
    }

    $conexion->commit();

    $saldo_actual = get_saldo_acumulado_hasta_mes($conexion, $id_jugador, $mes, $anio);

    echo json_encode([
        "ok" => true,
        "action" => "saldo_full_off",
        "target_id" => $target_id,
        "aporte_efectivo" => $aporte_efectivo,
        "consumido_target" => 0,
        "saldo" => $saldo_actual,
        "valor_real" => $valor_real
    ]);
    exit;

} catch (Exception $e) {
    $conexion->rollback();
    echo json_encode([
        "ok" => false,
        "msg" => $e->getMessage()
    ]);
    exit;
}