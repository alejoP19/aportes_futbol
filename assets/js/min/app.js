// ==============================
// app.js
// Archivo principal del sistema público
// ==============================

import { getDatos } from "./api.js";
import { renderTablaPublic } from "./table.js";
import { renderObservaciones, renderGastos, renderTotales, activarExportPDF } from "./ui.js";

document.addEventListener("DOMContentLoaded", () => {

    activarExportPDF();
    cargarSelects();
    cargarDatos();

    document.getElementById("selectMes").addEventListener("change", cargarDatos);
    document.getElementById("selectAnio").addEventListener("change", cargarDatos);
});


function cargarSelects() {
    const selA = document.getElementById("selectAnio");
    const selM = document.getElementById("selectMes");

    selA.innerHTML = "";
    selM.innerHTML = "";

    const yActual = new Date().getFullYear();
    const mActual = new Date().getMonth() + 1;

    for (let y = yActual; y >= yActual - 5; y--) {
        selA.innerHTML += `<option value="${y}" ${y === yActual ? "selected" : ""}>${y}</option>`;
    }

    const meses = ["Enero","Febrero","Marzo","Abril","Mayo","Junio","Julio","Agosto","Septiembre","Octubre","Noviembre","Diciembre"];

    meses.forEach((n, i) => {
        selM.innerHTML += `<option value="${i+1}" ${i+1 === mActual ? "selected" : ""}>${n}</option>`;
    });
}

async function cargarDatos() {
    const mes = document.getElementById("selectMes").value;
    const anio = document.getElementById("selectAnio").value;

    try {
        const data = await getDatos(mes, anio);

        renderTablaPublic(data);
        renderTotales(data);
        renderObservaciones(data.observaciones);
        renderGastos(data);

    } catch (error) {
        document.getElementById("contenedorTabla").innerHTML = "<p>Error cargando datos</p>";
    }
}
