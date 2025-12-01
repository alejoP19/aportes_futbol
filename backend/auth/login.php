<?php
session_start();
include "../../conexion.php";

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $email = $_POST["email"];
    $password = $_POST["password"];

    $query = $conexion->query("SELECT * FROM usuarios WHERE email='$email' LIMIT 1");

    if ($query && $query->num_rows === 1) {

        $user = $query->fetch_assoc();

        if (password_verify($password, $user["password"])) {

            $_SESSION["usuario_id"] = $user["id"];
            $_SESSION["rol"] = $user["rol"];
            $_SESSION["nombre"] = $user["nombre"];

            echo "OK";
            exit;
        }
    }

    echo "ERROR";
}