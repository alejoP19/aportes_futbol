// public/public.js
const API_JSON = "/APORTES_FUTBOL/backend/public_data/listado_publico.php";
const API_PDF  = "../public/public/public_reportes/reporte_mes_publico.php";
const API_ELIMINADOS_PUBLICO = "/APORTES_FUTBOL/backend/public_data/get_eliminados_mes_publico.php";
const API_OTROS_PARTIDOS_PUBLICO = "/APORTES_FUTBOL/backend/public_data/get_otros_partidos_info_publico.php";
const API_ESP_PUBLIC_GET = "/APORTES_FUTBOL/backend/public_data/aportes_esporadicos_public/get_esporadicos_publico.php";

document.addEventListener("DOMContentLoaded", () => {
  cargarSelects();

  document.getElementById("selectAnio").addEventListener("change", cargarDatos);
  document.getElementById("selectMes").addEventListener("change", cargarDatos);

  const selOtro = document.getElementById("selectOtroDia");
  if (selOtro) {
    selOtro.addEventListener("change", () => {
      const mes  = document.getElementById("selectMes").value;
      const anio = document.getElementById("selectAnio").value;
      const d = parseInt(selOtro.value, 10);
      if (Number.isFinite(d)) setStoredOtroDia(mes, anio, d);
      cargarDatos(); // ✅ fuerza recarga y header sincroniza
    });
  }

  cargarDatos();
  
});

function cargarSelects() {
  const selA = document.getElementById("selectAnio");
  const selM = document.getElementById("selectMes");

  selA.innerHTML = "";
  const actualY = new Date().getFullYear();
  for (let y = actualY; y >= actualY - 5; y--) {
    const op = document.createElement("option");
    op.value = y;
    op.textContent = y;
    if (y === actualY) op.selected = true;
    selA.appendChild(op);
  }

  const meses = ["Enero","Febrero","Marzo","Abril","Mayo","Junio","Julio","Agosto","Septiembre","Octubre","Noviembre","Diciembre"];
  selM.innerHTML = "";
  const actualM = new Date().getMonth() + 1;
  meses.forEach((m, i) => {
    const op = document.createElement("option");
    op.value = i + 1;
    op.textContent = m;
    if (i + 1 === actualM) op.selected = true;
    selM.appendChild(op);
  });
}

async function cargarDatos() {
  const mes  = document.getElementById("selectMes").value;
  const anio = document.getElementById("selectAnio").value;
  const storedOtro = getStoredOtroDia(mes, anio);
  const otroParam = storedOtro ? `&otro=${storedOtro}` : "";

  const url = `${API_JSON}?mes=${mes}&anio=${anio}${otroParam}`;
  const r = await fetch(url, { cache: "no-store" });

  if (!r.ok) {
    document.getElementById("contenedorTabla").innerHTML = "<p>Error cargando datos</p>";
    return;
  }

  const data = await r.json();

  renderSelectOtroDia(data, mes, anio);

  const espData = await cargarAportesEsporadicosPublico(mes, anio, data.otro_dia);

  let otrosJuegosData = { ok:false, cantidad:0, total_general:0, items:[] };
  try {
    const rOtros = await fetch(`${API_OTROS_PARTIDOS_PUBLICO}?mes=${mes}&anio=${anio}`, {
      cache: "no-store"
    });
    otrosJuegosData = await rOtros.json();
  } catch (e) {
    console.warn("No se pudo cargar otros juegos:", e);
  }

  renderTablaPublic(data);
  renderTotales(data);
  renderObservaciones(data.observaciones);
  renderGastos(data, mes, anio);
  renderOtrosAportesPublico(data, mes, anio);
  renderOtrosPartidosPublico(otrosJuegosData);
  await cargarEliminadosMes(mes, anio);

  const inp = document.querySelector("#contenedorTabla #searchPublic");
  if (inp && inp.value.trim()) {
    aplicarFiltroPublico(document.getElementById("contenedorTabla"), inp.value);
  }
}

function renderTablaPublic(data) {
  const cont = document.getElementById("contenedorTabla");
  if (!cont) return;

  const dias = Array.isArray(data.dias_validos) ? data.dias_validos : [];
  const fechaEspecial = Number(data.otro_dia || 0);
  const tf = data.tfoot || {};

  let html = `
    <div class="public-search-bar">
      <div class="public-search-wrap">
        <span class="icono-buscar">🔍</span>
        <input id="searchPublic"
              type="text"
              class="form-control public-search-input"
              placeholder="Buscar jugador..."
              autocomplete="off">
        <button type="button" class="public-search-clear" title="Limpiar">×</button>
      </div>
    </div>

    <table class="planilla">
      <thead>
        <tr class="header-tr-one">
          <th>Nombres</th>
          <th colspan="${dias.length + 1}">Días de los juegos</th>
          <th colspan="2">Otros aportes</th>
          <th>Total Mes</th>
          <th>Saldo</th>
          <th>Deudas</th>
        </tr>
        <tr class="header-tr-two">
          <th></th>
          ${dias.map(d => `<th>${d}</th>`).join("")}
          <th>Otro juego (${String(fechaEspecial).padStart(2, "0")})</th>
          <th>Tipo</th>
          <th>Valor</th>
          <th>Por Jugador</th>
          <th>Tu Saldo</th>
          <th>Tu Deuda</th>
        </tr>
      </thead>
      <tbody>
  `;

  data.rows.forEach(row => {
    if (Number(row.activo) === 0) return;

    const deudas = row.deudas || {};

    html += `<tr data-player="${row.id}">`;
    html += `<td class="player-name">${escapeHtml(row.nombre)}</td>`;

    row.dias.forEach((visible, idx) => {
      const real = Number((row.real_dias || [])[idx] ?? visible ?? 0);
      const consumo = Number((row.consumo_dias || [])[idx] ?? 0);
      const diaNumero = dias[idx];
      const hayDeuda = deudas[diaNumero] === true;
      const hayExcedente = real > 3000 && Number(visible || 0) > 0;

      const deudaHtml = hayDeuda
        ? `<span class="deuda-x-publica" title="Día no pagado">✖</span>`
        : "";

      const plusHtml = consumo > 0
        ? `<span class="saldo-plus-public" data-saldo="${consumo}" title="Tomó del saldo: ${formatMoney(consumo)}">✚</span>`
        : "";

      if (hayExcedente) {
        html += `
          <td class="celda-aporte aporte-excedente" title="Aportó ${formatMoney(real)}" data-real="${real}">
            ${deudaHtml} ${plusHtml} ⭐ ${Number(visible || 0) ? formatMoney(visible) : ""}
          </td>
        `;
      } else {
        html += `
          <td class="celda-aporte">
            ${deudaHtml} ${plusHtml} ${Number(visible || 0) ? formatMoney(visible) : ""}
          </td>
        `;
      }
    });

    const realEsp = Number(row.real_especial || 0);
    const visibleEsp = Number(row.especial || 0);
    const consumoEsp = Number(row.consumo_especial || 0);
    const hayExcEsp = realEsp > 3000 && visibleEsp > 0;
    const hayDeudaEsp = deudas[fechaEspecial] === true;

    const deudaEsp = hayDeudaEsp
      ? `<span class="deuda-x-publica" title="Día no pagado">✖</span>`
      : "";

    const plusEsp = consumoEsp > 0
      ? `<span class="saldo-plus-public" data-saldo="${consumoEsp}" title="Tomó del saldo: ${formatMoney(consumoEsp)}">✚</span>`
      : "";

    if (hayExcEsp) {
      html += `
        <td class="celda-aporte aporte-excedente" title="Aportó ${formatMoney(realEsp)}" data-real="${realEsp}">
          ${deudaEsp} ${plusEsp} ⭐ ${visibleEsp ? formatMoney(visibleEsp) : ""}
        </td>
      `;
    } else {
      html += `
        <td class="celda-aporte">
          ${deudaEsp} ${plusEsp} ${visibleEsp ? formatMoney(visibleEsp) : ""}
        </td>
      `;
    }

    let tiposHtml = "";
    let valorOtros = 0;

    if (row.otros?.length) {
      tiposHtml = row.otros
        .map(o => `${escapeHtml(o.tipo)} (${formatMoney(o.valor)})`)
        .join("<br>");
      valorOtros = row.otros.reduce((s, o) => s + Number(o.valor || 0), 0);
    }

    html += `<td class="otros-tipos"><div class="cell-scroll">${tiposHtml}</div></td>`;
    html += `<td class="otros-valor">${valorOtros ? formatMoney(valorOtros) : ""}</td>`;

    html += `<td class="total-por-jugador"><strong>${row.total_mes ? formatMoney(row.total_mes) : ""}</strong></td>`;
    html += `<td class="col-saldo"><strong>${row.saldo ? formatMoney(row.saldo) : ""}</strong></td>`;

    const diasDeuda = Number(row.total_deudas ?? 0);
    const lista = row.deudas_lista || [];

    if (diasDeuda > 0) {
      html += `
        <td class="columna-deuda">
          <strong>Deudas: ${diasDeuda}</strong>
          <div class="cell-scroll deuda-fechas-public">
            ${lista.map(f => `<div class="deuda-fecha-item">${escapeHtml(f)}</div>`).join("")}
          </div>
        </td>`;
    } else {
      html += `<td class="columna-deuda sin-deuda"></td>`;
    }

    html += `</tr>`;
  });

  html += `</tbody><tfoot>`;

  // FILA 1
  html += `<tr class="tfoot-total-dia">`;
  html += `<td><strong>TOTAL DÍA</strong></td>`;

  (tf.totales_por_dia_footer || []).forEach(v => {
    html += `<td><strong>${Number(v || 0).toLocaleString("es-CO")}</strong></td>`;
  });

  html += `<td><strong>${Number(tf.total_otro_footer || 0).toLocaleString("es-CO")}</strong></td>`;
  html += `<td></td>`;
  html += `<td><strong>${Number(tf.total_otros_aportes_footer || 0).toLocaleString("es-CO")}</strong></td>`;
  html += `<td><strong>${Number(tf.totales_parcial_footer || 0).toLocaleString("es-CO")}</strong></td>`;
  html += `<td><strong>${Number(tf.saldo_total_footer || 0).toLocaleString("es-CO")}</strong></td>`;
  html += `<td></td>`;
  html += `</tr>`;

  // FILA 2
  html += `
    <tr class="tfoot-base-sin-elim">
      <td colspan="${dias.length + 4}">
        <p>TOTAL FINAL MES (Sin Aportes De Tabla Eliminados / Sin Otros Aportes de Ambas Tablas)</p>
        <div class="tfoot-base-sin-elim-note">
          Sumando esta cifra con los Aportes Eliminados del Mes saldrá el Total Mes (sin otros aportes de Ambas Tablas).
        </div>
      </td>
      <td><strong>${Number(tf.totales_base_sin_eliminados_footer || 0).toLocaleString("es-CO")}</strong></td>
      <td></td>
      <td></td>
    </tr>
  `;

  // FILA 3
  html += `
    <tr class="tfoot-final-mes">
      <td colspan="${dias.length + 4}">
        <p>TOTAL PARCIAL MES (Incluye Otros Aportes de Ambas Tablas)</p>
      </td>
      <td><strong>${Number(tf.totales_final_con_otros_footer || 0).toLocaleString("es-CO")}</strong></td>
      <td></td>
      <td></td>
    </tr>
  `;

  // FILA 4
  html += `
    <tr class="tfoot-final-info">
      <td colspan="${dias.length + 7}">
        ✔ Eliminados Del Mes Suman un Total de: <strong>$ ${Number(tf.eliminados_mes_total_footer || 0).toLocaleString("es-CO")}</strong>
      </td>
    </tr>
  `;

  html += `</tfoot></table>`;

  cont.innerHTML = html;

  setupPublicSearch(cont);

  const tabla = cont.querySelector("table.planilla");
  if (tabla) setupHeaderColumnHighlight(tabla);

  const filas = cont.querySelectorAll("tbody tr");
  filas.forEach(tr => {
    tr.addEventListener("click", () => {
      filas.forEach(f => f.classList.remove("fila-seleccionada"));
      tr.classList.add("fila-seleccionada");
    });
  });
}
/* ==========================================================
   BUSCADOR PÚBLICO (dentro del thead)
========================================================== */

function setupPublicSearch(cont) {
  const input = cont.querySelector("#searchPublic");
  const clearBtn = cont.querySelector(".public-search-clear");
  if (!input) return;

  // evitar que el click suba al THEAD y dispare el highlight
  ["click","mousedown","keydown","touchstart"].forEach(evt => {
    input.addEventListener(evt, e => e.stopPropagation());
    if (clearBtn) clearBtn.addEventListener(evt, e => e.stopPropagation());
  });

  function filtrar(q) {
    const query = (q || "").trim().toLowerCase();
    cont.querySelectorAll("tbody tr").forEach(tr => {
      const nameCell = tr.querySelector(".player-name");
      const nombre = (nameCell?.textContent || "").toLowerCase();
      tr.style.display = nombre.includes(query) ? "" : "none";
    });
  }

  input.addEventListener("input", () => filtrar(input.value));

  if (clearBtn) {
    clearBtn.addEventListener("click", () => {
      input.value = "";
      filtrar("");
      input.focus();
    });
  }
}

// helper para re-aplicar filtro cuando recargas mes/año
function aplicarFiltroPublico(cont, q) {
  const input = cont.querySelector("#searchPublic");
  if (input) input.value = q;

  const query = (q || "").trim().toLowerCase();
  cont.querySelectorAll("tbody tr").forEach(tr => {
    const nameCell = tr.querySelector(".player-name");
    const nombre = (nameCell?.textContent || "").toLowerCase();
    tr.style.display = nombre.includes(query) ? "" : "none";
  });
}

/* ==========================================================
  Sombreado De Columnas
========================================================== */


function setupHeaderColumnHighlight(table) {
  if (!table || !table.tHead) return;

  const headRows = table.tHead.rows;
  const groupRow = headRows[0];                  // fila con colspans (Nombres / Días / Otros / etc)
  const colRow   = headRows[headRows.length - 1]; // fila con columnas reales (4,7,11... Tipo, Valor...)

  // Devuelve la celda (th/td) que "cubre" el índice real de columna, respetando colSpan
  function cellAtColIndex(row, colIndex) {
    let acc = 0;
    for (const cell of row.cells) {
      const span = cell.colSpan || 1;
      if (colIndex >= acc && colIndex < acc + span) return cell;
      acc += span;
    }
    return null;
  }

  function clear() {
    table.querySelectorAll(".col-activa, .col-especial").forEach(el => {
      el.classList.remove("col-activa", "col-especial");
    });
  }

  function paintCol(colIndex) {
    clear();

    // Pintar toda la tabla (thead + tbody + tfoot) respetando colspans
    Array.from(table.rows).forEach(row => {
      const cell = cellAtColIndex(row, colIndex);
      if (cell) cell.classList.add("col-activa");
    });

    // Marcar especial si la columna clickeada corresponde a "Otra Fecha"
    const header = cellAtColIndex(colRow, colIndex);
    if (header && /\(28\)|otra\s*fecha|fecha\s*especial/i.test(header.textContent || "")) {
      Array.from(table.rows).forEach(row => {
        const cell = cellAtColIndex(row, colIndex);
        if (cell) cell.classList.add("col-especial");
      });
    }
  }

  // Índice real de inicio de un TH con colspan dentro de su fila
  function startIndexFromColspan(th) {
    let start = 0;
    for (const cell of th.parentElement.cells) {
      if (cell === th) break;
      start += cell.colSpan || 1;
    }
    return start;
  }

  table.tHead.addEventListener("click", (e) => {
    const th = e.target.closest("th");
    if (!th) return;

    const row = th.parentElement;

    // Si clic en la fila de grupos (colspan): pintar la primera columna real del grupo
    if (row === groupRow) {
      const start = startIndexFromColspan(th);
      paintCol(start);
    } else {
      // Si clic en la fila de columnas reales: usar el índice real de esa columna
      const colIndex = startIndexFromColspan(th); // aquí también sirve aunque colSpan sea 1
      paintCol(colIndex);
    }

    e.stopPropagation();
  });

  document.addEventListener("click", (e) => {
    if (!table.contains(e.target)) clear();
  });
}

/* ==========================================================
   OBSERVACIONES
========================================================== */

function renderObservaciones(text) {
  const el = document.getElementById("observaciones");
  el.textContent = text?.trim() ? text : "No hay Observaciones este mes...";
}

/* ==========================================================
   GASTOS
========================================================== */

function renderGastos(data, mes, anio) {
  const box = document.getElementById("gastosMesPublico");
  if (!box) return;

  const t = data.totales || {};
  const detalle = data.gastos_detalle || [];

  let html = `<h3 class="titulo-gastos">Gastos</h3>`;

  if (detalle.length) {
    html += `<ul class="lista-gastos">`;
    detalle.forEach(g => {
      html += `<li><label> ${escapeHtml(g.nombre)}</label><p>${formatMoney(g.valor)}</p></li>`;
    });
    html += `</ul>`;
  } else {
    html += "<p>No hay gastos registrados este mes.</p>";
  }

  const fecha = new Date(anio, mes - 1, 1);
  let nombreMes = fecha.toLocaleString("es-ES", { month: "long" });
  nombreMes = nombreMes.charAt(0).toUpperCase() + nombreMes.slice(1);

  html += `
    <div class="totales-gastos">
       <div class="totales-gastos-item">
      <label class="gastos-valor-label">Gastos Totales de ${nombreMes}</label>
      <p class="gastos-valor-valor">${formatMoney(t.gastos_mes || 0)}</p>
     </div>
      <div class="totales-gastos-item">
      <label class="gastos-valor-label">Gastos Totales del ${anio}</label>
      <p class="gastos-valor-valor">${formatMoney(t.gastos_anio || 0)}</p>
       </div>
    </div>
  `;

  box.innerHTML = html;
}

/* ==========================================================
   OTROS APORTES (CARD)
========================================================== */

function renderOtrosAportesPublico(data, mes, anio){
  const box = document.getElementById("otrosAportesPublico");
  if (!box) return;

  const detalle = Array.isArray(data.otros_detalle) ? data.otros_detalle : [];
  const t = data.totales || {};

  let html = `<h3 class="titulo-otros-aportes">Otros Aportes</h3>`;

  if (detalle.length){
    html += `<ul class="lista-otros">`;
    detalle.forEach(o => {
      const tipo  = o.tipo ?? "";
      const valor = Number(o.valor ?? 0);

      html += `
        <li>
          <label>${escapeHtml(tipo)}</label>
          <p>${formatMoney(valor)}</p>
        </li>
      `;
    });
    html += `</ul>`;
  } else {
    html += `<p>No hay otros aportes registrados este mes.</p>`;
  }

  html += `
    <div class="totales-otros-aportes">
      <div class="totales-otros-aportes-item">
        <label class="total-otros-label">Total Otros Aportes de este mes</label>
        <p class="total-otros-valor">${formatMoney(t.otros_mes || 0)}</p>
      </div>
    </div>
    <div class="totales-otros-aportes">
      <div class="totales-otros-aportes-item">
        <label class="total-otros-label">Total Otros Aportes del año</label>
        <p class="total-otros-valor">${formatMoney(t.otros_anio || 0)}</p>
      </div>
    </div>

  `;

  box.innerHTML = html;
}



/* ==========================================================
   PDF EXPORT
========================================================== */

document.addEventListener("DOMContentLoaded", () => {
  const exportPdf = document.querySelector(".export_public_pdf_butt");
  if (exportPdf) {
    exportPdf.addEventListener("click", e => {
      e.preventDefault();
      const mes = document.getElementById("selectMes").value;
      const anio = document.getElementById("selectAnio").value;

      window.open(
        `/APORTES_FUTBOL/public/public_reportes/export_pdf_publico.php?mes=${mes}&anio=${anio}`,
        "_blank"
      );
    });
  }
});

/* ==========================================================
   HELPERS
========================================================== */


function normMes(m){ return String(parseInt(m, 10)); }
function normAnio(a){ return String(parseInt(a, 10)); }
function getOtroKey(mes, anio){ return `public_otroDia_${normAnio(anio)}_${normMes(mes)}`; }

function getStoredOtroDia(mes, anio){
  const v = parseInt(localStorage.getItem(getOtroKey(mes, anio)) || "", 10);
  return Number.isFinite(v) ? v : null;
}
function setStoredOtroDia(mes, anio, dia){
  localStorage.setItem(getOtroKey(mes, anio), String(dia));
}


function formatMoney(v) {
  if (v === null || v === undefined || v === "") return "";
  return Number(v).toLocaleString("es-CO", {
    style: "currency",
    currency: "COP",
    maximumFractionDigits: 0
  });
}

function escapeHtml(str) {
  if (!str) return "";
  return str.replace(/[&<>"'`=\/]/g, s =>
    ({
      "&": "&amp;", "<": "&lt;", ">": "&gt;",
      '"': "&quot;", "'": "&#39;",
      "/": "&#x2F;", "`": "&#x60;", "=": "&#x3D;"
    })[s]
  );
}

/* ==========================================================
    TOTALES BOX (laterales)
========================================================== */

function renderTotales(data) {
  if (!data) return;
  const t = data.totales ?? {};

  const set = (id, val) => {
    const el = document.getElementById(id);
    if (el) el.textContent = formatMoney(Number(val || 0));
  };

  set("tParcialMes",  t.parcial_mes);
  set("tParcialAnio", t.parcial_anio);

  set("tOtrosMes",  t.otros_mes);
  set("tOtrosAnio", t.otros_anio);

  set("tGastosMes",  t.gastos_mes);
  set("tGastosAnio", t.gastos_anio);

  set("tFinalMes",  t.final_neto_mes);
  set("tFinalAnio", t.final_anio_neto);

  set("tSaldoMes",   t.saldo_mes);
  set("tSaldoTotal", t.saldo_total);
  set("tTotalRealHastaFecha", t.total_real_hasta_fecha);
  
    set("tEliminadosMes",  data?.tfoot?.eliminados_mes_total_footer || 0);
}

/* ==========================================================
   TOOLTIP (tu código igual)
========================================================== */

let tooltipActivo = null;

document.addEventListener("click", function (e) {
  const cell = e.target.closest(".aporte-excedente");
  if (!cell) return;

  const real = Number(cell.dataset.real || 0);
  if (!real) return;

  if (tooltipActivo) {
    tooltipActivo.remove();
    tooltipActivo = null;
  }

  const tip = document.createElement("div");
  tip.className = "tooltip-aporte";
  tip.textContent = `Aportó ${formatMoney(real)}`;

  document.body.appendChild(tip);
  tooltipActivo = tip;

  const rect = cell.getBoundingClientRect();

  tip.style.left = (window.scrollX + rect.left + rect.width / 2) + "px";
  tip.style.top  = (window.scrollY + rect.top - 8) + "px";
  tip.style.transform = "translate(-50%, -100%)";

  setTimeout(() => {
    if (tooltipActivo) {
      tooltipActivo.remove();
      tooltipActivo = null;
    }
  }, 1800);
});

function showTooltipAtEl(el, text) {
  if (!el || !text) return;

  if (tooltipActivo) {
    tooltipActivo.remove();
    tooltipActivo = null;
  }

  const tip = document.createElement("div");
  tip.className = "tooltip-aporte";
  tip.textContent = text;
  document.body.appendChild(tip);
  tooltipActivo = tip;

  const rect = el.getBoundingClientRect();
  tip.style.left = (window.scrollX + rect.left + rect.width / 2) + "px";
  tip.style.top  = (window.scrollY + rect.top - 8) + "px";
  tip.style.transform = "translate(-50%, -100%)";
}

function hideTooltip() {
  if (tooltipActivo) {
    tooltipActivo.remove();
    tooltipActivo = null;
  }
}

document.addEventListener("mouseover", (e) => {
  const exc = e.target.closest(".aporte-excedente");
  if (exc) {
    const real = Number(exc.dataset.real || 0);
    if (real) showTooltipAtEl(exc, `Aportó ${formatMoney(real)}`);
    return;
  }

  const plus = e.target.closest(".saldo-plus-public");
  if (plus) {
    const s = Number(plus.dataset.saldo || 0);
    if (s) showTooltipAtEl(plus, `Tomó del saldo: ${formatMoney(s)}`);
    return;
  }
});

document.addEventListener("mouseout", (e) => {
  const leavingExc  = e.target.closest(".aporte-excedente");
  const leavingPlus = e.target.closest(".saldo-plus-public");
  if (leavingExc || leavingPlus) hideTooltip();
});


function renderSelectOtroDia(data, mes, anio){
  const sel = document.getElementById("selectOtroDia");
  if (!sel) return;

  const diasValidos = Array.isArray(data.dias_validos) ? data.dias_validos : [];
  const daysInMonth = new Date(Number(anio), Number(mes), 0).getDate();

  // candidatos = todos los días que NO son miércoles/sábado (no están en dias_validos)
  const candidatos = [];
  for (let d = 1; d <= daysInMonth; d++){
    if (!diasValidos.includes(d)) candidatos.push(d);
  }

  sel.innerHTML = "";
  candidatos.forEach(d => {
    const op = document.createElement("option");
    op.value = String(d);
    op.textContent = `Día ${String(d).padStart(2,"0")}`;
    sel.appendChild(op);
  });

  // Seleccionar el guardado o el backend
  const stored = getStoredOtroDia(mes, anio);
  const target = stored || data.otro_dia;
  if (target) sel.value = String(target);
}


function renderOtrosPartidosPublico(data) {
  const box = document.getElementById("otrosPartidosPublico");
  if (!box) return;

  const cantidad = Number(data?.cantidad || 0);
  const total = Number(data?.total_general || 0);
  const items = Array.isArray(data?.items) ? data.items : [];

  let html = `<h3 class="partidos-extra-titulo">Datos Columna Otro Juego</h3>`;

  if (!cantidad) {
    html += `
      <div class="alert-no-otros-partidos">
        <p class="alert-no-otros-partidos-label">
          No Hay Registros de Otros Partidos Jugados (Días NO miércoles/sábado).
        </p>
      </div>
    `;
    box.innerHTML = html;
    return;
  }

  html += `
    <div>
      <div class="partidos-extra-subtitle">
        <span>Otros partidos (${cantidad})</span>
      </div>

      <div class="partidos-extra-container-table">
        <table class="partidos-extra-table">
          <thead>
            <tr>
              <th>#</th>
              <th>Aportante</th>
              <th>Fecha</th>
              <th>Tabla</th>
              <th>Valor</th>
            </tr>
          </thead>
          <tbody>
            ${items.map((it, i) => `
              <tr>
                <td>${i + 1}</td>
                <td>${escapeHtml(it.nombre || "")}</td>
                <td>${escapeHtml(it.fecha_label || it.fecha || "")}</td>
                <td>${escapeHtml(it.tabla || "")}</td>
                <td class="partidos-extra-total">
                  <strong>${formatMoney(it.efectivo_total || 0)}</strong>
                </td>
              </tr>
            `).join("")}
          </tbody>
        </table>

        </div>
        <div class="partidos-extra-tfoot-table">
          <p>Total otros partidos: <span>${formatMoney(total)}</span></p>
        </div>
    </div>
  `;

  box.innerHTML = html;
}

/* ================================
   ELIMINADOS PÚBLICO - IGUAL ADMIN
================================ */
window.__eliminadosMesCache = window.__eliminadosMesCache || {};

function keyElim(anio, mes) {
  return `${parseInt(anio, 10)}-${parseInt(mes, 10)}`;
}

async function fetchEliminadosMesPublico(mes, anio) {
  const mesN = parseInt(mes, 10);
  const anioN = parseInt(anio, 10);
  const url = `${API_ELIMINADOS_PUBLICO}?mes=${mesN}&anio=${anioN}`;

  const r = await fetch(url, { cache: "no-store" });
  const txt = await r.text();

  let data;
  try {
    data = JSON.parse(txt);
  } catch (e) {
    console.error("Respuesta NO JSON get_eliminados_mes_publico.php:", { url, status: r.status, txt });
    throw e;
  }
  return data;
}

function renderModalEliminadosPublico(data, mes, anio) {
  const modal = document.getElementById("modalEliminados");
  const body  = document.getElementById("modalEliminadosBody");
  if (!modal || !body) return;


  const players = Array.isArray(data.players) ? data.players : [];
  const rows = Array.isArray(data.rows) ? data.rows : [];

  if (!players.length) {
    body.innerHTML = `<div style="opacity:.85;">No Hubo Eliminados Este Mes.</div>`;
    modal.classList.remove("hidden");
    modal.classList.remove("closing");
    return;
  }

  const rowsByPlayer = new Map();
  for (const r of rows) {
    const k = String(r.jugador_id);
    if (!rowsByPlayer.has(k)) rowsByPlayer.set(k, []);
    rowsByPlayer.get(k).push(r);
  }

  const renderDeudasCell = (pp) => {
    const total = Number(pp.deudas_total || 0);
    const fechas = Array.isArray(pp.deudas_fechas) ? pp.deudas_fechas : [];
    if (!total) return `<span style="opacity:.6;">0</span>`;

    return `
      <div class="deuda-cell-eliminados">
        <div class="deuda-count">${total}</div>
        <div class="deuda-fechas-eliminados">${fechas.map(f => escapeHtml(f)).join("<br>")}</div>
      </div>
    `;
  };

  body.innerHTML = `
<div class="table-container-eliminados">
  <table class="table-mini-eliminados eliminados-detalle">
    <thead>
      <tr>
        <th style="width:40%;">Aportante</th>
        <th style="width:6%;">#</th>
        <th style="width:25%;">Fecha</th>
        <th style="width:14%;" class="right">Cantidad</th>
        <th style="width:10%;" class="right">Totales</th>
        <th style="width:10%;" class="right">Saldo</th>
        <th style="width:10%;" class="right">Deudas</th>
      </tr>
    </thead>

    <tbody>
      ${players.map(p => {
        const pr = rowsByPlayer.get(String(p.id)) || [];

        const baja = (p.fecha_baja || "");
        const bajaObj = baja ? new Date(baja + "T00:00:00") : null;
        const bajaMes  = bajaObj ? (bajaObj.getMonth() + 1) : null;
        const bajaAnio = bajaObj ? bajaObj.getFullYear() : null;
        const bajaInfo = (bajaMes && bajaAnio) ? `${bajaMes}/${bajaAnio}` : "sin fecha";
        const fechaBaja = escapeHtml(p.fecha_baja || "");

        const playerCell = `
          <div class="player-title-eliminados">
            ${escapeHtml(p.nombre || "")}
            <span class="baja-pill-eliminados">Baja: ${fechaBaja}</span>
          </div>
        `;

        if (!pr.length) {
          return `
            <tr class="row-empty-eliminados">
              <td class="player-cell-empty-eliminados">${playerCell}</td>
              <td colspan="4" style="opacity:.85;">
                Este aportante fue eliminado en ${bajaInfo}, pero no tiene aportes registrados este mes.<br>
                Si quieres ver sus aportes, <strong> revisa las planillas principales de los meses anteriores.</strong>
              </td>
              <td class="right-eliminados">${formatMoney(p.saldo_fin_mes || 0)}</td>
              <td class="right-eliminados">${renderDeudasCell(p)}</td>
            </tr>

            <tr class="player-total-eliminados">
              <td>Total Aportes(Mes)</td>
              <td colspan="3"></td>
              <td class="right-eliminados">${formatMoney(p.total_mes || 0)}</td>
              <td class="right-eliminados">${formatMoney(p.saldo_fin_mes || 0)}</td>
              <td></td>
            </tr>

            <tr class="player-sep-eliminados"><td colspan="7"></td></tr>
          `;
        }

        const rowsHtml = pr.map((r, i) => {
          const isLast = i === pr.length - 1;
         
          let cls = "row-normal-eliminados";
          if (r.kind === "otro") cls = "row-otro-aporte-eliminados";
          else if (r.kind === "otro_juego") cls = "row-otro-juego-eliminados";
          console.log(cls)
          const labelHtml = r.label ? `<small class="row-label-eliminados">${escapeHtml(r.label)}</small>` : "";

          const saldoHtml  = isLast ? `${formatMoney(p.saldo_fin_mes || 0)}` : "";
          const deudasHtml = isLast ? renderDeudasCell(p) : "";

          if (i === 0) {
            return `
              <tr class="player-sep-eliminados"><td colspan="7"></td></tr>
              <tr class="${cls}">
                <td rowspan="${pr.length}" class="player-cell-eliminados"> ${playerCell}</td>
                <td class="num-aporte_elim">${r.n}</td>
                <td class="fecha_aporte-elim">${escapeHtml(r.fecha || "")}${labelHtml}</td>
                <td class="right-eliminados">${formatMoney(r.cantidad || 0)}</td>
                <td class="right-eliminados">${formatMoney(r.total || 0)}</td>
                <td class="right-eliminados">${saldoHtml}</td>
                <td class="right-eliminados">${deudasHtml}</td>
              </tr>
            `;
          }

          return `
            <tr class="${cls}">
              <td class="num-aporte_elim">${r.n}</td>
              <td class="fecha_aporte-elim">${escapeHtml(r.fecha || "")}${labelHtml}</td>
              <td class="right-eliminados">${formatMoney(r.cantidad || 0)}</td>
              <td class="right-eliminados">${formatMoney(r.total || 0)}</td>
              <td class="right-eliminados">${saldoHtml}</td>
              <td class="right-eliminados">${deudasHtml}</td>
            </tr>
          `;
        }).join("");

        const footJugador = `
          <tr class="player-total-eliminados">
            <td>Total Aportes (Mes)</td>
            <td colspan="3"></td>
            <td class="right-eliminados">${formatMoney(p.total_mes || 0)}</td>
            <td class="right-eliminados">${formatMoney(p.saldo_fin_mes || 0)}</td>
            <td></td>
          </tr>
          <tr class="player-sep-eliminados"><td colspan="7"></td></tr>
        `;

        return rowsHtml + footJugador;
      }).join("")}
    </tbody>

    <tfoot>
      <tr class="total-general-eliminados">
        <td class="total-tfoot-eliminados">Total General</td>
        <td colspan="3"></td>
        <td class="right-eliminados">${formatMoney(data.totales?.total_general_aportes || 0)}</td>
        <td class="right-eliminados">${formatMoney(data.totales?.total_general_saldo || 0)}</td>
        <td></td>
      </tr>
    </tfoot>
  </table>
</div>
  `;

  modal.classList.remove("closing");
  modal.classList.remove("hidden");
}

(function initEliminadosModalPublicOnce(){
  const btn = document.getElementById("btnVerEliminados");
  if (!btn || btn.dataset.bound) return;
  btn.dataset.bound = "1";

  btn.addEventListener("click", async () => {
    const mesNow = parseInt(document.getElementById("selectMes").value, 10);
    const anioNow = parseInt(document.getElementById("selectAnio").value, 10);
    const k = keyElim(anioNow, mesNow);

    try {
      if (!window.__eliminadosMesCache[k]) {
        window.__eliminadosMesCache[k] = await fetchEliminadosMesPublico(mesNow, anioNow);
      }
      renderModalEliminadosPublico(window.__eliminadosMesCache[k], mesNow, anioNow);
    } catch (e) {
      console.error("Error eliminados público:", e);
      const modal = document.getElementById("modalEliminados");
      const body  = document.getElementById("modalEliminadosBody");
      if (body) body.innerHTML = `<div style="opacity:.85;">Error cargando eliminados. Revisa consola.</div>`;
      if (modal) {
        modal.classList.remove("closing");
        modal.classList.remove("hidden");
      }
    }
  });

  const close = document.getElementById("closeModalEliminados");

  document.addEventListener("click", (e) => {
    const modal = document.getElementById("modalEliminados");
    if (!modal) return;

    if (e.target === close || e.target === modal) {
      modal.classList.add("hidden");
      modal.classList.remove("closing");
    }
  });

  document.addEventListener("keydown", (e) => {
    if (e.key !== "Escape") return;
    const modal = document.getElementById("modalEliminados");
    if (!modal) return;
    modal.classList.add("hidden");
    modal.classList.remove("closing");
  });
})();

async function cargarEliminadosMes(mes, anio) {
  const mesN = parseInt(mes, 10);
  const anioN = parseInt(anio, 10);
  const k = keyElim(anioN, mesN);

  try {
    const data = await fetchEliminadosMesPublico(mesN, anioN);
    window.__eliminadosMesCache[k] = data;
  } catch (e) {
    window.__eliminadosMesCache[k] = { ok:false };
  }
}

async function cargarAportesEsporadicosPublico(mes, anio, otroDia) {
  const wrap = document.getElementById("esporadicosWrapPublic");
  if (!wrap) return null;

  try {
    const url = `${API_ESP_PUBLIC_GET}?mes=${mes}&anio=${anio}&otro=${otroDia || ""}&slots=10`;
    const r = await fetch(url, { cache: "no-store" });
    const txt = await r.text();

    let data = null;
    try {
      data = JSON.parse(txt);
    } catch (e) {
      console.error("Respuesta NO JSON esporádicos público:", { url, txt });
      wrap.innerHTML = `<div style="opacity:.85;">No se pudo cargar la tabla esporádica.</div>`;
      return null;
    }

    if (!r.ok || !data?.ok) {
      wrap.innerHTML = `<div style="opacity:.85;">No se pudo cargar la tabla esporádica.</div>`;
      return null;
    }

    renderTablaEsporadicosPublico(wrap, data);
    return data;
  } catch (e) {
    console.error("Error esporádicos público:", e);
    wrap.innerHTML = `<div style="opacity:.85;">No se pudo cargar la tabla esporádica.</div>`;
    return null;
  }
}

function renderTablaEsporadicosPublico(wrap, data) {
  const dias = Array.isArray(data.dias_validos) ? data.dias_validos : [];
  const anio = Number(data.anio);
  const mes = Number(data.mes);
  const otroDia = Number(data.otro_dia);
  const fechaOtro = data.fecha_otro;
  const rows = Array.isArray(data.rows) ? data.rows : [];
  const meta = data.meta_by_slot || {};
  const totals = data.totals_by_date || {};

  const fechasDias = dias.map(d => `${anio}-${String(mes).padStart(2, "0")}-${String(d).padStart(2, "0")}`);

  let html = `
    <div class="esp-public-wrap">
      <table class="esp-table">
        <thead>
          <tr>
            <th>#</th>
            ${dias.map(d => `<th>${d}</th>`).join("")}
            <th>Otra Fecha (${String(otroDia).padStart(2, "0")})</th>
            <th>Otro Aporte</th>
            <th>Nota</th>
          </tr>
        </thead>
        <tbody>
  `;

  rows.forEach(row => {
    const slot = Number(row.slot);
    const m = meta[slot] || {};
    const metaOtro = Number(m.otro_aporte || 0);
    const metaNota = m.nota || "";

    html += `<tr>`;
    html += `<td><strong>${slot}</strong></td>`;

    fechasDias.forEach(f => {
      const v = Number(row.dias?.[f] || 0);
      html += `<td>${cellEspHtmlReadOnly(v)}</td>`;
    });

    html += `<td>${cellEspHtmlReadOnly(Number(row.otro || 0))}</td>`;
    html += `<td><span class="esp-readonly-text">${metaOtro ? formatMoney(metaOtro) : "$0"}</span></td>`;
    html += `<td><span class="esp-readonly-note">${escapeHtml(metaNota || "—")}</span></td>`;
    html += `</tr>`;
  });

  html += `</tbody><tfoot class="esp-tfoot"><tr><td>Totales</td>`;

  fechasDias.forEach(f => {
    html += `<td><strong>${formatMoney(Number(totals[f] || 0))}</strong></td>`;
  });

  html += `<td><strong>${formatMoney(Number(totals[fechaOtro] || 0))}</strong></td>`;

  const totalOtrosMeta = Object.values(meta).reduce((acc, m) => acc + Number(m?.otro_aporte || 0), 0);
  html += `<td><strong>${formatMoney(totalOtrosMeta)}</strong></td>`;
  html += `<td></td>`;
  html += `</tr></tfoot></table></div>`;

  wrap.innerHTML = html;
}

function cellEspHtmlReadOnly(valor) {
  const v = Number(valor || 0);
  const chipClass = v > 0 ? "esp-chip" : "esp-chip zero";
  const chipText = v > 0 ? formatMoney(v) : "$0";

  return `
    <div class="esp-cell esp-readonly-cell">
      <span class="${chipClass}">${chipText}</span>
    </div>
  `;
}