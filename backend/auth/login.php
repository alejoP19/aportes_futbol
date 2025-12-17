
<?php

session_start();
include "../../conexion.php";

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $email = trim(strtolower($_POST["email"] ?? ""));
   $password = trim($_POST["password"] ?? "");

    if ($email === "" || $password === "") {
        echo "ERROR";
        exit;
    }

    $stmt = $conexion->prepare(
        "SELECT id, nombre, rol, password
         FROM usuarios
         WHERE email = ?
         LIMIT 1"
    );
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $res = $stmt->get_result();

    if ($res && $res->num_rows === 1) {
        $user = $res->fetch_assoc();
if (!password_verify($password, $user["password"])) {
    echo "NO COINCIDE";
    exit;
}
   if (password_verify($password, $user["password"])) {

            $_SESSION["usuario_id"] = $user["id"];
            $_SESSION["rol"]        = $user["rol"];
            $_SESSION["nombre"]     = $user["nombre"];
            $_SESSION["email"]      = $email;

            echo "OK";
            exit;
        }
    }

    echo "ERROR";
}

