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
    renderGastos(data,mes,anio)
     
}

function renderTablaPublic(data) {
    const cont = document.getElementById("contenedorTabla");
    const dias = data.dias_validos; // array de números
    const fechaEspecial = data.fecha_especial; // numero 28

    // Cabecera
    let html = `<table class="planilla"><thead>`;
    html += `<tr>`;
    html += `<th>Nombres</th>`;
    html += `<th colspan="${dias.length + 1}">Días de los juegos</th>`;
    html += `<th colspan="2">Otros aportes</th>`;
    html += `<th>Total Mes</th>`;
    html += `<th>Saldo</th>`; 
    html += `</tr>`;

    html += `<tr><th></th>`;
    for (let i = 0; i < dias.length; i++) {
        html += `<th>${dias[i]}</th>`;
    }
    html += `<th>Fecha Especial (${fechaEspecial})</th>`;
    html += `<th>Tipo</th><th>Valor</th>`;
    html += `<th>Por Jugador</th>`;
    html += `<th></th>`;
    html += `</tr>`;
    html += `</thead><tbody>`;

    // Filas por jugador
    data.rows.forEach(row => {
        html += `<tr data-player="${row.id}">`;
        html += `<td class="player-name">${escapeHtml(row.nombre)}</td>`;
        
    row.dias.forEach((v, idx) => {
    const realArr = row.real_dias || [];
    const real    = realArr[idx] !== undefined ? Number(realArr[idx]) : Number(v || 0);
    const visible = Number(v || 0);

    const hayExcedente = real > visible && visible > 0;

    if (hayExcedente) {
        const title = `Aportó ${formatMoney(real)}`;
       html += `
<td class="celda-aporte aporte-excedente" title="${title}">
    ⭐ ${formatMoney(visible)}
</td>`;

    } else {
       html += `<td class="celda-aporte">${visible ? formatMoney(visible) : ""}</td>`;


    }
});

const realEsp    = row.real_especial !== undefined ? Number(row.real_especial) : Number(row.especial || 0);
const visibleEsp = Number(row.especial || 0);
const hayExcEsp  = realEsp > visibleEsp && visibleEsp > 0;

if (hayExcEsp) {
    const titleEsp = `Aportó ${formatMoney(realEsp)}`;
    html += `
<td class="celda-aporte aporte-excedente" title="${titleEsp}">
    ⭐ ${formatMoney(realEsp)}
</td>`;
} else {
 html += `<td class="celda-aporte">${visibleEsp ? formatMoney(visibleEsp) : ""}</td>`;


}



        let tiposHtml = "";
        let valorOtros = 0;
        if (row.otros && row.otros.length) {
            tiposHtml = row.otros.map(o => `${escapeHtml(o.tipo)} (${formatMoney(o.valor)})`).join("<br>");
            valorOtros = row.otros.reduce((s, o) => s + Number(o.valor), 0);
        }

        html += `<td class="otros-tipos">${tiposHtml}</td>`;
       html += `<td class="otros-valor">${valorOtros ? formatMoney(valorOtros) : ""}</td>`;

       
       // total del mes
       html += `<td class="total-por-jugador"><strong>${row.total_mes ? formatMoney(row.total_mes) : ""}</strong></td>`;
       // ← AGREGAR ESTA LÍNEA:
       html += `<td class="total-por-jugador">${row.saldo ? formatMoney(row.saldo) : ""}</td>`;

        html += `</tr>`;
    });

    html += `</tbody>`;

    // Pie
    html += `<tfoot ><tr><td><strong>TOTAL DÍA</strong></td>`;
    const totalsPorDia = Array(dias.length).fill(0);
    let totalEspecial = 0;
    let totalOtros = 0;
    let totalVisibleMes = 0;
    let totalSaldosMes  = 0;

    data.rows.forEach(r => {
        r.dias.forEach((v, i) => totalsPorDia[i] += Number(v || 0));
        totalEspecial += Number(r.especial || 0);
        if (r.otros && r.otros.length)
            totalOtros += r.otros.reduce((s, o) => s + Number(o.valor), 0);
    });
data.rows.forEach(r => {
    totalVisibleMes += Number(r.total_mes || 0);
    totalSaldosMes  += Number(r.saldo || 0);
});
    totalsPorDia.forEach(v => html += `<td class="total-footer-dias"><strong>${v ? formatMoney(v) : "0"}</strong></td>`);

    html += `<td><strong>${totalEspecial ? formatMoney(totalEspecial) : "0"}</strong></td>`;
    html += `<td><strong>TOTAL OTROS</strong></td>`;
    html += `<td class="otros-valor"><strong>${totalOtros ? formatMoney(totalOtros) : "0"}</strong></td>`;
      html += `<td class="otros-valor"><strong>${totalVisibleMes ? formatMoney(totalVisibleMes) : "0"}</strong></td>`;
     html += `<td class="otros-valor"><strong>${totalSaldosMes ? formatMoney(totalSaldosMes) : "0"}</strong></td>`;
    html += `</tr></tfoot>`;
// total de aportes visibles del mes por jugador

    html += `</table>`;
    cont.innerHTML = html;

    // --- Animación ---
    const tabla = cont.querySelector("table");
    if (tabla) {
        tabla.classList.add("tabla-animada");
    }

    // --- Selección de filas al hacer clic ---
    const filas = cont.querySelectorAll("tbody tr");
    filas.forEach(tr => {
        tr.addEventListener("click", () => {
            // quitar selección previa
            filas.forEach(f => f.classList.remove("fila-seleccionada"));
            // activar selección
            tr.classList.add("fila-seleccionada");
        });
    });
}



function renderObservaciones(text) {
    const el = document.getElementById("observaciones");
    el.textContent = text && text.trim() ? text : "No hay Observaciones este mes...";

}

function renderGastos(data, mes, anio) {
    const box = document.getElementById("gastosMesPublico");
    if (!box) return;

    const t = data.totales || {};
    const detalle = data.gastos_detalle || [];

    let html = `<h3 class="titulo-gastos">Gastos</h3>`;

    // 🔹 Lista de gastos
    if (detalle.length > 0) {
        html += `<ul class="lista-gastos">`;
        detalle.forEach(g => {
            html += `
                <li>
                    <label>* ${escapeHtml(g.nombre)}</label>
                    <p>${formatMoney(g.valor)}</p>
                </li>
            `;
        });
        html += "</ul>";
    } else {
        html += "<p>No hay gastos registrados este mes.</p>";
    }

    // ------------------------------
    // 🔹 NOMBRE DEL MES (CORREGIDO)
    // ------------------------------
    const fecha = new Date(anio, mes - 1, 1);  
    let nombreMes = fecha.toLocaleString('es-ES', { month: 'long' });

    // Capitalizar
    nombreMes = nombreMes.charAt(0).toUpperCase() + nombreMes.slice(1);

    // ------------------------------
    // 🔹 Totales
    // ------------------------------
    html += `
        <label class="gastos-valor-label">
            Gastos Totales de ${nombreMes}
        </label>
        <p class="valor-gastos">${formatMoney(t.gastos_mes || 0)}</p>

        <label class="gastos-valor-label">
            Gastos Totales del ${anio}
        </label>
        <p class="valor-gastos">${formatMoney(t.gastos_anio || 0)}</p>
    `;

    box.innerHTML = html;
}


// export PDF
document.addEventListener("DOMContentLoaded", () => {
    const exportPdf = document.querySelector('.export_public_pdf_butt');

    if (exportPdf) {
        exportPdf.addEventListener('click', e => {
            e.preventDefault();

            const mes = document.getElementById("selectMes").value;
            const anio = document.getElementById("selectAnio").value;

            // Ruta correcta hacia tu archivo export_pdf_publico.php
            window.open(
                `/APORTES_FUTBOL/public/public_reportes/export_pdf_publico.php?mes=${mes}&anio=${anio}`,
                '_blank'
            );
        });
    }
});
    
    
          
      
 
function formatMoney(v) {
    if (!v && v !== 0) return "";
    return Number(v).toLocaleString('es-CO', { style: 'currency', currency: 'COP', maximumFractionDigits: 0 });
}

function escapeHtml(str) {
    if (!str) return "";
    return str.replace(/[&<>"'`=\/]/g, function(s) {
        return ({
            '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
            '/': '&#x2F;', '`': '&#x60;', '=': '&#x3D;'
        })[s];
    });
}


function renderTotales(data) {
    // data puede ser:
    // - un objeto con .totales (obj con propiedades)
    // - un objeto con .totales_mes, .totales_anio, .totales_otros
    // - un objeto donde totales_mes / totales_anio son numbers
    // - o undefined (en cuyo caso intentamos calcular desde data.rows)

    if (!data) {
        console.warn("⚠ renderTotales: no llegó data");
        return;
    }

    // 1) intentar extraer de formas comunes
    const tObj = data.totales ?? null; // forma antigua: data.totales.{total_mes,...}
    const tMesField = data.totales_mes ?? data.totalesMes ?? null;
    const tAnioField = data.totales_anio ?? data.totalesAnio ?? null;
    const tOtrosField = data.totales_otros ?? data.totalesOtros ?? null;

    // valores finales a mostrar (números)
    let totalMes = 0;
    let totalAnio = 0;
    let totalOtros = 0;

    // 2) si data.totales es un objeto con propiedades
if (tObj && typeof tObj === "object") {

    // total del mes
    totalMes = Number(
        tObj.month_total ??
        tObj.total_mes ??
        tObj.tMes ??
        tObj.total_mes_dia ??
        0
    ) || 0;

    // total del año (tu JSON trae year_total)
    totalAnio = Number(
        tObj.year_total ??     // ← ESTE ES EL BUENO
        tObj.total_anio ??
        tObj.tAnio ??
        0
    ) || 0;

    // total otros aportes
    totalOtros = Number(
        tObj.otros_mes_total ??  // ← tu JSON trae esto
        tObj.total_otros ??
        tObj.tOtros ??
        0
    ) || 0;
}
 else {
        // 3) si vienen campos top-level (números o strings)
        if (tMesField !== null && tMesField !== undefined) {
            // puede ser objeto o número; si es objeto, tratar de extraer value
            if (typeof tMesField === "object") {
                totalMes = Number(tMesField.total_mes ?? tMesField.total_mes_dia ?? Object.values(tMesField)[0] ?? 0) || 0;
            } else {
                totalMes = Number(tMesField) || 0;
            }
        }

        if (tAnioField !== null && tAnioField !== undefined) {
            if (typeof tAnioField === "object") {
                totalAnio = Number(tAnioField.total_anio ?? Object.values(tAnioField)[0] ?? 0) || 0;
            } else {
                totalAnio = Number(tAnioField) || 0;
            }
        }

        if (tOtrosField !== null && tOtrosField !== undefined) {
            if (typeof tOtrosField === "object") {
                totalOtros = Number(tOtrosField.total_otros ?? Object.values(tOtrosField)[0] ?? 0) || 0;
            } else {
                totalOtros = Number(tOtrosField) || 0;
            }
        }
    }

    // 4) Fallback: si no hay totales en la respuesta, intentar calcular desde data.rows
    if ((!totalMes || totalMes === 0) && Array.isArray(data.rows) && data.rows.length) {
        // asumiendo que cada row tiene total_mes u aporte por día: buscamos campos comunes
        totalMes = 0;
        data.rows.forEach(r => {
            // posibles propiedades: total_mes, totalMes, aporte_principal, total
            const v = Number(r.total_mes ?? r.totalMes ?? r.total ?? r.aporte_principal ?? 0) || 0;
            totalMes += v;
        });
    }

    if ((!totalOtros || totalOtros === 0) && Array.isArray(data.rows) && data.rows.length) {
        // calcular total 'otros' sumando row.otros si existen
        let s = 0;
        data.rows.forEach(r => {
            if (r.otros && Array.isArray(r.otros)) {
                s += r.otros.reduce((acc, o) => acc + (Number(o.valor) || 0), 0);
            }
        });
        if (s > 0) totalOtros = s;
    }

    // 5) si aún no hay totalAnio, intentar deducir sumando por año desde rows (si rows incluye año info)
    // (esto es un intento genérico; preferible que el backend lo devuelva)
    if ((!totalAnio || totalAnio === 0) && Array.isArray(data.rows) && data.rows.length) {
        // no intentaremos calcular por año a menos que rows contengan aporte_anio o similar
        let s = 0;
        let found = false;
        data.rows.forEach(r => {
            const v = Number(r.aporte_anio ?? r.total_anio ?? 0) || 0;
            if (v) { found = true; s += v; }
        });
        if (found) totalAnio = s;
    }

    // 6) Escribir en DOM (IDs: tMes, tOtros, tAnio)
    const elMes = document.getElementById("tMes");
    const elOtros = document.getElementById("tOtros");
    const elAnio = document.getElementById("tAnio");

    if (elMes) elMes.textContent = totalMes ? formatMoney(totalMes) : "0";
    if (elOtros) elOtros.textContent = totalOtros ? formatMoney(totalOtros) : "0";
    if (elAnio) elAnio.textContent = totalAnio ? formatMoney(totalAnio) : "0";
}










