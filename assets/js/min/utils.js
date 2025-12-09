// ==============================
// utils.js
// Funciones pequeñas reutilizables
// ==============================

export function formatMoney(v) {
    if (!v && v !== 0) return "";
    return Number(v).toLocaleString("es-CO", {
        style: "currency",
        currency: "COP",
        maximumFractionDigits: 0
    });
}

export function escapeHtml(str) {
    if (!str) return "";
    return str.replace(/[&<>"'`=\/]/g, s => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
        '/': '&#x2F;', '`': '&#x60;', '=': '&#x3D;'
    })[s]);
}
