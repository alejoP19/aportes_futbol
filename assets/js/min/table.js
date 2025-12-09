// ==============================
// table.js
// Render de tabla pública
// ==============================

import { formatMoney, escapeHtml } from "./utils.js";

export function renderTablaPublic(data) {
    const cont = document.getElementById("contenedorTabla");
    const dias = data.dias_validos;
    const fechaEspecial = data.fecha_especial;

    let html = `<table class="planilla"><thead>`;

    html += `
<tr>
    <th>Nombres</th>
    <th colspan="${dias.length + 1}">Días de los juegos</th>
    <th colspan="2">Otros aportes</th>
    <th>Total Mes</th>
    <th>Saldo</th>
</tr>`;

    html += `<tr><th></th>`;
    dias.forEach(d => html += `<th>${d}</th>`);
    html += `<th>Fecha Especial (${fechaEspecial})</th>`;
    html += `<th>Tipo</th><th>Valor</th>`;
    html += `<th>Por Jugador</th><th>Saldo</th>`;
    html += `</tr></thead><tbody>`;

    // ---- Filas
    data.rows.forEach(row => {
        html += `<tr data-player="${row.id}">`;
        html += `<td class="player-name">${escapeHtml(row.nombre)}</td>`;

        // DÍAS NORMALES
        row.dias.forEach((v, idx) => {
            const realArr = row.real_dias || [];
            const real = realArr[idx] ?? Number(v || 0);
            const visible = Number(v || 0);

            const excede = real > visible && visible > 0;

            if (excede) {
                html += `
<td class="celda-aporte aporte-excedente" title="Aportó ${formatMoney(real)}">
    ⭐ ${formatMoney(visible)}
</td>`;
            } else {
                html += `<td class="celda-aporte">${visible ? formatMoney(visible) : ""}</td>`;
            }
        });

        // FECHA ESPECIAL
        const realEsp = row.real_especial ?? Number(row.especial);
        const visibleEsp = Number(row.especial || 0);
        const excEsp = realEsp > visibleEsp;

        if (excEsp) {
            html += `
<td class="celda-aporte aporte-excedente" title="Aportó ${formatMoney(realEsp)}">
    ⭐ ${formatMoney(visibleEsp)}
</td>`;
        } else {
            html += `<td class="celda-aporte">${visibleEsp ? formatMoney(visibleEsp) : ""}</td>`;
        }

        // OTROS APORTES
        let tipos = "";
        let totalOtros = 0;

        if (row.otros?.length) {
            tipos = row.otros.map(o => `${escapeHtml(o.tipo)} (${formatMoney(o.valor)})`).join("<br>");
            totalOtros = row.otros.reduce((s, o) => s + Number(o.valor), 0);
        }

        html += `<td class="otros-tipos">${tipos}</td>`;
        html += `<td class="otros-valor">${totalOtros ? formatMoney(totalOtros) : ""}</td>`;

        // TOTAL MES & SALDO
        html += `<td class="total-por-jugador">${row.total_mes ? formatMoney(row.total_mes) : ""}</td>`;
        html += `<td class="total-por-jugador">${row.saldo ? formatMoney(row.saldo) : ""}</td>`;
        html += `</tr>`;
    });

    html += `</tbody>`;
    html += renderFooter(data);
    html += `</table>`;

    cont.innerHTML = html;

    activarSeleccionFilas();
}

function renderFooter(data) {
    const dias = data.dias_validos;
    const totalsPorDia = Array(dias.length).fill(0);
    let totalEspecial = 0;
    let totalOtros = 0;
    let totalMes = 0;
    let totalSaldos = 0;

    data.rows.forEach(r => {
        r.dias.forEach((v, i) => totalsPorDia[i] += Number(v || 0));
        totalEspecial += Number(r.especial || 0);

        if (r.otros?.length)
            totalOtros += r.otros.reduce((s, o) => s + Number(o.valor), 0);

        totalMes += Number(r.total_mes);
        totalSaldos += Number(r.saldo);
    });

    let html = `<tfoot><tr><td><strong>TOTAL DÍA</strong></td>`;

    totalsPorDia.forEach(v => html += `<td class="total-footer-dias"><strong>${formatMoney(v)}</strong></td>`);

    html += `
<td><strong>${formatMoney(totalEspecial)}</strong></td>
<td><strong>TOTAL OTROS</strong></td>
<td><strong>${formatMoney(totalOtros)}</strong></td>
<td><strong>${formatMoney(totalMes)}</strong></td>
<td><strong>${formatMoney(totalSaldos)}</strong></td>
</tr></tfoot>`;

    return html;
}

function activarSeleccionFilas() {
    const filas = document.querySelectorAll("tbody tr");
    filas.forEach(tr => {
        tr.addEventListener("click", () => {
            filas.forEach(f => f.classList.remove("fila-seleccionada"));
            tr.classList.add("fila-seleccionada");
        });
    });
}
