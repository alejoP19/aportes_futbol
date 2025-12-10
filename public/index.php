
 

<?php /* public/index.php */ ?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <title>Aportes – Público</title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="../assets/css/public.css">
</head>
<body>

<header class="topbar">
  <div class="brand">
    <img src="../assets/img/reliquias_logo.jpg" class="logo" alt="logo">
  </div>

  <div class="titles">
    <h1>Aportes</h1>
    <h3>Las Reliquias del Fútbol</h3>
  </div>

  <div class="actions-right">
    <a href="login.html" class="admin_public_butt">Administrar</a>
    <a  class="export_public_pdf_butt">Exportar PDF</a>
  </div>
</header>

<section id="selectors" class="selectors">
  <label>Año</label>
  <select id="selectAnio" class="form-select" ></select>

  <label>Mes</label>
  <select id="selectMes"  class="form-select " ></select>
</section>

<section id="contenedorTabla"></section>

<div class="gastos-observaciones">
  <!-- Nueva sección para mostrar gastos del mes / año -->
  <section id="gastosMesPublico" class="observaciones-box">
    
    </section>
    <section id="totales" class="totales-card">
      <h2>Totales</h2>

    <div class="totals-items" >
      <label><strong>Total Aportes del Mes</strong></label>
        <p id="tMes"></p>
    </div>

    <div class="totals-items" >
      <label><strong>Total Otros Aportes Del Mes </strong></label>
        <p id="tOtros"></p>
    </div>

    <div class="totals-items" >
      <label><strong>Total Aportes Del Año </strong></label>
        <p id="tAnio"></p>
    </div>
</section>
    <div class="box-container-observ" >
         <h2 id="title-observaciones">Observaciones</h2>
       <section id="observaciones" class="observaciones-box">
   
       </section>
   </div>
</div>


<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/js/bootstrap.bundle.min.js" integrity="sha384-MrcW6ZMFYlzcLA8Nl+NtUVF0sA7MsXsP1UyJoMp4YLEuNSfAP+JcXn/tWtIaxVXM" crossorigin="anonymous"></script>
<script src="../assets/js/public.js"></script>
</body>
</html>
