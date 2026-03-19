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
      <a class="export_public_pdf_butt">Exportar PDF</a>
    </div>
  </header>

  <section id="selectors" class="selectors">
    <label>Año</label>
    <select id="selectAnio" class="form-select"></select>

    <label>Mes</label>
    <select id="selectMes" class="form-select "></select>
  </section>
  <div class="otro-juego-public-picker">
    <label>Otra Fecha</label>
    <select id="selectOtroDia" class="form-select"></select>
  </div>

  <section id="contenedorTabla"></section>

  <section class="public-esporadicos-section">
    <details class="esporadicos-details" closed>
      <summary class="esporadicos-summary">
        <span>Aportes Esporádicos</span>
        <small>— se suman automáticamente a totales diarios, mensuales y anuales</small>
      </summary>

      <div class="esporadicos-toolbar public-readonly-toolbar">
        <span class="esp-hint">Solo informativo · Sin edición</span>
      </div>

      <div id="esporadicosWrapPublic"></div>

      <div class="esp-note">
        Los aportes esporádicos se suman automáticamente a los totales diarios, mensuales y anuales de la planilla principal.
      </div>
    </details>
  </section>

  <div class="cards-container">


    <!-- Nueva sección para mostrar gastos del mes / año -->
    <section id="gastosMesPublico"></section>
    <section id="otrosAportesPublico"></section>
    <section id="otrosPartidosPublico" class="otros-partidos-card"></section>
    <div id="aportantes-eliminados" class="totales-aportantes-eliminados">
      <h4>Aportantes Eliminados (Registros)</h4>

      <div class="eliminados-div-button">
        <div class="mini-help">
          <strong style="color: rgba(255, 196, 0, 0.952); text-shadow:-1px 1px 1px black;">-</strong>
          No Aparecen en Planilla <br>
          <strong style="color: rgba(255, 196, 0, 0.952); text-shadow:-1px 1px 1px black;">-</strong>
          Sus Aportes y Saldos Siguen Sumando en Totales Parciales. <br>
          <strong style="color: rgba(0, 202, 51, 0.95); text-shadow:-1px 1px 1px black;">-</strong>
          <strong style="color: rgba(0, 202, 51, 0.95); text-shadow:-1px 1px 1px black;">
            Para Ver Registros Haz Click en el Botón
          </strong>
        </div>

        <button type="button" id="btnVerEliminados" class="btn-ver-eliminados">Ver detalle</button>
      </div>
    </div>

    <!-- <hr style="opacity:.20; margin:1px 0;"> -->
    <div id="modalEliminados" class="modal hidden">
      <div class="modal-card">
        <div class="modal-head">
          <strong>Eliminados Este Mes</strong>
          <button type="button" id="closeModalEliminados">✖</button>
        </div>
        <div id="modalEliminadosBody"></div>
      </div>
    </div>

    <div class="parcial-totals-mini-help">
      <h6> Total Parcial Mes Incluye: </h6> <br>
      <strong style="color: rgba(255, 196, 0, 0.952); text-shadow:-1px 1px 1px black,-1px 1px 1px black,-1px 1px 1px black;">-<small>Aportes de Cada Partido (Miercoles / Sabado) de Ambas Planillas</small></strong><br>
      <strong style="color: rgba(255, 196, 0, 0.952); text-shadow:-1px 1px 1px black,-1px 1px 1px black,-1px 1px 1px black;">-<Small>Aportes de Los Jugadores Eliminados.</Small></strong>
      <strong style="color: rgba(255, 196, 0, 0.952); text-shadow:-1px 1px 1px black,-1px 1px 1px black,-1px 1px 1px black;"> Nota: <small> Estos Aportes se Pueden Ver en La Sección de
          <strong style="color:rgba(255, 196, 0, 0.952); text-shadow:-1px 1px 1px black,-1px 1px 1px black,-1px 1px 1px black,-1px 1px 1px black;;">
            << Aportantes Eliminados (Registros)>> <br>
          </strong> </small></strong>
      <strong style="color: rgba(0, 255, 64, 0.95); text-shadow:-1px 1px 1px black,-1px 1px 1px black,-1px 1px 1px black;">(Click en Ver Detalle).</strong> <br>
      <strong style="color: rgba(255, 196, 0, 0.952); text-shadow:-1px 1px 1px black,-1px 1px 1px black,-1px 1px 1px black;">-<small> Aportes de Columna (Otro Juego) de Ambas Planillas (Cada Dia Jugado);</small> </strong>
      <strong style="color: rgba(255, 196, 0, 0.952); text-shadow:-1px 1px 1px black,-1px 1px 1px black,-1px 1px 1px black;">
        Nota: <small> Estos Juegos Quedan Registrados En La Sección</small> </strong>
      <strong style="color:rgba(255, 196, 0, 0.952); text-shadow:-1px 1px 1px black,-1px 1px 1px black,-1px 1px 1px black,-1px 1px 1px black;;">
        << Datos Columna Otro Juego>> <br>
      </strong>
      <strong style="color: rgba(255, 196, 0, 0.952); text-shadow:-1px 1px 1px black,-1px 1px 1px black,-1px 1px 1px black;">-<small> Verifica Cada Ingreso de Estos Juegos Usando:
          <strong style="color: rgba(0, 255, 64, 0.95); text-shadow:-1px 1px 1px black,-1px 1px 1px black">Select Otro (Juego)
            <br> </strong>,-1px 1px 1px black;
          <small> en la Planilla Principal.</small>
    </div>
    <section id="public-totals-card">
      <h4 class="parcial-tot-title">Totales (COP)</h4>
      <div class="total-parcial-mini">
        <div class="tot-row">
          <span>Total Parcial Mes</span>
          <strong id="tParcialMes" class="totales-item-value">$ 0</strong>
        </div>

        <div class="tot-row">
          <span>Total Parcial Año</span>
          <strong id="tParcialAnio" class="totales-item-value">$ 0</strong>
        </div>
      </div>

      <!-- <hr style="opacity:.25; margin:6px 0;"> -->

      <div class="totales-gastos-mini">
        <div class="tot-row">
          <span>Gastos del Mes</span>
          <strong id="tGastosMes" class="totales-item-value gastos-value">$ 0</strong>
        </div>

        <div class="tot-row">
          <span>Gastos del Año</span>
          <strong id="tGastosAnio" class="totales-item-value gastos-value">$ 0</strong>
        </div>
      </div>

      <!-- <hr style="opacity:.25; margin:6px 0;"> -->

      <div class="totales-finales-mini">
        <div class="tot-row">
          <span>Total Estimado Final Mes</span>
          <strong id="tFinalMes" class="totales-item-value estimados-value">$ 0</strong>
        </div>

        <div class="tot-row">
          <span>Total Estimado Final Año</span>
          <strong id="tFinalAnio" class="totales-item-value estimados-value">$ 0</strong>
        </div>
      </div>

      <!-- <hr style="opacity:.25; margin:6px 0;"> -->

      <div class="otros-saldo-mini">
        <div class="tot-row">
          <span>Saldo Actual Mes</span>
          <strong id="tSaldoMes" class="totales-item-value saldos-value">$ 0</strong>
        </div>

        <div class="tot-row">
          <span>Saldos Acumulados</span>
          <strong id="tSaldoTotal" class="totales-item-value saldos-value">$ 0</strong>
        </div>

      </div>
      <div class="total-final-mini">
        <div class="tot-row">
          <span>Total Final Año (Con Saldos Acumulados)</span>
          <strong id="tTotalRealHastaFecha" class="totales-item-value final-value">$ 0</strong>
        </div>

      </div>
    </section>
    <div class="box-container-observ">
      <h2 id="title-observaciones">Observaciones</h2>
      <section id="observaciones" class="observaciones-box"></section>
    </div>
  </div>
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/js/bootstrap.bundle.min.js" integrity="sha384-MrcW6ZMFYlzcLA8Nl+NtUVF0sA7MsXsP1UyJoMp4YLEuNSfAP+JcXn/tWtIaxVXM" crossorigin="anonymous"></script>
  <script src="../assets/js/public.js"></script>
</body>

</html>