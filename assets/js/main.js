console.log("MAIN.JS SE CARGÓ CORRECTAMENTE");

// assets/js/main.js
const API = "backend";
let selectedPlayerId = null; // fila seleccionada global

// ----------- UTILS -----------------
async function fetchText(url) {
    const r = await fetch(url);
    return await r.text();
}

async function postJSON(url, data) {
    const r = await fetch(url, {
        method: 'POST',
        headers: { 'Accept': 'application/json' },
        body: (data instanceof FormData ? data : JSON.stringify(data))
    });
    try { return await r.json(); } catch (e) { return null; }
}

// ----------- JUGADORES -----------------
async function loadPlayersList() {
    const res = await fetch(`${API}/aportantes/get_players.php`);
    const players = await res.json();
    const sel = document.getElementById('selectPlayerOtros');

    if (sel) {
        // Opción tipo placeholder
        sel.innerHTML = `
            <option value="" disabled selected>Elige un Aportante</option>
        `;

        players.forEach(p => {
            const opt = document.createElement('option');
            opt.value = p.id;
            opt.textContent = p.nombre;
            sel.appendChild(opt);
        });
    }
}

async function agregarJugador() {
    const nombre = document.getElementById("playerName").value.trim();
    const telefono = document.getElementById("playerPhone").value.trim();

    if (nombre === "") {
        Swal.fire({ icon: 'info', title: 'Nombre Requerido', text: 'El Nombre es Obligatorio' });
        return;
    }
    if (telefono === "") {
        Swal.fire({ icon: 'info', title: 'Telefono Requerido', text: 'El Telefono es Obligatorio' });
        return;
    }

    const data = { nombre, telefono };
    const resp = await postJSON(`${API}/aportantes/add_player.php`, data);

    if (resp && resp.status === "ok") {
        Swal.fire({ icon: 'success', title: '¡Excelente!', text: 'Nuevo Aportante Registrado', showConfirmButton: false, timer: 1800 });

        document.getElementById("playerName").value = "";
        document.getElementById("playerPhone").value = "";

        await loadPlayersList();
        await refreshSheet();
    } else if (resp && resp.msg === "Nombre de jugador ya existe") {
        Swal.fire({ icon: 'info',title: '¡Atención!', text: 'Nombre de Aportante ya Registrado, Elige Otro' });
    } else {
        Swal.fire({ icon: 'error', title: 'Error', text: 'Error al guardar el aportante' });
    }
}
// ----------- TABLA Y APORTE -----------------
async function loadSheet(mes, anio) {
    const html = await fetchText(`${API}/aportes/listar_aportes.php?mes=${mes}&anio=${anio}`);
    const container = document.getElementById('monthlyTableContainer');
    container.innerHTML = html;

    // Mantener fila seleccionada
    if (selectedPlayerId) {
        const row = container.querySelector(`tr[data-player='${selectedPlayerId}']`);
        if (row) row.classList.add('selected-row');
    }

    // Click en filas
    container.querySelectorAll("tr").forEach(r => {
        r.addEventListener("click", () => {
            container.querySelectorAll("tr.selected-row").forEach(rr => rr.classList.remove("selected-row"));
            r.classList.add("selected-row");
            selectedPlayerId = r.getAttribute("data-player");
        });
    });

   // Inputs de aporte
container.querySelectorAll('.cell-aporte').forEach(input => {
    input.addEventListener('change', async ev => {
    const id = ev.target.dataset.player;
    const fecha = ev.target.dataset.fecha;
    const raw = (ev.target.value || "").toString().trim();

    let valorToSend = null;

    if (raw === "") {
        valorToSend = null;
    } else {
        const digits = raw.replace(/[^\d\-]/g, "");
        valorToSend = parseInt(digits, 10);
        if (isNaN(valorToSend)) valorToSend = null;
    }

    /* --------------------------------------------
       ⭐ MOSTRAR ESTRELLA INMEDIATA (ANTES DEL REFRESH)
    ----------------------------------------------*/
    let wrapper = ev.target.closest(".aporte-wrapper");
if (wrapper) {
    let flag = wrapper.querySelector(".saldo-flag");
    if (flag) {

        if (valorToSend > 2000) {
            flag.textContent = "★";
            flag.classList.add("show");   // ← activa animación
        } else {
            flag.classList.remove("show"); // ← oculta animación
            setTimeout(() => flag.textContent = "", 200);
        }

    }
}


    // Guardar en backend
    await postJSON(`${API}/aportes/guardar_aporte.php`, { 
        id_jugador: id, 
        fecha, 
        valor: valorToSend 
    });

    ev.target.classList.add('saved');
    setTimeout(() => ev.target.classList.remove('saved'), 400);

    // Ahora sí refrescamos todo
    await refreshSheet();
});

});
    // Botones eliminar
container.querySelectorAll(".btn-del-player").forEach(btn => {
    btn.addEventListener("click", ev => {
        ev.stopPropagation(); // para no seleccionar fila
        eliminarJugador(btn.dataset.id);
    });
});
}

// ----------- OBSERVACIONES -----------------
function loadObservaciones(mes, anio) {
    fetch(`${API}/aportes/get_observaciones.php?mes=${mes}&anio=${anio}`)
        .then(res => res.json())
        .then(data => {
            document.getElementById("obsMes").value = data.observaciones || "";
        });
}

function saveObservaciones() {
    const mes = monthSelect.value;
    const anio = yearSelect.value;
    const texto = document.getElementById("obsMes").value;
    if (texto == '') {
        Swal.fire({
  icon: "error",
  title: "No Hay Observaciónes",
  text: "Debe Ingresar una Observación!",
  
});
    }else{
 fetch(`${API}/aportes/save_observaciones.php`, {
        method: "POST",
        headers: { "Content-Type": "application/x-www-form-urlencoded" },
        body: `mes=${mes}&anio=${anio}&texto=${encodeURIComponent(texto)}`
    })
        .then(res => res.json())
        .then(data => {
            if (data.ok)  Swal.fire({
  title: "Observación o Gasto Guardada Exitosamente!",
  icon: "success",
  draggable: true
});
else  
Swal.fire({
  icon: "error",
  title: "Oops...",
  text: "Error al Guardar la Observación o Gasto!",
  footer: '<a href="#">Why do I have this issue?</a>'
});;
        });
    }
   
}

// ----------- OTROS APORTES -----------------
async function addOtroAporte() {
    const sel = document.getElementById('selectPlayerOtros');
    const id = sel.value;
    const tipo = document.getElementById('otroTipo').value.trim();
    const valor = parseInt(document.getElementById('otroValor').value) || 0;
    if (!id || tipo === "") {
        Swal.fire({
  title: "¡Importante!",
  text: "Debe Elegir un Aportante y un Tipo de Aporte",
  icon:"info",
});  
return; 

}
    const fd = new FormData();
    fd.append('id_jugador', id);
    fd.append('mes', monthSelect.value);
    fd.append('anio', yearSelect.value);
    fd.append('tipo', tipo);
    fd.append('valor', valor);
    const j = await postJSON(`${API}/aportes/add_otro_aporte.php`, fd);
    if (j && j.ok) {
      Swal.fire({
  title: "Otro Aporte Agregado Exitosamente!",
  icon: "success",
  draggable: true
});
        document.getElementById('otroTipo').value = "";
        document.getElementById('otroValor').value = "";
        await refreshSheet();
    }
}

// ----------- TOTALES -----------------
async function loadTotals(mes, anio) {
    const res = await fetch(`${API}/aportes/get_totals.php?mes=${mes}&anio=${anio}`);
    const j = await res.json();
    if (!j) return;

    // Ajustado a los IDs de tu HTML de administrador
    document.getElementById('tDia').innerText = j.today
        ? j.today.toLocaleString('es-CO', { style: 'currency', currency: 'COP', maximumFractionDigits: 0 })
        : '';
    document.getElementById('tMes').innerText = j.month_total
        ? j.month_total.toLocaleString('es-CO', { style: 'currency', currency: 'COP', maximumFractionDigits: 0 })
        : '';
    document.getElementById('tAnio').innerText = j.year_total
        ? j.year_total.toLocaleString('es-CO', { style: 'currency', currency: 'COP', maximumFractionDigits: 0 })
        : '';

        // otros aportes
document.getElementById('tOtros').innerText = j.otros_mes
    ? j.otros_mes.toLocaleString('es-CO', { style: 'currency', currency: 'COP', maximumFractionDigits: 0 })
    : '';

        
}


// ----------- REFRESH COMPLETA -----------------
async function refreshSheet() {
    const mes = monthSelect.value;
    const anio = yearSelect.value;
    await loadSheet(mes, anio);
    await loadTotals(mes, anio);
    loadObservaciones(mes, anio);
}

// ----------- PANEL IZQUIERDO -----------------
function toggleLeftPanel() {
    const container = document.querySelector('.container');
    const middlePanel = document.querySelector('.middle-panel');

    container.classList.toggle('panel-collapsed');

    if (middlePanel) {
        middlePanel.classList.toggle('expanded');

        // reset scroll al expandir
        if (middlePanel.classList.contains('expanded')) {
            middlePanel.scrollTop = 0;
            middlePanel.scrollLeft = 0;
        }
    }
}





// ----------- EVENTOS INICIALES -----------------
const monthSelect = document.getElementById("monthSelect");
const yearSelect = document.getElementById("yearSelect");

document.addEventListener("DOMContentLoaded", async () => {
    await loadPlayersList();
    await refreshSheet();

    // botones
    const btnAddPlayer = document.getElementById("btnAddPlayer");
    if (btnAddPlayer) btnAddPlayer.addEventListener("click", agregarJugador);

    const btnAddOtro = document.getElementById('btnAddOtro');
    if (btnAddOtro) btnAddOtro.addEventListener('click', addOtroAporte);

    const saveObsBtn = document.getElementById('saveObsBtn');
    if (saveObsBtn) saveObsBtn.addEventListener('click', saveObservaciones);

    // cambiar mes/año
    monthSelect.addEventListener("change", refreshSheet);
    yearSelect.addEventListener("change", refreshSheet);

    // export PDF
    const exportPdf = document.querySelector('.export-pdf-butt');
    if (exportPdf) {
        exportPdf.addEventListener('click', e => {
            e.preventDefault();
            const mes = monthSelect.value;
            const anio = yearSelect.value;
            window.open(`backend/reportes/export_pdf.php?mes=${mes}&anio=${anio}`, '_blank');
        });
    }

  
// --- MOSTRAR / OCULTAR TABLA APORTANTES ---
const btnVer = document.getElementById("btnVerAportantes");
const tabla = document.getElementById("playersTableContainer");

if (btnVer && tabla) {
    btnVer.addEventListener("click", () => {
        const middlePanel = document.querySelector('.middle-panel');
        const isExpanded = middlePanel.classList.toggle("expanded");
        btnVer.textContent = isExpanded ? "Ocultar Aportantes" : "Ver Aportantes";
    });
}



    
btnLogout.addEventListener("click", function (e) {
    e.preventDefault();

    Swal.fire({
        title: "¿Cerrar tu Sesión?",
        text: "¡La Sesión de Administrador se Cerrará!",
        icon: "question",
        iconColor: "#16cc34ff",
        showCancelButton: true,
        confirmButtonColor: "#16cc34ff",
        cancelButtonColor: "rgba(233, 37, 53, 1)",
        confirmButtonText: "Sí, Cerrar!",
        cancelButtonText: "Cancelar"
    }).then((result) => {

        if (!result.isConfirmed) {
            Swal.fire({
                title: "¡Perfecto, Continuemos!",
                icon: "success",
                draggable: true
});
            return;
        }

        // 🟢 Mostrar mensaje de despedida (tu estilo)
        Swal.fire({
            title: "¡Sesión Cerrada!",
            text: "¡Te Esperamos Pronto!",
            icon: "success",
            iconColor: "#0e9625ff",
            confirmButtonText: "OK"
        }).then(() => {

            // 🔥 Llamada real al logout.php
            fetch('/aportes_futbol/backend/auth/logout.php', {
                method: 'POST',
                credentials: 'same-origin'
            })
            .then(() => {
                // 🔁 Redirigir a la página pública
                window.location.href = "/aportes_futbol/public/index.php";
            })
            .catch(() => {
                window.location.href = "/apportes_futbol/public/index.php";
            });

        });
    });
});
})



async function eliminarJugador(id) {
    Swal.fire({
       title: "¿Eliminar Este Aportante?",
  text: "Sus Aportes No Serán Eliminados!",
  icon: "question",
  iconColor: '#ff0040ff',
  showCancelButton: true,
  confirmButtonColor: "#1ead5eff",
  cancelButtonColor: "#ff0040ff",
  confirmButtonText: "Yes, delete it!"
    }).then(async (result) => {

        if (result.isConfirmed) {

            // --- ENVÍO CORRECTO DEL ID ---
            const form = new FormData();
            form.append("id", id);

            const response = await fetch(`${API}/aportantes/delete_player.php`, {
                method: "POST",
                body: form
            });

            const resp = await response.json();
            console.log("Respuesta delete:", resp);

            // --- RESPUESTA ---
            if (resp && resp.ok) {
                Swal.fire({
                    icon: "success",
                    title: "Aportante eliminado",
                    timer: 1500,
                    showConfirmButton: false
                });

                await loadPlayersList(); 
                await refreshSheet(); 
            } 
            else {
                Swal.fire("Error", resp.msg || "No se pudo eliminar", "error");
            }
        }

    });
}



