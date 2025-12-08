<?php

session_start();

function esAdmin() {
    return isset($_SESSION["rol"]) && $_SESSION["rol"] === "administrador";
}

function protegerAdmin() {
    if (!esAdmin()) {
        http_response_code(403);
        header("Location: /APORTES_FUTBOL/backend/auth/acceso_denegado.php");
exit;

    }
}
