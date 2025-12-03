<?php
// backend/auth/logout.php
// IMPORTANTE: Asegúrate de que NO haya espacio ni BOM antes de <?php

// Iniciar sesión si no está iniciada
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Vaciar variables de sesión
$_SESSION = [];

// Si existe cookie de sesión, eliminarla
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

// Finalmente destruir la sesión
session_destroy();

// Redirigir (usa ruta absoluta según tu proyecto)
header("Location: /aportes_futbol/public/index.php");
exit;
