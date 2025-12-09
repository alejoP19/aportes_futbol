// ==============================
// api.js
// Manejo de llamadas a backend
// ==============================

export const API_JSON = "/APORTES_FUTBOL/backend/public_data/listado_publico.php";

export async function getDatos(mes, anio) {
    const url = `${API_JSON}?mes=${mes}&anio=${anio}`;
    const r = await fetch(url);

    if (!r.ok) throw new Error("Error al cargar datos");

    return await r.json();
}
