// public/public.js
const API_JSON = "../backend/public_data/listado_publico.php";
const API_PDF = "../backend/reportes/reporte_mes.php";
document.addEventListener("DOMContentLoaded", () => {
    cargarSelects();
    document.getElementById("selectAnio").addEventListener("change", cargarDatos);
    document.getElementById("selectMes").addEventListener("change", cargarDatos);
    document.getElementById("btnPDF").addEventListener("click", descargarPDF);
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
    renderTotales(data.totales);
    renderObservaciones(data.observaciones);
}

function renderTablaPublic(data) {
    const cont = document.getElementById("contenedorTabla");
    const dias = data.dias_validos; // array de números
    const fechaEspecial = data.fecha_especial; // numero 28

    // Cabecera
    let html = `<table class="planilla"><thead>`;
    html += `<tr>`;
    html += `<th>Nombres</th>`;
    html += `<th colspan="${dias.length + 1}">Días de los juegos</th>`; // +1 por fecha especial
    html += `<th colspan="2">Otros aportes</th>`;
    html += `<th>Total Mes</th>`;
    html += `</tr>`;

    // Segunda fila del thead con números de día y columnas
    html += `<tr><th></th>`;
    for (let i = 0; i < dias.length; i++) {
        html += `<th>${dias[i]}</th>`;
    }
    html += `<th>Fecha Especial (${fechaEspecial})</th>`;
    html += `<th>Tipo</th><th>Valor</th>`;
    html += `<th>Por Jugador</th>`;
    html += `</tr>`;

    html += `</thead><tbody>`;

    // Filas por jugador
    data.rows.forEach(row => {
        html += `<tr data-player="${row.id}">`;
        html += `<td class="player-name">${escapeHtml(row.nombre)}</td>`;

        // dias
        row.dias.forEach(v => {
            html += `<td>${v ? formatMoney(v) : ""}</td>`;
        });

        // especial
        html += `<td>${row.especial ? formatMoney(row.especial) : ""}</td>`;

        // otros (tipo(s))
        let tiposHtml = "";
        let valorOtros = 0;
        if (row.otros && row.otros.length) {
            tiposHtml = row.otros.map(o => `${escapeHtml(o.tipo)} (${formatMoney(o.valor)})`).join("<br>");
            valorOtros = row.otros.reduce((s, o) => s + Number(o.valor), 0);
        }
        html += `<td class="otros-tipos">${tiposHtml}</td>`;
        html += `<td class="otros-valor">${valorOtros ? formatMoney(valorOtros) : ""}</td>`;

        // total por jugador
        html += `<td class="total-por-jugador"><strong>${row.total_mes ? formatMoney(row.total_mes) : ""}</strong></td>`;

        html += `</tr>`;
    });

    html += `</tbody>`;

    // Pie con totales por dia (sumas en columns) y totales de otros
    html += `<tfoot><tr><td><strong>TOTAL DÍA</strong></td>`;
    // calculamos totales por dia
    const totalsPorDia = Array(dias.length).fill(0);
    let totalEspecial = 0;
    let totalOtros = 0;
    data.rows.forEach(r => {
        r.dias.forEach((v,i) => { totalsPorDia[i] += Number(v || 0); });
        totalEspecial += Number(r.especial || 0);
        if (r.otros && r.otros.length) totalOtros += r.otros.reduce((s,o)=>s+Number(o.valor),0);
    });
    totalsPorDia.forEach(v => html += `<td><strong>${v ? formatMoney(v) : "0"}</strong></td>`);
    html += `<td><strong>${totalEspecial ? formatMoney(totalEspecial) : "0"}</strong></td>`;
    html += `<td><strong>TOTAL OTROS</strong></td>`;
    html += `<td><strong>${totalOtros ? formatMoney(totalOtros) : "0"}</strong></td>`;
    html += `<td></td>`; // columna total por jugador (vacía aquí)
    html += `</tr></tfoot>`;

    html += `</table>`;
    cont.innerHTML = html;
}

function renderTotales(t) {
    document.getElementById("tDia").textContent = t.today ? formatMoney(t.today) : "";
    document.getElementById("tMes").textContent = t.month_total ? formatMoney(t.month_total) : "";
    document.getElementById("tAnio").textContent = t.year_total ? formatMoney(t.year_total) : "";
}

function renderObservaciones(text) {
    const el = document.getElementById("observaciones");
    el.textContent = text && text.trim() ? text : "Sin observaciones para este mes.";
}

function descargarPDF() {
    const mes = document.getElementById("selectMes").value;
    const anio = document.getElementById("selectAnio").value;
    // Abre el reporte existente para el mes (ajusta la ruta si tu export_pdf usa otro nombre)
    window.open(`${API_PDF}?mes=${mes}&anio=${anio}`, "_blank");
}

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
