<?php
session_start();

function esAdmin() {
    return isset($_SESSION["rol"]) && $_SESSION["rol"] === "administrador";
}

function protegerAdmin() {
    if (!esAdmin()) {
        http_response_code(403);
        die("No autorizado");
    }
}
