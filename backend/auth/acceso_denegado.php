<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Acceso Denegado</title>
 <meta name="viewport" content="width=device-width,initial-scale=1">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="../../assets/css/public.css">
</head>
<style>
body {
     position: relative;
  margin: 0;
  padding: 0;
  overflow-x: hidden;
  box-sizing: border-box;
  background-image: url("../../assets/img/prohibido.avif") !important;
  background-repeat: no-repeat;
  background-position: center;
  background-size: cover;
  background-attachment: fixed;
  width: 100%;
  min-height: 100dvh;
  font-family: "Lucida Sans Unicode", sans-serif !important;
}
.card {
    max-width: 420px;
    margin: 120px auto;
    background: white;
    padding: 30px;
    border-radius: 12px;
    text-align: center;
    box-shadow: 0 0 15px rgba(0,0,0,.15);
}
.card h2 {
    color: #d20000;
    margin-bottom: 10px;
}
.card a {
    display: inline-block;
    margin-top: 15px;
    padding: 10px 18px;
    background: #d20000;
    color: white;
    border-radius: 6px;
    text-decoration: none;
}
</style>
<header class="topbar">
  <div class="brand">
    <img src="../../assets/img/reliquias_logo.jpg" class="logo" alt="logo">
  </div>

  <div class="titles">
    <h1>Aportes</h1>
    <h3>Las Reliquias del Fútbol</h3>
  </div>


</header>
<body class="acceso-prohidibo">
    <div class="card">
        <h2>Acceso Denegado</h2>
        <p>Para entrar al panel de administrador debes iniciar sesión.</p>

        <a href="../../public/login.html">Ir al Inicio de Sesión</a>
    </div>
</body>
</html>
