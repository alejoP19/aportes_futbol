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


      <div class="showing-otros-aportes">
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

        <div class="parcial-totals-mini-help">
          <h6> Total Parcial Mes Incluye: </h6> <br>
          <strong style="color: rgba(255, 196, 0, 0.952); text-shadow:-1px 1px 1px black,-1px 1px 1px black;">-</strong> Aportes de Cada Partido (Miercoles / Sabado) de Ambas Planillas <br>
          <strong style="color: rgba(255, 196, 0, 0.952); text-shadow:-1px 1px 1px black,-1px 1px 1px black;">-</strong> Aportes de Los Jugadores Eliminados.<br> <br>
          <strong style="color: rgba(255, 196, 0, 0.952); text-shadow:-1px 1px 1px black,-1px 1px 1px black;"> Nota: </strong>
          Estos se Pueden Ver en La Sección de Aportantes Eliminados (Registros) <strong style="color: rgba(0, 255, 64, 0.95); text-shadow:-1px 1px 1px black,-1px 1px 1px black;">(Click en Ver Detalle).</strong> <br><br>
          <strong style="color: rgba(255, 196, 0, 0.952); text-shadow:-1px 1px 1px black,-1px 1px 1px black;">-</strong> Aportes de Columna (Otro Juego) de Ambas Planillas (Cada Dia Jugado); <br><br>
          <strong style="color: rgba(255, 196, 0, 0.952); text-shadow:-1px 1px 1px black,-1px 1px 1px black;">
            Nota: </strong> Estos Juegos Quedan Registrados En La Sección
          <strong style="color:rgba(255, 196, 0, 0.952); text-shadow:-1px 1px 1px black,-1px 1px 1px black;">
            << Datos Columna Otro Juego>>
          </strong>,
          Verifica Cada Ingreso de Estos Juegos Usando el
          <strong style="color: rgba(0, 255, 64, 0.95); text-shadow:-1px 1px 1px black,-1px 1px 1px black;">Select Otro (Juego)</strong> en la Planilla Principal. <br><br>
        </div>

        <div class="total-parcial-mes-card">
          <span>Total Parcial Mes </span>
          <small>(Activos + Eliminados + Otros Aportes / Sin Saldo): </small>
          <strong id="tParcialMes" class="totales-parcials-item-value">$ 0</strong>
        </div>
        <div class="total-parcial-año-card">
          <span>Total Parcial Año </span>
          <small>(Activos + Eliminados + Otros Aportes / Sin Saldo): </small>
          <strong id="tParcialAnio" class="totales-parcials-item-value">$ 0</strong>
        </div>


        <hr style="opacity:.25; margin:1px 0;">
        <div class="showing-totales-gastos">
          <div class="showing-totales-gastos-item">
            <span>Gastos del Mes:</span>
            <strong id="tGastosMes" class="gastos-item">0</strong>
          </div>
          <div class="showing-totales-gastos-item">
            <span>Gastos del Año:</span>
            <strong id="tGastosAnio" class="gastos-item">0</strong>
          </div>
        </div>

        <hr style="opacity:.25; margin:1px 0;">
        <div class="showing-totales-estimados">
          <div class="showing-totales-estimados-item">
            <span>Total Estimado Final Mes</span>
            <small>(Total Parcial Mes - gastos / Sumable Por Mes / Sin Saldos)</small>
            <strong id="tFinalMes" class="totales-blocks-item-value">$ 0</strong>
          </div>
          <div class="showing-totales-estimados-item">
            <span>Total Estimado Final Año</span>
            <small>(Total Parcial Mes - gastos / Sumable Por Mes / Sin Saldos)</small>
            <strong id="tFinalAnio" class="totales-blocks-item-value">$ 0</strong>
          </div>
        </div>
        <hr style="opacity:.25; margin:1px 0;">
        <div class="showing-saldos">
          <div class="showing-saldos-item ">
            <span>Saldo Actual Mes</span>
            <small>(Saldos Generados Este Mes)</small>
            <strong id="tSaldoMes" class="totales-blocks-item-value saldo-mes">$ 0</strong>
          </div>
          <div class="showing-saldos-item">
            <span>Saldos Acumulados</span>
            <small>(Saldos Generados Este Mes + Heredados Meses Anteriores)</small>
            <strong id="tSaldoTotal" class="totales-blocks-item-value saldo-acumulado">$ 0</strong>
          </div>
        </div>

        <hr style="opacity:.25; margin:1px 0;">
        <div class="showing-total-final">
          <div class="showing-total-final-items">
            <span>Total Final Año (Con Saldos Acumulados)</span>
            <small>(Total Estimado Final Año + Saldo acumulado)</small>
            <strong id="tTotalRealHastaFecha" class="totales-blocks-item-value">$ 0</strong>
          </div>
        </div>
        <hr style="opacity:.25; margin:1px 0;">
      </div>

      <hr style="opacity:.25; margin:1px 0;">
      <div class="showing-total-final">
        <div class="showing-total-final-items">
          <span>Caja General Acumulada</span>
          <small>(Suma de cierres reales de años anteriores + total real del año actual)</small>
          <strong id="tCajaGeneralAcumulada" class="totales-blocks-item-value"></strong>
        </div>
      </div>

      <hr style="opacity:.25; margin:8px 0;">
      <div class="showing-otros-aportes">
        <div class="showing-otros-aportes-items">
          <span>Otros Aportes Mes</span>
          <small>(Solo Informativo):</small>
          <strong id="tOtrosMes" class="totales-blocks-item-value">$ 0</strong>
        </div>

        <div class="showing-otros-aportes-items">
          <span>Otros Aportes Año </span>
          <small>(Solo Informativo):</small>
          <strong id="tOtrosAnio" class="totales-blocks-item-value">$ 0</strong>
        </div>

      </div>
    </aside>

    <aside class="right-card-on-laptop">
      <div class="otros-partidos-info-card">
        <h4>Datos Columna Otro Juego</h4>
        <div> <span><strong id="otrosPartidosInfo" class="totales-otros-card"></strong></span></div>
      </div>

      <hr style="opacity:.25; margin:8px 0;">
      <div class="totales-aportantes-eliminados">
        <h4>Aportantes Eliminados (Registros)</h4>
        <div class="eliminados-div-button">
          <div class="mini-help">
            <strong style="color: rgba(255, 196, 0, 0.952); text-shadow:-1px 1px 1px black;">-</strong>
            No Aparecen en Planilla <br>
            <strong style="color: rgba(255, 196, 0, 0.952); text-shadow:-1px 1px 1px black;">-</strong>
            Sus Aportes y Saldos Siguen Sumando en Totales Parciales. <br>
            <strong style="color: rgba(0, 202, 51, 0.95);  text-shadow:-1px 1px 1px black;">-</strong>
            <strong style="color: rgba(0, 202, 51, 0.95); text-shadow:-1px 1px 1px black;">
              Para Ver Registros Haz CLick en el Botón</strong>
          </div>
          <!-- <div> Solo Informativo Ya está Sumando en Total Parcial.</div> -->

          <!-- <span id="totalEliminadosMes"></span> -->
          <button type="button" id="btnVerEliminados" class="btn-ver-eliminados">Ver detalle</button>
        </div>
      </div>
      <hr style="opacity:.20; margin:1px 0;">
      <div id="modalEliminados" class="modal hidden">
        <div class="modal-card">
          <div class="modal-head">
            <strong>Eliminados Este Mes</strong>
            <button type="button" id="closeModalEliminados">✕</button>
          </div>
          <div id="modalEliminadosBody"></div>
        </div>
      </div>
      <div id="modalTodosEliminados" class="modal hidden">
        <div class="modal-card-ver-elim">
          <div class="modal-head-ver-elim">
            <strong>Aportantes Eliminados (Todos)</strong>
            <button type="button" id="closeModalTodosEliminados" >✕</button>
          </div>
          <div id="modalTodosEliminadosBody"></div>
        </div>
      </div>

      <!-- Observaciones -->
      <div class="observaciones-container" id="gastosWrapper">
        <h3>Observaciones Del Mes</h3>
        <textarea id="obsMes"></textarea>

        <button id="saveObsBtn" class="guardar-observaciones">Guardar Observaciones</button>

      </div>

    </aside>

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