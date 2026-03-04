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
        <details class="esporadicos-details" closed>
          <summary class="esporadicos-summary">
            Aportes Esporádicos
            <small>— se suman automáticamente a totales diarios, mensuales y anuales</small>
          </summary>

          <div class="esporadicos-toolbar">
            <button type="button" id="btnAddEspRow" class="btn-add-esp">+ Agregar fila</button>
            <span class="esp-hint">Checkbox = $3.000 · Click en valor para editar</span>
          </div>

          <div id="esporadicosWrap"></div>

          <div class="esp-note">
            Los aportes esporádicos se suman automáticamente a los totales diarios, mensuales y anuales.
          </div>
        </details>
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
          <div class="parcial-totals-mini-help">Total Parcial Mes Suma: <br>
            - Los Aportes de Cada Día (Miercoles / Sabado) <br>
            - Aportes de (Cada Dia) la Columna Otro Juego de Ambas Planillas; Seleciona Cada Dia de Los Registros en Tabla: Datos Columna Otro Juego para Corroborar. <br>

            - Incluye Aportes y Otros Aportes de Los Jugadores Eliminados; Estos se Pueden Ver en La Sección de Aportantes Eliminados Este Mes (Ver Detalle).


          </div>
          <div class="total-parcial-mini-items">
            <span>Total Parcial Mes </span>
            <small>(Incluye: Aportes Eliminados / Otros Aportes / Sin Saldo): </small>
            <strong id="tParcialMes" class="totales-item-value">$ 0</strong>
          </div>
          <div class="total-parcial-mini-items">
            <span>Total Parcial Año </span>
            <small>(Incluye: Aportes Eliminados / Otros Aportes / Sin Saldo): </small>
            <strong id="tParcialAnio" class="totales-item-value">$ 0</strong>
          </div>
        </div>
        <hr style="opacity:.25; margin:1px 0;">
        <div class="otros-saldo-mini">
          <div class="otros-aportes-items">
            <span>Otros Aportes Mes</span>
            <small>(Solo Informativo):</small>
            <strong id="tOtrosMes" class="totales-item-value">$ 0</strong>
          </div>

          <div class="otros-aportes-items">
            <span>Otros Aportes Año </span>
            <small>(Solo Informativo):</small>
            <strong id="tOtrosAnio" class="totales-item-value">$ 0</strong>
          </div>

        </div>
        <hr style="opacity:.25; margin:1px 0;">
        <div class="totales-aportantes-eliminados">
          <h4>Aportes De Aportantes Eliminados </h4>
          <div class="eliminados-div-button">
            <div class="mini-help"> - Aportantes Eliminados No Aparecen en Planilla <br> - Sus Aportes y Saldos Siguen Sumando en Totales Parciales. <br>
              - Pueden Verse En la Tabla Aportes Eliminados </div>
            <!-- <div> Solo Informativo Ya está Sumando en Total Parcial.</div> -->

            <!-- <span id="totalEliminadosMes"></span> -->
            <button type="button" id="btnVerEliminados" class="btn-ver-eliminados">Ver detalle</button>
          </div>
        </div>



        <hr style="opacity:.25; margin:1px 0;">
        <div class="totales-gastos-mini">
          <div class="totales-gastos-mini-item">
            <span>Gastos del Mes:</span>
            <strong id="tGastosMes" class="totales-item-value gastos-item">0</strong>
          </div>
          <div class="totales-gastos-mini-item">
            <span>Gastos del Año:</span>
            <strong id="tGastosAnio" class="totales-item-value  gastos-item">0</strong>
          </div>
        </div>

        <hr style="opacity:.25; margin:1px 0;">
        <div class="totales-finales-mini">
          <div class="totales-finales-mini-item">
            <span>Total Estimado Final Mes</span>
            <small>(Total Parcial Mes - gastos / Sumable Por Mes / Sin Saldos)</small>
            <strong id="tFinalMes" class="totales-item-value">$ 0</strong>
          </div>
          <div class="totales-finales-mini-item">
            <span>Total Estimado Final Año</span>
            <small>(Total Parcial Mes - gastos / Sumable Por Mes / Sin Saldos)</small>
            <strong id="tFinalAnio" class="totales-item-value">$ 0</strong>
          </div>
        </div>

       
          <div class="totales-estimado-mini">
            <div class="otros-aportes-items">
              <span>Saldos Actuales De Aportantes (De Este Mes)</span>
              <small>(Saldo Mes / Delta)</small>
              <strong id="tSaldoMes" class="totales-item-value">$ 0</strong>
            </div>

            <div class="otros-aportes-items">
              <span>Saldos Actuales De Aportantes (Acumulado Hasta Este Mes)</span>
              <small>(Saldo Acumulado / Foto)</small>
              <strong id="tSaldoTotal" class="totales-item-value">$ 0</strong>
            </div>
          

        </div>
        <hr style="opacity:.25; margin:1px 0;">
        <div class="totales-finales-mini">
          
           <!-- CON SALDO DEL MES (SUMABLE) -->
  <div class="totales-finales-mini-item">
    <span>Total Final Mes (con saldo del mes)</span>
    <small>(Total Estimado Final Mes + Saldo de este mes)</small>
    <strong id="tFinalMesConSaldoMes" class="totales-item-value">$ 0</strong>
  </div>

  <div class="totales-finales-mini-item">
    <span>Total Final Año (con saldo del mes)</span>
    <small>(Total Estimado Final Año + Suma de saldos mensuales)</small>
    <strong id="tFinalAnioConSaldoMes" class="totales-item-value">$ 0</strong>
  </div>

          <hr style="opacity:.25; margin:8px 0;">

  <!-- CON SALDO ACUMULADO (FOTO) -->
  <div class="totales-finales-mini-item">
    <span>Total Final Mes (con saldo acumulado)</span>
    <small>(Total Estimado Final Mes + Saldo acumulado)</small>
    <strong id="tFinalMesConSaldo" class="totales-item-value">$ 0</strong>
  </div>

  <div class="totales-finales-mini-item">
    <span>Total Final Año (con saldo acumulado)</span>
    <small>(Total Estimado Final Año + Saldo acumulado)</small>
    <strong id="tFinalAnioConSaldo" class="totales-item-value">$ 0</strong>
  </div>

  <p style="margin:6px 0 0; font-size:.85em; opacity:.85;">
    Nota: El “saldo acumulado” es una foto al corte. Si sumas los totales acumulados mes a mes, duplicas saldos anteriores.
    Para sumar meses usa “saldo del mes”.
  </p>
        </div>
        <hr style="opacity:.25; margin:1px 0;">
        <div class="totales-finales-mini">
          <div class="totales-finales-mini-item">
  <span>Total Final Año (suma de meses con saldo acumulado)</span>
  <small>(Cuadra si sumas los totales mensuales “con saldo acumulado”)</small>
  <strong id="final_anio_con_saldo_acumulado_sumado" class="totales-item-value">$ 0</strong>
</div>
<p>Nota: este valor duplica saldos anteriores; es solo para que cuadre con la suma manual mes a mes.</p>
        </div>
        <div id="modalEliminados" class="modal hidden">
          <div class="modal-card">
            <div class="modal-head">
              <strong>Eliminados Este Mes</strong>
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