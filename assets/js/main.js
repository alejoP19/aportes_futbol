// assets/js/main.js
const API = "backend";
let selectedPlayerId = null; // fila seleccionada global

// ----------- UTILS -----------------
async function fetchText(url){ 
    const r = await fetch(url); 
    return await r.text(); 
}

async function postJSON(url, data){ 
    const r = await fetch(url, { 
        method:'POST', 
        headers:{'Accept':'application/json'}, 
        body: (data instanceof FormData ? data : JSON.stringify(data)) 
    }); 
    try { return await r.json(); } catch(e){ return null; } 
}

// ----------- JUGADORES -----------------
async function loadPlayersList(){
    const res = await fetch(`${API}/get_players.php`);
    const players = await res.json();
    const sel = document.getElementById('selectPlayerOtros');
    if(sel){
        sel.innerHTML = '<option value="">-- selecciona --</option>';
        players.forEach(p=>{
            const opt = document.createElement('option');
            opt.value = p.id;
            opt.textContent = p.nombre;
            sel.appendChild(opt);
        });
    }
}

async function agregarJugador(){
    const nombre = document.getElementById("playerName").value.trim();
    const telefono = document.getElementById("playerPhone").value.trim();
    if(nombre===""){ alert("El nombre es obligatorio"); return; }
    const data = { nombre, telefono };
    const resp = await postJSON(`${API}/add_player.php`, data);
    if(resp && resp.status==="ok"){
        alert("Jugador agregado correctamente");
        document.getElementById("playerName").value="";
        document.getElementById("playerPhone").value="";
        await loadPlayersList();
        await refreshSheet();
    } else { alert("Error guardando jugador"); }
}

// ----------- TABLA Y APORTE -----------------
async function loadSheet(mes, anio){
    const html = await fetchText(`${API}/listar_aportes.php?mes=${mes}&anio=${anio}`);
    const container = document.getElementById('monthlyTableContainer');
    container.innerHTML = html;

    // Mantener fila seleccionada
    if(selectedPlayerId){
        const row = container.querySelector(`tr[data-player='${selectedPlayerId}']`);
        if(row) row.classList.add('selected-row');
    }

    // Click en filas
    container.querySelectorAll("tr").forEach(r=>{
        r.addEventListener("click", ()=>{
            container.querySelectorAll("tr.selected-row").forEach(rr=>rr.classList.remove("selected-row"));
            r.classList.add("selected-row");
            selectedPlayerId = r.getAttribute("data-player");
        });
    });

    // Inputs de aporte
   container.querySelectorAll('.cell-aporte').forEach(input=>{
    input.addEventListener('change', async ev=>{
        const id = ev.target.dataset.player;
        const fecha = ev.target.dataset.fecha;
        const valor = parseInt(ev.target.value) || 0;

        await postJSON(`${API}/guardar_aporte.php`, { id_jugador:id, fecha, valor });

        ev.target.classList.add('saved');
        setTimeout(()=>ev.target.classList.remove('saved'),400);

        // 🔥 Recargar tabla + totales al instante
        await refreshSheet();
    });
});
}

// ----------- OBSERVACIONES -----------------
function loadObservaciones(mes, anio){
    fetch(`${API}/get_observaciones.php?mes=${mes}&anio=${anio}`)
        .then(res=>res.json())
        .then(data=>{
            document.getElementById("obsMes").value = data.observaciones || "";
        });
}

function saveObservaciones(){
    const mes = monthSelect.value;
    const anio = yearSelect.value;
    const texto = document.getElementById("obsMes").value;
    fetch(`${API}/save_observaciones.php`,{
        method:"POST",
        headers: {"Content-Type":"application/x-www-form-urlencoded"},
        body:`mes=${mes}&anio=${anio}&texto=${encodeURIComponent(texto)}`
    })
    .then(res=>res.json())
    .then(data=>{
        if(data.ok) alert("Observaciones guardadas correctamente.");
        else alert("Error al guardar observaciones.");
    });
}

// ----------- OTROS APORTES -----------------
async function addOtroAporte(){
    const sel = document.getElementById('selectPlayerOtros');
    const id = sel.value;
    const tipo = document.getElementById('otroTipo').value.trim();
    const valor = parseInt(document.getElementById('otroValor').value) || 0;
    if(!id || tipo===""){ alert("Selecciona jugador y escribe el tipo"); return; }
    const fd = new FormData();
    fd.append('id_jugador', id);
    fd.append('mes', monthSelect.value);
    fd.append('anio', yearSelect.value);
    fd.append('tipo', tipo);
    fd.append('valor', valor);
    const j = await postJSON(`${API}/add_otro_aporte.php`, fd);
    if(j && j.ok){
        alert("Otro aporte agregado");
        document.getElementById('otroTipo').value="";
        document.getElementById('otroValor').value="";
        await refreshSheet();
    }
}

// ----------- TOTALES -----------------
async function loadTotals(mes, anio){
    const res = await fetch(`${API}/get_totals.php?mes=${mes}&anio=${anio}`);
    const j = await res.json();
    if(!j) return;
    document.getElementById('dailyTotal').innerText = j.today ? j.today.toLocaleString('es-CO',{style:'currency',currency:'COP',maximumFractionDigits:0}) : '';
    document.getElementById('monthlyTotal').innerText = j.month_total ? j.month_total.toLocaleString('es-CO',{style:'currency',currency:'COP',maximumFractionDigits:0}) : '';
    document.getElementById('yearlyTotal').innerText = j.year_total ? j.year_total.toLocaleString('es-CO',{style:'currency',currency:'COP',maximumFractionDigits:0}) : '';
}

// ----------- REFRESH COMPLETA -----------------
async function refreshSheet(){
    const mes = monthSelect.value;
    const anio = yearSelect.value;
    await loadSheet(mes, anio);
    await loadTotals(mes, anio);
    loadObservaciones(mes, anio);
}

// ----------- PANEL IZQUIERDO -----------------
function toggleLeftPanel(){
    const container = document.querySelector('.container');
    const middlePanel = document.querySelector('.middle-panel');

    container.classList.toggle('panel-collapsed');

    if(middlePanel){
        middlePanel.classList.toggle('expanded');

        // reset scroll al expandir
        if(middlePanel.classList.contains('expanded')){
            middlePanel.scrollTop = 0;
            middlePanel.scrollLeft = 0;
        }
    }
}





// ----------- EVENTOS INICIALES -----------------
const monthSelect = document.getElementById("monthSelect");
const yearSelect = document.getElementById("yearSelect");

document.addEventListener("DOMContentLoaded", async ()=>{
    await loadPlayersList();
    await refreshSheet();

    // botones
    const btnAddPlayer = document.getElementById("btnAddPlayer");
    if(btnAddPlayer) btnAddPlayer.addEventListener("click", agregarJugador);

    const btnAddOtro = document.getElementById('btnAddOtro');
    if(btnAddOtro) btnAddOtro.addEventListener('click', addOtroAporte);

    const saveObsBtn = document.getElementById('saveObsBtn');
    if(saveObsBtn) saveObsBtn.addEventListener('click', saveObservaciones);

    // cambiar mes/año
    monthSelect.addEventListener("change", refreshSheet);
    yearSelect.addEventListener("change", refreshSheet);

    // export PDF
    const exportPdf = document.querySelector('.export-pdf-butt');
    if(exportPdf){
        exportPdf.addEventListener('click', e=>{
            e.preventDefault();
            const mes = monthSelect.value;
            const anio = yearSelect.value;
            window.open(`backend/export_pdf.php?mes=${mes}&anio=${anio}`,'_blank');
        });
    }

    // toggle panel izquierdo
    const toggleBtn = document.querySelector('.toggle-left-panel');
    if(toggleBtn){
        toggleBtn.addEventListener('click', toggleLeftPanel);
    }
});
