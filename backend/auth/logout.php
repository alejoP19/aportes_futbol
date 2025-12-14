<?php
// backend/auth/logout.php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Vaciar sesión
$_SESSION = [];

// Eliminar cookie de sesión
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(),
        '',
        time() - 42000,
        $params["path"],
        $params["domain"],
        $params["secure"],
        $params["httponly"]
    );
}

// Destruir sesión
session_destroy();

// RESPONDER A FETCH (NO REDIRIGIR)
echo "OK";
exit;


