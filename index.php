<?php
include "conexion.php";
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Aportes - Cuadrícula diaria</title>
<link rel="stylesheet" href="assets/css/styles.css">
</head>
<body>
<header class="topbar">

  <!-- IZQUIERDA: LOGO -->
  <div class="brand">
    <img src="assets/img/reliquias_logo.jpg" alt="logo" class="logo">
  </div>

  <!-- BOTÓN APORTANTES -->
  <button class="toggle-left-panel">
    Ver Aportantes
  </button>

  <!-- TÍTULO -->
  <div class="title">
    <h1>Aportes</h1>
    <div class="subtitle">Las Reliquias Del Fútbol</div>
  </div>

  <!-- SELECTS FECHA -->
  <div class="date-selectors">
    <div class="year">
      <label>Año</label>
      <select id="yearSelect">
        <?php
        $year = date('Y');
        for($i = $year; $i >= $year - 5; $i--) {
            echo "<option value='$i'".($i == $year ? " selected" : "").">$i</option>";
        }
        ?>
      </select>
    </div>
    <div class="month">
      <label>Mes</label>
      <select id="monthSelect">
        <?php
        $months = [1=>'Enero',2=>'Febrero',3=>'Marzo',4=>'Abril',5=>'Mayo',6=>'Junio',7=>'Julio',8=>'Agosto',9=>'Septiembre',10=>'Octubre',11=>'Noviembre',12=>'Diciembre'];
        foreach($months as $k=>$m){
          $sel = ($k==date('n'))? ' selected' : '';
          echo "<option value='$k'$sel>$m</option>";
        }
        ?>
      </select>
    </div>
  </div>

</header>

<main class="container">

  <!-- PANEL IZQUIERDO: APORTANTES -->
  <section class="left-panel">
    <div class="controls">
      <div class="add-player-title">Ingresar Nuevo Aportante</div>
      <form id="addPlayerForm" class="add-player-box">
        <input type="text" id="playerName" placeholder="Nombre del aportante" required>
        <input type="text" id="playerPhone" placeholder="Teléfono del aportante" required>
        <button type="submit" id="btnAddPlayer" class="save-player-btn">Guardar Aportante</button>
      </form>
    </div>
    <div id="playersTableContainer">
      <div class="loading">Cargando lista de aportantes...</div>
    </div>
    <div class="pagination" id="paginationContainer"></div>
  </section>

  <!-- PANEL CENTRAL: TABLA + OBSERVACIONES -->
  <section class="middle-panel">
    <!-- Tabla scrollable -->
    <div class="table-wrap" id="monthlyTableContainer">
      <div class="loading">Cargando planilla mensual...</div>
    </div>

    <!-- Observaciones -->
    <div class="gastos-block" id="gastosWrapper">
      <h3>GASTOS Y OBSERVACIONES DEL MES</h3>
      <textarea id="obsMes" style="width:100%;min-height:120px;padding:8px;border:1px solid #ddd;"></textarea>
      <div style="margin-top:8px;text-align:right">
        <button id="saveObsBtn" class="btn">Guardar Observaciones</button>
      </div>
    </div>
  </section>

  <!-- PANEL DERECHO: TOTALES + OTROS APORTES + PDF -->
  <aside class="right-panel">
    <div class="totals-card">
      <h3>Totales (COP)</h3>
      <div>Hoy: <strong id="dailyTotal">0</strong></div>
      <div>Mes: <strong id="monthlyTotal">0</strong></div>
      <div>Año: <strong id="yearlyTotal">0</strong></div>
    </div>

    <div class="otros-aportes-card">
      <h4>Agregar Otro Aporte</h4>
      <select id="selectPlayerOtros"></select>
      <input id="otroTipo" type="text" placeholder="Tipo (ej. Balón)" />
      <input id="otroValor" type="number" placeholder="Valor (COP)" />
      <button id="btnAddOtro" class="otro-aporte">Agregar</button>
    </div>

    <div class="notes-card">
      <h4>Ver Reporte Actual Del Mes</h4>
      <div class="actions">
        <a href="/aportes_futbol/backend/export_pdf.php?mes=<?=$mes?>&anio=<?=$anio?>" target="_blank" class="export-pdf-butt">Generar PDF</a>
      </div>
    </div>
  </aside>

</main>

<!-- VARIABLES GLOBALES -->
<script>window.API_BASE = "backend"; window.isAdmin = <?php echo isset($_SESSION['is_admin'])? "true":"false"; ?>;</script>

<!-- MAIN JS -->
<script src="assets/js/main.js"></script>


<!-- SCRIPT PERSONALIZADO PARA TABLA Y OBSERVACIONES -->


</body>
</html>
