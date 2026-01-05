<?php
// backend/auth/auth.php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function esAdmin(): bool {
    // Usa la variable que realmente usas en toda tu app
    return !empty($_SESSION['is_admin']) && $_SESSION['is_admin'] === true;

    // Si también quieres soportar admin_id, puedes usar:
    // return (!empty($_SESSION['is_admin']) && $_SESSION['is_admin'] === true)
    //        || !empty($_SESSION['admin_id']);
}

function protegerAdmin() {
    if (!esAdmin()) {
        header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
        header("Pragma: no-cache");
        header("Expires: Sat, 01 Jan 2000 00:00:00 GMT");

        header("Location: /APORTES_FUTBOL/backend/auth/acceso_denegado.php");
        exit;
    }
}

