

<?php
include __DIR__ . "/../../conexion.php";

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    header("Content-Type: application/json");

    $token = $_GET['token'] ?? '';
    $pass1 = trim($_POST['pass1'] ?? '');

    if (!$token || !$pass1) {
        echo json_encode(["ok" => false, "msg" => "Datos incompletos"]);
        exit;
    }

    if (!preg_match('/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[\W_]).{10,}$/', $pass1)) {
        echo json_encode(["ok" => false, "msg" => "Contraseña insegura"]);
        exit;
    }

    $hash = password_hash($pass1, PASSWORD_DEFAULT);

  $u = $conexion->prepare("
    UPDATE usuarios
    SET password = ?, reset_token = NULL, reset_expira = NULL
    WHERE reset_token = ? AND reset_expira > NOW()
");
    $u->bind_param("ss", $hash, $token);
    $u->execute();
    if ($u->affected_rows === 0) {
    echo json_encode(["ok" => false, "msg" => "Token inválido o vencido"]);
    exit;
}

    echo json_encode(["ok" => true]);
    exit;
}



$token = $_GET['token'] ?? '';

if (!$token) {
  die("Token inválido");
}

// Verificar token
$q = $conexion->prepare("
  SELECT id FROM usuarios
  WHERE reset_token = ? AND reset_expira > NOW()
");
$q->bind_param("s", $token);
$q->execute();
$r = $q->get_result();

if ($r->num_rows === 0) {
  die("Token vencido o inválido");
}
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <title>Recuperar Contraseña</title>
  <meta name="viewport" content="width=device-width,initial-scale=1">

  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="../../assets/css/public.css">
  <link rel="stylesheet" href="../../assets/css/login_admin.css">
</head>

<body>

<header class="topbar">
  <div class="brand">
    <img src="../../assets/img/reliquias_logo.jpg" class="logo">
  </div>

  <div class="titles">
    <h1>Aportes</h1>
    <h3>Las Reliquias del Fútbol</h3>
  </div>

  <div class="actions-butt-right">
    <a href="../../public/login.html" class="volver-butt">Volver</a>
  </div>
</header>

<section class="admin-card">
  <h2 class="title-admin-form" style="margin-bottom:60px; margin-top:40px">Ingresa una nueva contraseña</h2>

 <form id="recoverForm" class="admin-form">

  <label class="title-labels-form">Nueva contraseña</label>
  <div class="password-wrapper">
    <input type="password" id="pass1" name="pass1" class="form-control" required>
    <span class="toggle-pass" data-target="pass1">👁️</span>
  </div>

  <label class="title-labels-form">Confirmar contraseña</label>
  <div class="password-wrapper">
    <input type="password" id="pass2" name="pass2" class="form-control" required>
    <span class="toggle-pass" data-target="pass2">👁️</span>
  </div>

  <button type="submit" class="ingresar-butt">
    Cambiar contraseña
  </button>
</form>
  <h3 class="warning-title-admin">Solo para administradores</h3>
</section>


<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<script>
document.getElementById("recoverForm").addEventListener("submit", e => {
  e.preventDefault();

  const p1 = document.getElementById("pass1").value;
  const p2 = document.getElementById("pass2").value;

  const fuerte = /^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[\W_]).{10,}$/;

  if (p1 !== p2) {
    Swal.fire(
      "Error",
      "Las contraseñas no coinciden",
      "error"
    );
    return;
  }

  if (!fuerte.test(p1)) {
    Swal.fire(
      "Contraseña débil",
      "Debe tener mínimo 10 caracteres, mayúscula, minúscula, número y símbolo",
      "warning"
    );
    return;
  }

  fetch(window.location.href, {
    method: "POST",
    headers: { "Content-Type": "application/x-www-form-urlencoded" },
    body: `pass1=${encodeURIComponent(p1)}`
  })
  .then(r => r.json())
  .then(res => {
    if (res.ok) {
      Swal.fire(
        "Proceso exitoso",
        "La contraseña fue actualizada correctamente",
        "success"
      ).then(() => {
        window.location.href = "../../public/login.html";
      });
    } else {
      Swal.fire("Error", res.msg, "error");
    }
  });
});


</script>
<script>
document.querySelectorAll(".toggle-pass").forEach(btn => {
  btn.addEventListener("click", () => {
    const input = document.getElementById(btn.dataset.target);
    input.type = input.type === "password" ? "text" : "password";
  });
});
</script>
</body>
</html>
