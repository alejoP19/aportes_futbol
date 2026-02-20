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

  <section id="contenedorTabla">
  </section>

  <div class="gast-tot-obs-container">
    <!-- Nueva sección para mostrar gastos del mes / año -->
    <section id="gastosMesPublico" class="observaciones-box"></section>
    <section id="otrosAportesPublico" class="observaciones-box"></section>
    <section id="totales" class="totals-card public-totals-card">
      <h4 class="parcial-tot-title">Totales (COP)</h4>

      <!-- 1) PARCIALES -->
      <div class="total-parcial-mini">
        <small class="parcial-totals-mini-help">
          Parciales: solo aportes del mes/año (sin saldos, sin otros aportes, sin eliminados).
        </small>

        <div class="tot-row">
          <span>
            Total Parcial del Mes
            <small class="op-hint">(Aportes activos del mes)</small>
          </span>
          <strong id="tParcialMes" class="totales-item-value">$ 0</strong>
        </div>

        <div class="tot-row">
          <span>
            Total Parcial del Año
            <small class="op-hint">(Aportes activos acumulado ≤ mes)</small>
          </span>
          <strong id="tParcialAnio" class="totales-item-value">$ 0</strong>
        </div>
      </div>

      <hr style="opacity:.25; margin:6px 0;">

      <!-- 2) OTROS + SALDO -->
      <div class="otros-saldo-mini">
        <div class="tot-row">
          <span>
            Total Otros Aportes del Mes
            <small class="op-hint">(Σ otros_aportes mes)</small>
          </span>
          <strong id="tOtrosMes" class="totales-item-value otros-span">$ 0</strong>
        </div>

        <div class="tot-row">
          <span>
            Total Otros Aportes del Año
            <small class="op-hint">(Σ otros_aportes acumulado ≤ mes)</small>
          </span>
          <strong id="tOtrosAnio" class="totales-item-value otros-span">$ 0</strong>
        </div>

        <div class="tot-row">
          <span>
            Total Saldo Actual Hasta Mes
            <small class="op-hint">(saldo vigente acumulado)</small>
          </span>
          <strong id="tSaldoTotal" class="totales-item-value otros-span">$ 0</strong>
        </div>
      </div>

      <hr style="opacity:.25; margin:6px 0;">

      <!-- 3) ELIMINADOS -->
      <div class="totales-aportantes-eliminados">
        <h4>Aportes de Jugadores Eliminados</h4>
        <div class="mini-help">
          No aparecen como activos en planilla, pero sus aportes siguen sumando en el estimado.
        </div>

        <div class="tot-row">
          <span>Total Eliminados del Mes <small class="op-hint">(Aportes inactivos mes)</small></span>
          <strong id="tEliminadosMes" class="totales-item-value">$ 0</strong>
        </div>

        <div class="tot-row">
          <span>Total Eliminados del Año <small class="op-hint">(Aportes inactivos acumulado ≤ mes)</small></span>
          <strong id="tEliminadosAnio" class="totales-item-value">$ 0</strong>
        </div>
        <button type="button" id="btnVerEliminados" class="btn-ver-eliminados">Ver detalle</button>
      </div>

      <hr style="opacity:.25; margin:6px 0;">

      <!-- 4) ESTIMADOS -->
      <div class="totales-estimado-mini">
        <div class="tot-row">
          <span>
            Total Estimado del Mes
            <small class="op-hint">(parcial + otros + saldo + eliminados)</small>
          </span>
          <strong id="tEstimadoMes" class="totales-item-value">$ 0</strong>
        </div>

        <div class="tot-row">
          <span>
            Total Estimado del Año
            <small class="op-hint">(parcial + otros + saldo + eliminados)</small>
          </span>
          <strong id="tEstimadoAnio" class="totales-item-value">$ 0</strong>
        </div>
      </div>

      <hr style="opacity:.25; margin:6px 0;">

      <!-- 5) GASTOS -->
      <div class="totales-gastos-mini">
        <div class="tot-row">
          <span>Gastos del Mes <small class="op-hint">(Σ gastos mes)</small></span>
          <strong id="tGastosMes" class="totales-item-value gastos-item">$ 0</strong>
        </div>

        <div class="tot-row">
          <span>Gastos del Año <small class="op-hint">(Σ gastos acumulado ≤ mes)</small></span>
          <strong id="tGastosAnio" class="totales-item-value gastos-item">$ 0</strong>
        </div>
      </div>

      <hr style="opacity:.25; margin:6px 0;">

      <!-- 6) FINALES -->
      <div class="totales-finales-mini">
        <div class="tot-row">
          <span>Total Final del Mes <small class="op-hint">(estimado - gastos)</small></span>
          <strong id="tFinalMes" class="totales-item-value">$ 0</strong>
        </div>

        <div class="tot-row">
          <span>Total Final del Año <small class="op-hint">(estimado - gastos)</small></span>
          <strong id="tFinalAnio" class="totales-item-value">$ 0</strong>
        </div>
      </div>
    </section>

    <section id="otrosPartidosPublico" class="otros-partidos-card"></section>
    <div class="box-container-observ">
      <h2 id="title-observaciones">Observaciones</h2>
      <section id="observaciones" class="observaciones-box">

      </section>
    </div>
  </div>


  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/js/bootstrap.bundle.min.js" integrity="sha384-MrcW6ZMFYlzcLA8Nl+NtUVF0sA7MsXsP1UyJoMp4YLEuNSfAP+JcXn/tWtIaxVXM" crossorigin="anonymous"></script>
  <script src="../assets/js/public.js"></script>


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
</body>

</html>