<?php
header("Content-Type: application/json");
include "../../conexion.php";
include "../auth/auth.php";

protegerAdmin();

$password = $_POST['password'] ?? '';

if (strlen($password) < 10) {
    echo json_encode(["ok" => false, "msg" => "Contraseña muy corta"]);
    exit;
}

// Validación fuerte backend (OBLIGATORIA)
if (!preg_match('/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[\W_]).{10,}$/', $password)) {
    echo json_encode(["ok" => false, "msg" => "Contraseña no cumple seguridad"]);
    exit;
}

// Generar hash
$hash = password_hash($password, PASSWORD_DEFAULT);

// Actualizar (por usuario en sesión)
$stmt = $conexion->prepare("
    UPDATE usuarios 
    SET password = ?, updated_at = NOW()
    WHERE usuario = ?
");
$stmt->bind_param("ss", $hash, $_SESSION['usuario']);
$stmt->execute();

echo json_encode(["ok" => true]);
