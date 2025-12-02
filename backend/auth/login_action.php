<?php
session_start();
include "../../conexion.php";

$email = $_POST['email'];
$password = $_POST['password'];

$result = $conexion->query("SELECT * FROM usuarios WHERE email='$email'");
$usuario = $result->fetch_assoc();

if($usuario && password_verify($password, $usuario['password'])) {
    // Guardar datos del usuario en sesión
    $_SESSION['id'] = $usuario['id'];
    $_SESSION['nombre'] = $usuario['nombre'];
    $_SESSION['rol'] = $usuario['rol'];

    header("Location: dashboard.php");
    exit;
} else {
    echo "Usuario o contraseña incorrectos.";
}
