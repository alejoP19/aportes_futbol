// ==============================
// ui.js
// Render UI: totales, observaciones, gastos, PDF
// ==============================

import { formatMoney } from "./utils.js";

export function renderObservaciones(text) {
    const el = document.getElementById("observaciones");
    el.textContent = text?.trim() || "No hay Observaciones este mes...";
}

export function renderGastos(data) {
    const box = document.getElementById("gastosMesPublico");
    if (!box || !data?.totales) return;

    const t = data.totales;

    const gastosMes  = Number(t.gastos_mes  || 0);
    const gastosAnio = Number(t.gastos_anio || 0);

    let html = "<h3>Gastos</h3>";
    html += `<p><strong>Gastos del mes:</strong> ${formatMoney(gastosMes)}</p>`;
    html += `<p><strong>Gastos del año hasta este mes:</strong> ${formatMoney(gastosAnio)}</p>`;

    box.innerHTML = html;
}

export function renderTotales(tot) {
    if (!tot || !tot.totales) return;

    const t = tot.totales;

    const mes   = document.getElementById("tMes");
    const otros = document.getElementById("tOtros");
    const anio  = document.getElementById("tAnio");

    mes.textContent   = formatMoney(Number(t.month_total || 0));
    otros.textContent = formatMoney(Number(t.otros_mes_total || 0));
    anio.textContent  = formatMoney(Number(t.year_total || 0));
}

export function activarExportPDF() {
    const btn = document.querySelector(".export_public_pdf_butt");
    if (!btn) return;

    btn.addEventListener("click", e => {
        e.preventDefault();

        const mes  = document.getElementById("selectMes").value;
        const anio = document.getElementById("selectAnio").value;

        window.open(
            `/APORTES_FUTBOL/public/public_reportes/export_pdf_publico.php?mes=${mes}&anio=${anio}`,
            "_blank"
        );
    });
}
