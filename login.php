<?php
include "config.php";
$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST'){
    $u = $_POST['username'] ?? '';
    $p = $_POST['password'] ?? '';
    if ($u === $ADMIN_USER && $p === $ADMIN_PASS){
        $_SESSION['is_admin'] = true;
        header('Location: index.php'); exit;
    } else {
        $err = 'Usuario o contraseña incorrectos';
    }
}
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Login - Aportes</title>
<link rel="stylesheet" href="assets/css/styles.css">
</head>
<body>
<div class="login-wrap">
  <form method="post" class="login-form">
    <h2>Administración</h2>
    <?php if($err): ?><div class="error"><?=$err?></div><?php endif; ?>
    <input name="username" placeholder="Usuario" required>
    <input name="password" type="password" placeholder="Contraseña" required>
    <div style="display:flex;gap:8px;">
      <button class="btn" type="submit">Entrar</button>
      <a href="index.php" class="btn hollow">Volver</a>
    </div>
  </form>
</div>
</body>
</html>
