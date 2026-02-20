// public/public.js
const API_JSON = "/APORTES_FUTBOL/backend/public_data/listado_publico.php";
const API_PDF  = "../public/public/public_reportes/reporte_mes_publico.php";
const API_ELIMINADOS_PUBLICO = "/APORTES_FUTBOL/backend/public_data/get_eliminados_mes_publico.php";

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
  const r = await fetch(url);

  if (!r.ok) {
    document.getElementById("contenedorTabla").innerHTML = "<p>Error cargando datos</p>";
    return;
  }

  const data = await r.json();

  // ✅ pintar selector (después de tener data.otro_dia y days)
  renderSelectOtroDia(data, mes, anio);

  renderTablaPublic(data);

  // ✅ si ya había texto escrito, re-aplicar filtro después del render
  const inp = document.querySelector("#contenedorTabla #searchPublic");
  if (inp && inp.value.trim()) {
    aplicarFiltroPublico(document.getElementById("contenedorTabla"), inp.value);
  }

  renderTotales(data);
  renderObservaciones(data.observaciones);
  renderGastos(data, mes, anio);
  renderOtrosAportesPublico(data, mes, anio);
  renderOtrosPartidosPublico(data);
  await cargarEliminadosMes(mes, anio);

}





/* ==========================================================
    TABLA PRINCIPAL
========================================================== */

function renderTablaPublic(data) {
  const cont = document.getElementById("contenedorTabla");

  const dias          = data.dias_validos;
  const fechaEspecial = data.otro_dia;

  const totalCols = dias.length + 7;

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
  `;

  // --- CABECERAS (igual que antes, pero YA SIN public-search-row) ---
  html += `<tr>`;
  html += `<th>Nombres</th>`;
  html += `<th colspan="${dias.length + 1}">Días de los juegos</th>`;
  html += `<th colspan="2">Otros aportes</th>`;
  html += `<th>Total Mes</th>`;
  html += `<th>Saldo</th>`;
  html += `<th>Deudas</th>`;
  html += `</tr>`;

  html += `<tr class="encabezado-dos" ><th></th>`;
  dias.forEach(d => html += `<th>${d}</th>`);
  html += `<th>Otra Fecha (${fechaEspecial})</th>`;
  html += `<th>Tipo</th><th>Valor</th>`;
  html += `<th>Por Jugador</th>`;
  html += `<th>Tu Saldo</th>`;
  html += `<th>Tu Deuda</th>`;
  html += `</tr></thead><tbody>`;

  // ... resto igual ...


  /* ==========================================================
      FILAS POR JUGADOR
  ========================================================== */
  data.rows.forEach(row => {
    const deudas = row.deudas || {};
    const claseEliminado = row.activo === 0 ? "eliminado" : "";

    html += `<tr data-player="${row.id}" class="${claseEliminado}">`;
    html += `<td class="player-name">${escapeHtml(row.nombre)}</td>`;

    // DÍAS NORMALES
    row.dias.forEach((v, idx) => {
      const realArr = row.real_dias || [];
      const real    = realArr[idx] !== undefined ? Number(realArr[idx]) : Number(v || 0);
      const visible = Number(v || 0);

      const hayExcedente = real > visible && visible > 0;
      const diaNumero    = dias[idx];
      const hayDeuda     = deudas[diaNumero] === true;

      const consumoArr = row.consumo_dias || [];
      const consumo = Number(consumoArr[idx] || 0);
      const showPlus = consumo > 0;

      let prefix = "";
      if (hayDeuda) {
        prefix = `<span class="deuda-x-publica" title="Día no pagado">✖</span>`;
      }

      const plusHtml = showPlus
        ? `<span class="saldo-plus-public" data-saldo="${consumo}" title="Tomó del saldo: ${formatMoney(consumo)}">✚</span>`
        : "";

      if (hayExcedente) {
        html += `
          <td class="celda-aporte aporte-excedente"
              title="Aportó ${formatMoney(real)}"
              data-real="${real}">
              ${prefix} ${plusHtml} ⭐ ${formatMoney(visible)}
          </td>`;
      } else {
        html += `
          <td class="celda-aporte">
              ${prefix} ${plusHtml} ${visible ? formatMoney(visible) : ""}
          </td>`;
      }
    });

    // ---------- FECHA ESPECIAL ----------
    const realEsp    = Number(row.real_especial ?? 0);
    const visibleEsp = Number(row.especial ?? 0);

    const consumoEsp = Number(row.consumo_especial || 0);
    const plusEsp = consumoEsp > 0
      ? `<span class="saldo-plus-public" data-saldo="${consumoEsp}" title="Tomó del saldo: ${formatMoney(consumoEsp)}">✚</span>`
      : "";

    const hayExcEsp   = realEsp > visibleEsp && visibleEsp > 0;
    const hayDeudaEsp = deudas[fechaEspecial] === true;

    let prefixEsp = "";
    if (hayDeudaEsp) {
      prefixEsp = `<span class="deuda-x-publica" title="Día no pagado">✖</span>`;
    }

    if (hayExcEsp) {
      html += `
        <td class="celda-aporte aporte-excedente"
            title="Aportó ${formatMoney(realEsp)}"
            data-real="${realEsp}">
            ${prefixEsp} ${plusEsp} ⭐ ${formatMoney(visibleEsp)}
        </td>`;
    } else {
      html += `
        <td class="celda-aporte">
            ${prefixEsp} ${plusEsp} ${visibleEsp ? formatMoney(visibleEsp) : ""}
        </td>`;
    }

    // ---------- OTROS ----------
    let tiposHtml = "";
    let valorOtros = 0;

    if (row.otros?.length) {
      tiposHtml = row.otros
        .map(o => `${escapeHtml(o.tipo)} (${formatMoney(o.valor)})`)
        .join("<br>");
      valorOtros = row.otros.reduce((s, o) => s + Number(o.valor), 0);
    }

    html += `<td class="otros-tipos"><div class="cell-scroll">${tiposHtml}</div></td>`;
    html += `<td class="otros-valor">${valorOtros ? formatMoney(valorOtros) : ""}</td>`;

    // ---------- TOTAL MES Y SALDO ----------
    html += `<td class="total-por-jugador"><strong>${row.total_mes ? formatMoney(row.total_mes) : ""}</strong></td>`;
    html += `<td class="total-por-jugador">${row.saldo ? formatMoney(row.saldo) : ""}</td>`;

    // ---------- DEUDA ----------
    const diasDeuda = Number(row.total_deudas ?? 0);
    const lista = row.deudas_lista || [];

    if (diasDeuda > 0) {
      html += `
        <td class="columna-deuda">
          <strong>Debe: ${diasDeuda}</strong>
          <div class="cell-scroll deuda-fechas-public">
            ${lista.map(f => `<div class="deuda-fecha-item">${escapeHtml(f)}</div>`).join("")}
          </div>
        </td>`;
    } else {
      html += `<td class="columna-deuda sin-deuda"></td>`;
    }

    html += `</tr>`;
  });

  html += `</tbody>`;

  /* ==========================================================
      PIE DE TABLA
  ========================================================== */
  html += `<tfoot><tr><td><h2 class="tfoot-totals-names">Totales Diarios</h2></td>`;

  const totalsPorDia = Array(dias.length).fill(0);
  let totalEspecial = 0;
  let totalOtros = 0;
  let totalVisibleMes = 0;
  let totalSaldosMes = 0;

  data.rows.forEach(r => {
    r.dias.forEach((v, i) => totalsPorDia[i] += Number(v || 0));
    totalEspecial += Number(r.especial || 0);

    if (r.otros?.length) totalOtros += r.otros.reduce((s, o) => s + Number(o.valor), 0);
  });

  data.rows.forEach(r => {
    totalVisibleMes += Number(r.total_mes || 0);
    totalSaldosMes  += Number(r.saldo || 0);
  });

  totalsPorDia.forEach(v => {
    html += `<td class="total-footer-dias"><strong>${v ? formatMoney(v) : "0"}</strong></td>`;
  });

  html += `<td><strong>${totalEspecial ? formatMoney(totalEspecial) : "0"}</strong></td>`;
  html += `<td><h2 class="tfoot-totals-names">Total Otros</h2></td>`;
  html += `<td class="otros-valor"><strong>${totalOtros ? formatMoney(totalOtros) : "0"}</strong></td>`;
  html += `<td><strong>${totalVisibleMes ? formatMoney(totalVisibleMes) : "0"}</strong></td>`;
  html += `<td><strong>${totalSaldosMes ? formatMoney(totalSaldosMes) : "0"}</strong></td>`;
  html += `<td><strong></strong></td>`;
  html += `</tr></tfoot></table>`;

  cont.innerHTML = html;

  // ✅ activar buscador (SIEMPRE después del innerHTML)
  setupPublicSearch(cont);

  // ✅ activar highlight columnas (después de pintar la tabla)
  const tabla = cont.querySelector("table.planilla");
  if (tabla) setupHeaderColumnHighlight(tabla);

  // selección fila
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
          <label> ${escapeHtml(tipo)}</label>
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
      <label class="total-otros-label">Total Otros Aportes de este mes</label>
      <p class="total-otros-valor">${formatMoney(t.otros_mes_total || 0)}</p>
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

  // Parciales
  set("tParcialMes",  t.parcial_mes);
  set("tParcialAnio", t.parcial_anio);

  // Otros + saldo
  set("tOtrosMes",   t.otros_mes);
  set("tOtrosAnio",  t.otros_anio);
  set("tSaldoTotal", t.saldo_hasta_mes);

  // Eliminados
  set("tEliminadosMes",  t.eliminados_mes);
  set("tEliminadosAnio", t.eliminados_anio);

  // Estimados
  set("tEstimadoMes",  t.estimado_mes);
  set("tEstimadoAnio", t.estimado_anio);

  // Gastos
  set("tGastosMes",  t.gastos_mes);
  set("tGastosAnio", t.gastos_anio);

  // Finales
  set("tFinalMes",  t.final_mes);
  set("tFinalAnio", t.final_anio);
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


function renderOtrosPartidosPublico(data){
  const box = document.getElementById("otrosPartidosPublico");
  if (!box) return;

  const info = data.otros_partidos_info || {};
  const cantidad = Number(info.cantidad || 0);
  const total = Number(info.total_general || 0);
  const items = Array.isArray(info.items) ? info.items : [];

  let html = `<h3 class="partidos-extra-titulo">Otros Partidos (no Mié/Sáb)</h3>`;

  html += `
    <div>
      <div class="partidos-extra-subtitle">
          <span>Cantidad(${cantidad})</span>
      </div>
      <div class="partidos-extra-container-table">
        <table class="partidos-extra-table">
          <thead>
            <tr>
              <th>#</th>
              <th>Fecha</th>
              <th>Total</th>
            </tr>
          </thead>
          <tbody>
            ${items.map((it, i) => `
              <tr>
                <td>${i+1}</td>
                <td>${escapeHtml(it.fecha_label || it.fecha)}</td>
                <td class="partidos-extra-total"><strong>${formatMoney(it.efectivo_total || 0)}</strong></td>
              </tr>
            `).join("")}
          </tbody>
          </table>
          <div class="partidos-extra-tfoot-table">
             <p>Total otros partidos: <span>${formatMoney(total)}</span> </p>
          </div>
      </div>
        
    </div>
  `;
 if (!cantidad){
    html += `
      <div class="alert-no-otros-partidos">
    <p class="alert-no-otros-partidos-label">No Hay Registros de Otros Partidos Jugados (Días NO miércoles/sábado).</p>
     </div>
    `;
    
  }
  box.innerHTML = html;
}



let __eliminadosMesCache = null;


async function cargarEliminadosMes(mes, anio){
  const elMes  = document.getElementById("tEliminadosMes");
  const elAnio = document.getElementById("tEliminadosAnio");

  const btn   = document.getElementById("btnVerEliminados");
  const modal = document.getElementById("modalEliminados");
  const body  = document.getElementById("modalEliminadosBody");
  const close = document.getElementById("closeModalEliminados");

  if (!btn || !modal || !body || !close) return;

  // Bind 1 vez
  if (!btn.dataset.bound) {
    btn.dataset.bound = "1";

    btn.addEventListener("click", () => {
      const data = __eliminadosMesCache;

      if (!data || data.ok !== true) {
        body.innerHTML = `<div style="opacity:.85;">No hay información disponible de eliminados para este mes.</div>`;
        modal.classList.remove("hidden");
        return;
      }

      const items = Array.isArray(data.items) ? data.items : [];

      if (!items.length){
        body.innerHTML = `<div style="opacity:.85;">No hubo eliminados en este mes.</div>`;
      } else {

        const totMes  = Number(data.totales?.eliminados_mes || 0);
        const totAnio = Number(data.totales?.eliminados_anio || 0);
        const totSal  = Number(data.totales?.saldo_eliminados || 0);

        body.innerHTML = `
          <div class="table-mini-wrap">
            <table class="table-mini">
              <thead>
                <tr>
                  <th>Nombre</th>
                  <th>Fecha baja</th>
                  <th class="right">Total Mes</th>
                  <th class="right">Total Año</th>
                  <th class="right">Saldo</th>
                </tr>
              </thead>
              <tbody>
                ${items.map(it => `
                  <tr>
                    <td>${escapeHtml(it.nombre || "")}</td>
                    <td>${escapeHtml(it.fecha_baja || "")}</td>
                    <td class="right"><strong>${formatMoney(it.total_mes || 0)}</strong></td>
                    <td class="right">${formatMoney(it.total_anio || 0)}</td>
                    <td class="right">${formatMoney(it.saldo || 0)}</td>
                  </tr>
                `).join("")}
              </tbody>
              <tfoot>
                <tr>
                  <td colspan="2"><strong>Totales</strong></td>
                  <td class="right"><strong>${formatMoney(totMes)}</strong></td>
                  <td class="right"><strong>${formatMoney(totAnio)}</strong></td>
                  <td class="right"><strong>${formatMoney(totSal)}</strong></td>
                </tr>
              </tfoot>
            </table>
          </div>
        `;
      }

      modal.classList.remove("hidden");
    });

    function closeModal(){
      modal.classList.add("closing");
      setTimeout(() => {
        modal.classList.add("hidden");
        modal.classList.remove("closing");
      }, 180);
    }

    close.onclick = closeModal;
    modal.onclick = (e) => { if(e.target === modal) closeModal(); };
    document.addEventListener("keydown", (ev) => {
      if(ev.key === "Escape" && !modal.classList.contains("hidden")) closeModal();
    });
  }

  // Cargar data
  try {
    const r = await fetch(`${API_ELIMINADOS_PUBLICO}?mes=${mes}&anio=${anio}`, { cache:"no-store" });

    // si backend devuelve HTML/redirect, aquí lo detectas rápido:
    const ct = r.headers.get("content-type") || "";
    if (!ct.includes("application/json")) throw new Error("Respuesta no es JSON (posible redirect/auth)");

    const data = await r.json();
    __eliminadosMesCache = data;

    const totMes  = Number(data?.totales?.eliminados_mes || 0);
    const totAnio = Number(data?.totales?.eliminados_anio || 0);

    if (elMes)  elMes.textContent  = formatMoney(totMes);
    if (elAnio) elAnio.textContent = formatMoney(totAnio);

  } catch (err) {
    __eliminadosMesCache = { ok:false };
    if (elMes)  elMes.textContent  = formatMoney(0);
    if (elAnio) elAnio.textContent = formatMoney(0);
    console.warn("No se pudo leer eliminados_publico:", err);
  }
}