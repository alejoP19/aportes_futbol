
<?php
// backend/auth/login.php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . "/../../conexion.php";

// 1. Leer datos del POST
$email    = $_POST['email']    ?? '';
$password = $_POST['password'] ?? '';

// 2. Buscar admin por correo
$stmt = $conexion->prepare("SELECT id, email, password FROM usuarios WHERE email = ? LIMIT 1");
$stmt->bind_param("s", $email);
$stmt->execute();
$res = $stmt->get_result();
$admin = $res->fetch_assoc();
$stmt->close();

if (!$admin) {
    echo "ERROR";
    exit;
}

// 3. Verificar contraseña
if (!password_verify($password, $admin['password'])) {
    echo "ERROR";
    exit;
}

// 4. ✅ Guardar sesión correcta
$_SESSION['admin_id'] = $admin['id'];   // opcional
$_SESSION['is_admin'] = true;           // 👈 ESTA es la que revisa esAdmin()

echo "OK";
exit;

