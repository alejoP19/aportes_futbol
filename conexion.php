<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$DB_HOST = 'localhost';
$DB_PORT = 3306; // tu puerto correcto
$DB_USER = 'root';
$DB_PASS = '';
$DB_NAME = 'aportes_futbol';

$conexion = new mysqli('localhost:3306', 'root', '', 'aportes_futbol');
$conexion->set_charset("utf8");

if ($conexion->connect_error) {
    die("Conexión falló: " . $conexion->connect_error);
}

$conexion->set_charset("utf8mb4");
