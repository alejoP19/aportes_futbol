// public/public.js
const API_JSON = "/APORTES_FUTBOL/backend/public_data/listado_publico.php";
const API_PDF = "../public/public/public_reportes/reporte_mes_publico.php";

document.addEventListener("DOMContentLoaded", () => {
    cargarSelects();
    document.getElementById("selectAnio").addEventListener("change", cargarDatos);
    document.getElementById("selectMes").addEventListener("change", cargarDatos);
    cargarDatos();
});

function cargarSelects() {
    const selA = document.getElementById("selectAnio");
    const selM = document.getElementById("selectMes");
    selA.innerHTML = "";
    const actualY = new Date().getFullYear();
    for (let y = actualY; y >= actualY - 5; y--) {
        const op = document.createElement("option");
        op.value = y; op.textContent = y;
        if (y === actualY) op.selected = true;
        selA.appendChild(op);
    }

    const meses = ["Enero","Febrero","Marzo","Abril","Mayo","Junio","Julio","Agosto","Septiembre","Octubre","Noviembre","Diciembre"];
    selM.innerHTML = "";
    const actualM = new Date().getMonth()+1;
    meses.forEach((m, i) => {
        const op = document.createElement("option");
        op.value = i+1; op.textContent = m;
        if (i+1 === actualM) op.selected = true;
        selM.appendChild(op);
    });
}

async function cargarDatos() {
    const mes = document.getElementById("selectMes").value;
    const anio = document.getElementById("selectAnio").value;

    const url = `${API_JSON}?mes=${mes}&anio=${anio}`;
    const r = await fetch(url);
    if (!r.ok) {
        document.getElementById("contenedorTabla").innerHTML = "<p>Error cargando datos</p>";
        return;
    }
    const data = await r.json();
    renderTablaPublic(data);
    renderTotales(data);
    renderObservaciones(data.observaciones);
    renderGastos(data,mes,anio);
}


/* ==========================================================
    TABLA PRINCIPAL
========================================================== */

function renderTablaPublic(data) {
    const cont = document.getElementById("contenedorTabla");
    const dias = data.dias_validos;
    const fechaEspecial = data.fecha_especial;
    let html = `<table class="planilla"><thead>`;
   
   

    // ---------------- CABECERA 1 ----------------
    html += `<tr>`;
    html += `<th>Nombres</th>`;
    html += `<th colspan="${dias.length + 1}">Días de los juegos</th>`;
    html += `<th colspan="2">Otros aportes</th>`;
    html += `<th>Total Mes</th>`;
    html += `<th>Saldo</th>`;
    html += `<th>Deudas</th>`; // ← AQUÍ ANTES TENÍAS OTRO "Saldo"
    html += `</tr>`;

    // ---------------- CABECERA 2 ----------------
    html += `<tr><th></th>`;
    dias.forEach(d => html += `<th>${d}</th>`);
    html += `<th>Otra Fecha (${fechaEspecial})</th>`;
    html += `<th>Tipo</th><th>Valor</th>`;
    html += `<th>Por Jugador</th>`; // debajo de "Total Mes"
    html += `<th>Tu Saldo</th>`;            // debajo de "Saldo"
    html += `<th>Tu Deuda</th>`;    // debajo de "Tu Deuda"
    html += `</tr></thead><tbody>`;

    /* ==========================================================
        FILAS POR JUGADOR
    ========================================================== */
    data.rows.forEach(row => {

        const deudas = row.deudas || {}; // deudas SOLO del mes actual
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

            let prefix = "";
            if (hayDeuda) {
                prefix = `<span class="deuda-x-publica" title="Día no pagado">✖</span>`;
            }
   if (hayExcedente) {
    const title = `Aportó ${formatMoney(real)}`; // ✅ mantiene hover en desktop

    html += `
<td class="celda-aporte aporte-excedente"
    title="Aportó ${formatMoney(real)}"
    data-real="${real}">
    ${prefix} ⭐ ${formatMoney(visible)}
</td>
`;
} else {
    html += `
<td class="celda-aporte">
    ${visible ? formatMoney(visible) : ""}
    ${prefix}
</td>`;
}
    
        });
     // ---------- FECHA ESPECIAL ----------
const realEsp    = Number(row.real_especial ?? row.especial ?? 0);
const visibleEsp = Number(row.especial ?? 0);
const hayExcEsp  = realEsp > visibleEsp && visibleEsp > 0;
const hayDeudaEsp = deudas[fechaEspecial] === true;

let prefixEsp = "";
if (hayDeudaEsp) {
    prefixEsp = `<div class="deuda-publica" title="Día no pagado" style="color:red;font-size:15px;">●</div>`;
}

if (hayExcEsp) {
    html += `
<td class="celda-aporte aporte-excedente"
    title="Aportó ${formatMoney(realEsp)}"
    data-real="${realEsp}">
    ${prefixEsp} ⭐ ${formatMoney(visibleEsp)}
</td>
`;
} else {
    html += `
<td class="celda-aporte">
    ${prefixEsp}${visibleEsp ? formatMoney(visibleEsp) : ""}
</td>
`;
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

        html += `<td class="otros-tipos">${tiposHtml}</td>`;
        html += `<td class="otros-valor">${valorOtros ? formatMoney(valorOtros) : ""}</td>`;

        // ---------- TOTAL MES Y SALDO ----------
        html += `<td class="total-por-jugador"><strong>${row.total_mes ? formatMoney(row.total_mes) : ""}</strong></td>`;
        html += `<td class="total-por-jugador">${row.saldo ? formatMoney(row.saldo) : ""}</td>`;

        // -------------------------------------------------------------------
        // NUEVA COLUMNA — TOTAL DE DÍAS ADEUDADOS (ACUMULADO HASTA ESTE MES)
        // -------------------------------------------------------------------
        const diasDeuda = Number(row.total_deudas ?? 0);

        let colDeudaHtml = "";
        if (diasDeuda > 0) {
            colDeudaHtml = `<td class="columna-deuda">Debe ${diasDeuda} día${diasDeuda > 1 ? "s" : ""}</td>`;
        } else {
            colDeudaHtml = `<td class="columna-deuda sin-deuda"></td>`;
        }

        html += colDeudaHtml;

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

        if (r.otros?.length)
            totalOtros += r.otros.reduce((s, o) => s + Number(o.valor), 0);
    });

    data.rows.forEach(r => {
        totalVisibleMes += Number(r.total_mes || 0);
        totalSaldosMes  += Number(r.saldo || 0);
    });

    totalsPorDia.forEach(v =>
        html += `<td class="total-footer-dias"><strong>${v ? formatMoney(v) : "0"}</strong></td>`
    );

    html += `<td><strong>${totalEspecial ? formatMoney(totalEspecial) : "0"}</strong></td>`;
    html += `<td><h2 class="tfoot-totals-names">Total Otros</h2></td>`;
    html += `<td class="otros-valor"><strong>${totalOtros ? formatMoney(totalOtros) : "0"}</strong></td>`;
    html += `<td><strong>${totalVisibleMes ? formatMoney(totalVisibleMes) : "0"}</strong></td>`;
    html += `<td><strong>${totalSaldosMes ? formatMoney(totalSaldosMes) : "0"}</strong></td>`;
     html += `<td><strong></strong></td>`;
    html += `</tr></tfoot></table>`;

    cont.innerHTML = html;

    // animación
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
  Sombreado De Columnas
========================================================== */
function setupHeaderColumnHighlight(table) {
  if (!table || !table.tHead) return;

  const headRows = table.tHead.rows;
  const groupRow = headRows[0];                              // fila 1 (con colspan)
  const colRow   = headRows[headRows.length - 1];           // fila 2 (días/tipo/valor...)

  function clear() {
    table.querySelectorAll(".col-activa").forEach(el => el.classList.remove("col-activa", "col-especial"));
  }

  function paintCol(colIndex) {
    clear();
    Array.from(table.rows).forEach(row => {
      const cell = row.cells[colIndex];
      if (cell) cell.classList.add("col-activa");
    });

    // opcional: si es la columna "Otra Fecha (28)" darle estilo distinto
    const h = colRow.cells[colIndex];
    if (h && /\(28\)|otra\s*fecha|fecha\s*especial/i.test(h.textContent || "")) {
      Array.from(table.rows).forEach(row => {
        const cell = row.cells[colIndex];
        if (cell) cell.classList.add("col-especial");
      });
    }
  }

  function startIndexFromColspan(th) {
    let start = 0;
    for (const cell of th.parentElement.cells) {
      if (cell === th) break;
      start += cell.colSpan || 1;
    }
    return start;
  }

  // ✅ SOLO HEADER: si clic en fila 1 -> marcar PRIMERA columna del grupo
  // si clic en fila 2 -> marcar esa columna exacta
  table.tHead.addEventListener("click", (e) => {
    const th = e.target.closest("th");
    if (!th) return;

    const row = th.parentElement;
    if (row === groupRow) {
      const start = startIndexFromColspan(th);
      paintCol(start);                 // 👈 aquí está tu regla: “primera columna del grupo”
    } else {
      paintCol(th.cellIndex);          // clic en fila 2: columna exacta (día 6, tipo, valor, etc.)
    }

    e.stopPropagation();
  });

  // ✅ click fuera desactiva
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
            html += `<li><label>* ${escapeHtml(g.nombre)}</label><p>${formatMoney(g.valor)}</p></li>`;
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
        <label class="gastos-valor-label">Gastos Totales de ${nombreMes}</label>
        <p class="gastos-valor-valor">${formatMoney(t.gastos_mes || 0)}</p>

        <label class="gastos-valor-label">Gastos Totales del ${anio}</label>
        <p class="gastos-valor-valor">${formatMoney(t.gastos_anio || 0)}</p>
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
    const elMes   = document.getElementById("tMes");
    const elOtros = document.getElementById("tOtros");
    const elAnio  = document.getElementById("tAnio");

    if (elMes)   elMes.textContent   = t.month_total     ? formatMoney(t.month_total)     : "0";
    if (elOtros) elOtros.textContent = t.otros_mes_total ? formatMoney(t.otros_mes_total) : "0";
    if (elAnio)  elAnio.textContent  = t.year_total      ? formatMoney(t.year_total)      : "0";
}



// ==========================================================
// Tooltip universal (DESKTOP + ANDROID + iOS)
// ==========================================================
let tooltipActivo = null;

document.addEventListener("click", function (e) {
    const cell = e.target.closest(".aporte-excedente");
    if (!cell) return;

    const real = Number(cell.dataset.real || 0);
    if (!real) return;

    // eliminar tooltip previo
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

    tip.style.left =
        (window.scrollX + rect.left + rect.width / 2) + "px";
    tip.style.top =
        (window.scrollY + rect.top - 8) + "px";
    tip.style.transform = "translate(-50%, -100%)";

    // auto cerrar
    setTimeout(() => {
        if (tooltipActivo) {
            tooltipActivo.remove();
            tooltipActivo = null;
        }
    }, 1800);
});