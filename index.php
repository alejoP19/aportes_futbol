<?php

include "backend/auth/auth.php";
protegerAdmin();
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
    
    <button class="toggle-left-panel" id="btnVerAportantes">
      Ver Aportantes
    </button>
    <div class="actions-buttons-div">
      <div class="logout-pdf-buttons">
        <button class="export-pdf-butt">Generar PDF</button>
        <button id="btnLogout" class="logout-button">Cerrar Sesión</button>
      </div>
      <!-- SELECTS FECHA -->
      <div class="date-selectors">
        <label>Año</label>
        <select id="yearSelect" class="year">
          <?php
          $year = date('Y');
          for ($i = $year; $i >= $year - 5; $i--) {
            echo "<option value='$i'" . ($i == $year ? " selected" : "") . ">$i</option>";
          }
          ?>
        </select>

        <label>Mes</label>
        <select id="monthSelect" class="month">
          <?php
          $months = [
            1 => 'Enero',
            2 => 'Febrero',
            3 => 'Marzo',
            4 => 'Abril',
            5 => 'Mayo',
            6 => 'Junio',
            7 => 'Julio',
            8 => 'Agosto',
            9 => 'Septiembre',
            10 => 'Octubre',
            11 => 'Noviembre',
            12 => 'Diciembre'
          ];

          $currentMonth = date('n'); // mes actual en número

          foreach ($months as $k => $m) {
            $sel = ($k == $currentMonth) ? " selected" : "";
            echo "<option value='$k'$sel>$m</option>";
          }
          ?>
        </select>

      </div>


    </div>


    <!-- TÍTULO -->
    <div class="title">
      <h1>Aportes</h1>
      <div class="subtitle">Las Reliquias Del Fútbol</div>
    </div>


  </header>

  <main class="container">

    <!-- PANEL IZQUIERDO: APORTANTES -->
    <section class="left-panel">

      <form class="add-aportante">
        <h4>Registrar Nuevo Aportante</h4>
        <label for="playerName">Nombre Del Aportante</label>
        <input type="text" id="playerName" placeholder="Ingresar Nombre" required>
        <label for="playerPhone">Telefono Del Aportante</label>
        <input type="text" id="playerPhone" placeholder="Ingresar Número" required>

        <button type="button" id="btnAddPlayer" class="save-player-btn">Registrar</button>
      </form>


      <div id="playersTableContainer">
        <div class="loading"></div>
      </div>

      <div class="pagination" id="paginationContainer">

      </div>


      <div class="otros-aportes-card">
        <h4>Agregar Otro Aporte</h4>
        <label for="selectPlayerOtros">¿Quién va a Aportar?</label>
        <select id="selectPlayerOtros"></select>
        <label for="otroTipo"> Tipo de Aporte</label>
        <input id="otroTipo" type="text" placeholder=" Ejemplo: Balón)" />
        <label for="otroValor"> Valor Del Aporte</label>
        <input id="otroValor" type="number" placeholder="$" />
        <button id="btnAddOtro" class="add-otro-aporte-butt">Agregar</button>
      </div>
    </section>

    <!-- OVERLAY PARA TABLA (Aportantes) -->
    <div class="overlay-aportantes" id="overlayAportantes" aria-hidden="true">

      <!-- PANEL CENTRAL: TABLA + OBSERVACIONES -->
      <section class="middle-panel">
        <!-- Tabla scrollable -->
        <div class="table-wrap" id="monthlyTableContainer">
          <div class="loading">Cargando planilla mensual...</div>
        </div>
      </section>


    </div>


    <!-- PANEL DE GASTOS-->
    <section class="gastos-section">
      <div class="gastos-card">
        <h4>Agregar Gasto</h4>

        <label for="gastoNombre">Nombre del gasto</label>
        <input id="gastoNombre" type="text" placeholder="Ej: Árbitro, Balón, Limpieza">

        <label for="gastoValor">Valor del gasto</label>
        <input id="gastoValor" type="number" placeholder="$">
        <button id="btnAddGasto" class="save-gasto">Registrar Gasto</button>

      </div>


      <div class="gastos-registrados-card">
        <h4>Gastos Registrados</h4>
        <ul id="listaGastos"></ul>
      </div>

    </section>


    <!-- PANEL DERECHO: TOTALES + OTROS APORTES + PDF -->
    <aside class="right-panel">
      <div class="totals-card">
        <h4 class="parcial-tot-title">Totales (COP)</h4>
        <div class="total-parcial-mini">
          <small class="parcial-totals-mini-help">Incluyen Valores de Cada Día de la Columna Otro Juego</small>
          <div><span>Total Parcial Mes <small>(Sin Otros Aportes/Saldo/Eliminados) </small>
              <strong id="tParcialMes" class="totales-item-value">$ 0</strong></span>
          </div>

          <div><span>Total Parcial Año <small>(Sin Otros Aportes/Saldo/Eliminados)</small>
              <strong id="tParcialAnio" class="totales-item-value">$ 0</strong></span>
          </div>
        </div>


        <hr style="opacity:.25; margin:1px 0;">
        <div class="otros-saldo-mini">
          <div><span>Otros Aportes Mes <strong id="tOtrosMes" class="totales-item-value otros-span">$ 0</strong></span>
          </div>
          <div><span>Otros Aportes Año <strong id="tOtrosAnio" class="totales-item-value otros-span">$ 0</strong></span>
          </div>
          <div><span> Saldo Actual Hasta Mes <strong id="tSaldoTotal" class="totales-item-value otros-span">$ 0</strong></span>
          </div>
        </div>

        <hr style="opacity:.25; margin:1px 0;">
        <div class="totales-aportantes-eliminados">
          <h4>Aportantes Eliminados Este Mes</h4>
          <div class="eliminados-div-button">
            <div class="mini-help">No Aparecen en Planilla, Pero sus Aportes y Saldos Siguen Sumando en Totales.</div>
            <span id="totalEliminadosMes">$ 0</span>
            <button type="button" id="btnVerEliminados" class="btn-ver-eliminados">Ver detalle</button>
          </div>
        </div>

        <hr style="opacity:.25; margin:1px 0;">
        <div class="totales-estimado-mini">
          <div><span>Total Estimado Mes <small>( + Otros Aportes + Saldos + Eliminados, Sin gastos)</small>
              <strong id="tEstimadoMes" class="totales-item-value">$ 0</strong></span>
          </div>
          <div> <span>Total Estimado Año <small>( + Otros Aportes + Saldos + Eliminados, Sin gastos)</small>
              <strong id="tEstimadoAnio" class="totales-item-value">$ 0</strong></span>
          </div>
        </div>

        <hr style="opacity:.25; margin:1px 0;">
        <div class="totales-gastos-mini">
          <div>
            <span>Gastos del Mes: <strong id="tGastosMes" class="totales-item-value gastos-item">0</strong></span>
          </div>
          <div>
            <span>Gastos del Año: <strong id="tGastosAnio" class="totales-item-value  gastos-item">0</strong></span>
          </div>
        </div>

        <hr style="opacity:.25; margin:1px 0;">
        <div class="totales-finales-mini">
          <div>Total Final Mes <small>(estimado - gastos)</small> <strong id="tFinalMes" class="totales-item-value">$ 0</strong></div>
          <div>Total Final Año <small>(estimado - gastos)</small> <strong id="tFinalAnio" class="totales-item-value">$ 0</strong></div>
        </div>




        <div id="modalEliminados" class="modal hidden">
          <div class="modal-card">
            <div class="modal-head">
              <strong>Eliminados este mes</strong>
              <button type="button" id="closeModalEliminados">✕</button>
            </div>
            <div id="modalEliminadosBody"></div>
          </div>
        </div>
      </div>
    </aside>
    <div class="otros-partidos-info-card">
      <h4>Datos Columna Otro Juego</h4>
      <div> <span><strong id="otrosPartidosInfo" class="totales-otros-card"></strong></span></div>
      <!-- Observaciones -->
      <div class="observaciones-container" id="gastosWrapper">
        <h3>Observaciones Del Mes</h3>
        <textarea id="obsMes"></textarea>

        <button id="saveObsBtn" class="guardar-observaciones">Guardar Observaciones</button>

      </div>
    </div>

    <!-- <div class="notes-card">
     
    </div> -->

  </main>

  <!-- VARIABLES GLOBALES -->
  <script>
    window.API_BASE = "backend";
    window.isAdmin = <?php echo isset($_SESSION['is_admin']) ? "true" : "false"; ?>;
  </script>

  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

  <!-- MAIN JS -->
  <script src="assets/js/main.js"></script>

  <!-- SCRIPT PERSONALIZADO PARA TABLA Y OBSERVACIONES -->


</body>