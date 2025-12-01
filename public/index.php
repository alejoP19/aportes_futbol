
<!-- 
    <header class="topbar"> -->
        <!-- IZQUIERDA: LOGO -->
        <!-- <div class="brand">
            <img src="assets/img/reliquias_logo.jpg" alt="logo" class="logo">
        </div>
        <a href="../public/login.html" class="toggle-left-panel">Administrar</a> -->


        <!-- TÍTULO -->
        <!-- <div class="title">
            <h1>Aportes</h1>
            <div class="subtitle">Las Reliquias Del Fútbol</div>
        </div>
        <div class="date-selectors">
            <label>Año</label>
            <select id="monthSelect">
                <option value="1">Enero</option>
                <option value="2">Febrero</option>
                <option value="3">Marzo</option>
                <option value="4">Abril</option>
                <option value="5">Mayo</option>
                <option value="6">Junio</option>
                <option value="7">Julio</option>
                <option value="8">Agosto</option>
                <option value="9">Septiembre</option>
                <option value="10">Octubre</option>
                <option value="11">Noviembre</option>
                <option value="12">Diciembre</option>
            </select>
            <label>Mes</label>
            <select id="yearSelect">
                <option>2023</option>
                <option>2024</option>
                <option selected>2025</option>
                <option>2026</option>
            </select>
        </div>


    </header>

    <div id="publicTableContainer" class="container"></div>

    <section class="totals-card">
        <p>Total Día: <strong id="dailyTotal"></strong></p>
        <p>Total Mes: <strong id="monthlyTotal"></strong></p>
        <p>Total Año: <strong id="yearlyTotal"></strong></p>
    </section> -->
    <!-- Observaciones -->
    <!-- <div class="gastos-block" id="gastosWrapper">
        <h3>Gastos y Observaciones Del Mes</h3>
        <textarea id="observacionesContainer" class="observaciones-texto">  </textarea>
    </div> -->
 

<?php /* public/index.php */ ?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <title>Aportes – Público</title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <link rel="stylesheet" href="../assets/css/public.css">
</head>
<body>

<header class="topbar">
  <div class="brand">
    <img src="../assets/img/reliquias_logo.jpg" class="logo" alt="logo">
  </div>

  <div class="titles">
    <h1>Aportes</h1>
    <div class="subtitle">Las Reliquias del Fútbol</div>
  </div>

  <div class="actions-right">
    <a href="login.html" class="btn-admin">Administrar</a>
    <button id="btnPDF" class="btn-pdf">Exportar PDF</button>
  </div>
</header>

<section id="selectors" class="selectors">
  <label>Año</label>
  <select id="selectAnio"></select>

  <label>Mes</label>
  <select id="selectMes"></select>
</section>

<section id="contenedorTabla"></section>

<section id="totales" class="totales-card">
  <p>Total Día: <strong id="tDia"></strong></p>
  <p>Total Mes: <strong id="tMes"></strong></p>
  <p>Total Año: <strong id="tAnio"></strong></p>
</section>

<section id="observaciones" class="observaciones-box"></section>

<script src="../assets/js/public.js"></script>
</body>
</html>
