

// assets/js/main.js
const API = "backend";
let currentOtroDia = null; // recuerda el día seleccionado del "Otro juego"
let selectedPlayerId = null; // fila seleccionada global
const API_ESP_GET = `${API}/aportes_esporadicos/get.php`;
const API_ESP_SAVE = `${API}/aportes_esporadicos/save.php`;
const API_ESP_META_SAVE = `${API}/aportes_esporadicos/save_meta.php`;

let __espCache = null;
let __espSlots = 10;
const ESP_BASE = 3000;

// ----------- UTILS -----------------
async function fetchText(url) {
  const r = await fetch(url);
  return await r.text();
}


function normMes(m) { return String(parseInt(m, 10)); }
function normAnio(a) { return String(parseInt(a, 10)); }

function getOtroKey(mes, anio) {
  return `otroDia_${normAnio(anio)}_${normMes(mes)}`;
}

function getStoredOtroDia(mes, anio) {
  const v = parseInt(localStorage.getItem(getOtroKey(mes, anio)) || "", 10);
  return Number.isFinite(v) ? v : null;
}

function setStoredOtroDia(mes, anio, dia) {
  localStorage.setItem(getOtroKey(mes, anio), String(dia));
}


async function postJSON(url, data) {
  const isForm = (data instanceof FormData);

  const r = await fetch(url, {
    method: 'POST',
    headers: isForm
      ? { 'Accept': 'application/json' }
      : { 'Accept': 'application/json', 'Content-Type': 'application/json' },
    body: isForm ? data : JSON.stringify(data)
  });

  try { return await r.json(); } catch (e) { return null; }
}

// ----------- UTILS (agrega esto) -----------------
function formatMoney(value) {
  const n = Number(value || 0);
  return n.toLocaleString("es-CO", {
    style: "currency",
    currency: "COP",
    maximumFractionDigits: 0
  });
}

function escapeHtml(str) {
  return String(str ?? "")
    .replaceAll("&", "&amp;")
    .replaceAll("<", "&lt;")
    .replaceAll(">", "&gt;")
    .replaceAll('"', "&quot;")
    .replaceAll("'", "&#039;");
}

async function refreshAfterEsporadicoSave({ refreshOtros = false } = {}) {
  const mes = monthSelect.value;
  const anio = yearSelect.value;

  // 1) recargar planilla principal para que el TFOOT refleje esporádicos
  await loadSheet(mes, anio);

  // 2) tarjetas
  await loadTotals(mes, anio);

  // 3) informativo otros partidos (si aplica)
  if (refreshOtros) {
    await loadOtrosPartidosInfo(mes, anio);
  }

  // 4) recargar tabla esporádicos
  await cargarAportesEsporadicos(mes, anio, currentOtroDia);
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
    Swal.fire({ icon: 'info', title: '¡Atención!', text: 'Nombre de Aportante ya Registrado, Elige Otro' });
  } else {
    Swal.fire({ icon: 'error', title: 'Error', text: 'Error al guardar el aportante' });
  }
}
// ----------- TABLA Y APORTE -----------------
async function loadSheet(mes, anio) {

  // ✅ 1) recuperar último "otro día" usado para ese mes/año
  const stored = getStoredOtroDia(mes, anio);
  if (stored) currentOtroDia = stored;

  // ✅ 2) mandar ?otro=DD AL BACKEND (antes del fetch)
  const otroParam = currentOtroDia ? `&otro=${currentOtroDia}` : "";
  const html = await fetchText(`${API}/aportes/listar_aportes.php?mes=${mes}&anio=${anio}${otroParam}`);


  const container = document.getElementById('monthlyTableContainer');
  container.innerHTML = html;

  // ✅ Al cargar la tabla (recarga / cambio de mes), activar clase + title del saldo
  initSaldoFromHTML(container);
  const table = container.querySelector(".planilla");
  setupHeaderColumnHighlight(table);

  // ================================
  // OTRO JUEGO: actualizar header + data-fecha sin recargar
  // ================================
  const selectOtro = document.getElementById("selectOtroDia"); // tu select (ajusta id si es otro)
  const thOtro = container.querySelector("#thOtroJuego");

  function pad2(n) { return String(n).padStart(2, "0"); }

  function updateOtroJuegoColumn(diaElegido) {
    if (!thOtro) return;

    // 1) Cambiar texto del header
    thOtro.textContent = `Otro juego (${pad2(diaElegido)})`;
    thOtro.dataset.dia = String(diaElegido);

    // 2) Cambiar data-fecha de TODOS los inputs y checks de esa columna
    //    Para saber qué columna es, calculamos su índice real en la tabla.
    const table = container.querySelector(".planilla");
    if (!table) return;

    // índice de columna real del th (considerando colspans)
    const headerRow = thOtro.parentElement;
    let colIndex = 0;
    for (const cell of headerRow.cells) {
      if (cell === thOtro) break;
      colIndex += cell.colSpan || 1;
    }

    // construir fecha nueva YYYY-MM-DD con mes/anio actuales
    const yyyy = String(anio);
    const mm = pad2(mes);
    const dd = pad2(diaElegido);
    const nuevaFecha = `${yyyy}-${mm}-${dd}`;

    // recorrer filas del tbody y actualizar dataset de input y checkbox en esa columna
    const bodyRows = table.tBodies[0]?.rows || [];
    for (const row of bodyRows) {
      const cell = row.cells[colIndex];
      if (!cell) continue;

      const input = cell.querySelector("input.cell-aporte");
      if (input) input.dataset.fecha = nuevaFecha;

      const chk = cell.querySelector("input.chk-deuda");
      if (chk) chk.dataset.fecha = nuevaFecha;
    }
  }

  if (selectOtro && thOtro) {
    // ✅ forzar select al último guardado (si existe)
    const stored = getStoredOtroDia(mes, anio);
    if (stored) {
      currentOtroDia = stored;
      selectOtro.value = String(stored);
    } else {
      // si no hay guardado, usa lo que venga del HTML
      currentOtroDia = parseInt(selectOtro.value || thOtro.dataset.dia || "1", 10);
      if (Number.isFinite(currentOtroDia)) {
        setStoredOtroDia(mes, anio, currentOtroDia);
      }
    }

    // ✅ cambio de día: guardar y RECARGAR la tabla desde backend
    selectOtro.addEventListener("change", async () => {
      const d = parseInt(selectOtro.value, 10);
      if (!Number.isFinite(d)) return;

      currentOtroDia = d;
      setStoredOtroDia(mes, anio, d);

      // 🔥 necesario para que:
      // - aparezcan valores de ese día
      // - el TOTAL DÍA / TOTAL MES / tarjetas se actualicen
      await refreshSheet();
    });
  }



  activarBusquedaJugadores();
  // Mantener fila seleccionada
  if (selectedPlayerId) {
    const row = container.querySelector(`tr[data-player='${selectedPlayerId}']`);
    if (row) row.classList.add('selected-row');
  }

  // Click en filas
  container.querySelectorAll("tbody tr").forEach(r => {
    r.addEventListener("click", (e) => {

      // opcional: si clickeas en input/botón/checkbox, no cambies selección
      if (e.target.closest("input, button, select, label")) return;

      container.querySelectorAll("tbody tr.selected-row")
        .forEach(rr => rr.classList.remove("selected-row"));

      r.classList.add("selected-row");
      selectedPlayerId = r.getAttribute("data-player");
    });
  });

  // Inputs de aporte


  container.querySelectorAll('.cell-aporte').forEach(input => {

    // ✅ Si el usuario SOLO tocó para ver tooltip, NO guardamos nada.
    input.addEventListener("focus", (ev) => {
      ev.target.dataset.dirty = "0";         // no editó aún
      ev.target.dataset.prevValue = ev.target.value; // por si quieres comparar
    });

    input.addEventListener("input", (ev) => {
      ev.target.dataset.dirty = "1";         // sí editó
    });

    input.addEventListener('blur', async ev => {

      // ✅ Si no editó, no dispares guardar_aporte.php
      if (ev.target.dataset.dirty !== "1") return;

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

      // Guardar en backend
      const resp = await postJSON(`${API}/aportes/guardar_aporte.php`, {
        id_jugador: id,
        fecha,
        valor: valorToSend
      });

      ev.target.classList.add('saved');
      setTimeout(() => ev.target.classList.remove('saved'), 400);

      if (resp && resp.ok) {

        // ✅ Si backend devuelve aporte_efectivo, fijar el valor mostrado en el input
        if (Object.prototype.hasOwnProperty.call(resp, "aporte_efectivo")) {
          ev.target.value = resp.aporte_efectivo ? String(resp.aporte_efectivo) : "";
        } else {
          ev.target.value = (valorToSend === null) ? "" : String(valorToSend);
        }

        // ✅ actualizar marcadores
        applySaldoMarker(ev.target, resp);

        const realParaMarcar = (resp && Object.prototype.hasOwnProperty.call(resp, "valor_real"))
          ? Number(resp.valor_real || 0)
          : Number(valorToSend || 0);

        applyExcedenteMarker(ev.target, realParaMarcar);

        // ✅ actualizar saldo fila
        if (Object.prototype.hasOwnProperty.call(resp, "saldo")) {
          const row = ev.target.closest("tr");
          const table = document.querySelector(".planilla");
          if (row && table) {
            const saldoColIndex = findColumnIndex(table, "Tu Saldo");
            if (saldoColIndex !== -1 && row.cells[saldoColIndex]) {
              const strong = row.cells[saldoColIndex].querySelector("strong");
              const txt = Number(resp.saldo || 0).toLocaleString("es-CO");
              if (strong) strong.textContent = txt;
              else row.cells[saldoColIndex].textContent = txt;
            }
          }
        }

        // ✅ recompute tabla y tarjetas
        // const table = document.querySelector(".planilla");
        await refreshSheet();
        await loadTotals(monthSelect.value, yearSelect.value);

      } else {
        console.error("Error guardando aporte:", resp);
      }
    });
  });

  // Botones eliminar
  container.querySelectorAll(".btn-del-player").forEach(btn => {
    btn.addEventListener("click", ev => {
      ev.stopPropagation(); // para no seleccionar fila
      eliminarJugador(btn.dataset.id);
    });
  });

    // Click en teléfono
  container.querySelectorAll(".telefono-cell").forEach(cell => {
    cell.addEventListener("click", (e) => {
      e.stopPropagation();

      const numero = (cell.dataset.full || cell.textContent || "").trim();
      if (!numero) return;

      Swal.fire({
        title: "Teléfono del aportante",
        html: `<strong style="font-size:18px;">${numero}</strong>`,
        icon: "info",
        confirmButtonText: "Cerrar",
        confirmButtonColor: "#0a9f6f"
      });
    });
  });
}

/* ==========================================================
  Sombreado De Columnas
========================================================== */
function setupHeaderColumnHighlight(table) {
  if (!table || !table.tHead) return;

  const headRows = table.tHead.rows;
  const groupRow = headRows[0];                     // fila 1 (colspan)
  const realRow = headRows[headRows.length - 1];   // fila real

  function clear() {
    table.querySelectorAll(".col-activa, .col-especial")
      .forEach(el => el.classList.remove("col-activa", "col-especial"));
  }

  function paint(colIndex) {
    clear();
    Array.from(table.rows).forEach(row => {
      if (row.cells[colIndex]) {
        row.cells[colIndex].classList.add("col-activa");
      }
    });

    const h = realRow.cells[colIndex];
    if (h && /\(28\)|otra\s*fecha|fecha\s*especial/i.test(h.textContent || "")) {
      Array.from(table.rows).forEach(row => {
        if (row.cells[colIndex]) row.cells[colIndex].classList.add("col-especial");
      });
    }
  }

  function getStartIndex(th) {
    let idx = 0;
    for (const cell of th.parentElement.cells) {
      if (cell === th) break;
      idx += cell.colSpan || 1;
    }
    return idx;
  }

  table.tHead.addEventListener("click", (e) => {
    const th = e.target.closest("th");
    if (!th) return;

    // CLICK EN FILA 1 (Días / Otros / Total / Saldo)
    if (th.parentElement === groupRow) {
      paint(getStartIndex(th));
    }
    // CLICK EN FILA 2 (día 6, 10, Tipo, Valor, etc.)
    else {
      paint(th.cellIndex);
    }

    e.stopPropagation();
  });

  // Click fuera limpia
  document.addEventListener("click", (e) => {
    if (!table.contains(e.target)) clear();
  });
}


// ----------- BARRA DE BUSQUEDA -----------------//

function activarBusquedaJugadores() {
  const input = document.getElementById("searchJugador");
  if (!input) return;

  input.oninput = () => {
    const texto = input.value.trim().toLowerCase();

    const tabla = document.querySelector(".planilla");
    if (!tabla) return;

    const filas = tabla.querySelectorAll("tbody tr");

    filas.forEach(tr => {
      const celdaNombre = tr.querySelector("td:first-child");
      if (!celdaNombre) return;

      const nombre = celdaNombre.textContent.toLowerCase();
      tr.style.display = nombre.includes(texto) ? "" : "none";
    });
  };
}
// LIMPIADOR DE BARRA DE BUSQUEDA
document.getElementById("clearSearch")?.addEventListener("click", () => {
  const input = document.getElementById("searchJugador");
  if (!input) return;
  input.value = "";
  input.dispatchEvent(new Event("input"));
  input.focus();
});


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

  // Si no escribió nada, mostrar advertencia pero permitir continuar
  if (texto.trim() === '') {
    Swal.fire({
      icon: "info",
      title: "No Ingresó Una Nueva Observación",
      text: "Se Mostrarán Solo Las Obsercaciones Guardadas Anteriormente.",
      confirmButtonText: "OK, Continuar",
      confirmButtonColor: "#28a745",
      showCancelButton: true,
      cancelButtonText: "Cancelar"
    }).then(result => {

      if (result.isConfirmed) {
        enviarObservacion(mes, anio, texto);
      }

    });

    return; // Salimos para que no siga la función
  }

  // Si sí escribió texto, guardar directo
  enviarObservacion(mes, anio, texto);
}


//----------------------------------
// FUNCIÓN QUE HACE EL FETCH REAL
//----------------------------------
function enviarObservacion(mes, anio, texto) {

  fetch(`${API}/aportes/save_observaciones.php`, {
    method: "POST",
    headers: { "Content-Type": "application/x-www-form-urlencoded" },
    body: `mes=${mes}&anio=${anio}&texto=${encodeURIComponent(texto)}`
  })
    .then(res => res.json())
    .then(data => {

      if (data.ok) {
        Swal.fire({
          title: "¡Observación Guardada Exitosamente!",
          icon: "success",
          iconColor: "#0e9625ff",
          confirmButtonText: "OK"
        });
      } else {
        Swal.fire({
          icon: "error",
          title: "Oops...",
          text: "Error al guardar la observación o gasto"
        });
      }

    })
    .catch(err => {
      Swal.fire({
        icon: "error",
        title: "Error",
        text: "No se pudo conectar con el servidor"
      });
    });
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
      icon: "info",
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
// function formatMoney(n){
//   return Number(n || 0).toLocaleString("es-CO", {
//     style: "currency",
//     currency: "COP",
//     maximumFractionDigits: 0
//   });
// }


async function loadTotals(mes, anio) {
  const res = await fetch(`${API}/aportes/get_totals.php?mes=${mes}&anio=${anio}`, { cache: "no-store" });
  const j = await res.json();
  if (!j || !j.ok) return;

  // Parciales
  document.getElementById("tParcialMes").innerText  = formatMoney(j.parcial_mes);
  document.getElementById("tParcialAnio").innerText = formatMoney(j.parcial_anio);

  // Otros
  document.getElementById("tOtrosMes").innerText  = formatMoney(j.otros_mes);
  document.getElementById("tOtrosAnio").innerText = formatMoney(j.otros_anio);

  // Gastos
  document.getElementById("tGastosMes").innerText  = formatMoney(j.gastos_mes);
  document.getElementById("tGastosAnio").innerText = formatMoney(j.gastos_anio);

  // Finales sin saldo (sumables)
  document.getElementById("tFinalMes").innerText  = formatMoney(j.final_neto_mes);
  document.getElementById("tFinalAnio").innerText = formatMoney(j.final_anio_neto);

  // Saldos (2)
  const sMes = document.getElementById("tSaldoMes");
  if (sMes) sMes.innerText = formatMoney(j.saldo_mes);

  const sTot = document.getElementById("tSaldoTotal");
  if (sTot) sTot.innerText = formatMoney(j.saldo_total);

  // Total real hasta la fecha (si lo pintas)
  const tr = document.getElementById("tTotalRealHastaFecha");
  if (tr) tr.innerText = formatMoney(j.total_real_hasta_fecha);
}

// ================================
// ELIMINADOS (MODAL) - FIX DEFINITIVO
// ================================
window.__eliminadosMesCache = window.__eliminadosMesCache || {}; // { "2026-2": data }

function keyElim(anio, mes) {
  return `${parseInt(anio, 10)}-${parseInt(mes, 10)}`;
}

async function fetchEliminadosMes(mes, anio) {
  const mesN = parseInt(mes, 10);
  const anioN = parseInt(anio, 10);
  const url = `${API}/aportantes/get_eliminados_mes.php?mes=${mesN}&anio=${anioN}`;

  const r = await fetch(url, { cache: "no-store", credentials: "include" });
  const txt = await r.text();

  let data;
  try {
    data = JSON.parse(txt);
  } catch (e) {
    console.error("Respuesta NO JSON get_eliminados_mes.php:", { url, status: r.status, txt });
    throw e;
  }
  return data;
}

function renderModalEliminados(data, mes, anio) {
  // 👇 OJO: SIEMPRE re-tomar elementos del DOM (por si loadSheet reemplazó algo)
  const modal = document.getElementById("modalEliminados");
  const body  = document.getElementById("modalEliminadosBody");
  if (!modal || !body) return;

  const players = Array.isArray(data.players) ? data.players : [];
  const rows = Array.isArray(data.rows) ? data.rows : [];

  if (!players.length) {
    body.innerHTML = `<div style="opacity:.85;">No Hubo Eliminados Este Mes.</div>`;
    modal.classList.remove("hidden");
    modal.classList.remove("closing");
    return;
  }

  // agrupar filas por jugador
  const rowsByPlayer = new Map();
  for (const r of rows) {
    const k = String(r.jugador_id);
    if (!rowsByPlayer.has(k)) rowsByPlayer.set(k, []);
    rowsByPlayer.get(k).push(r);
  }

  const renderDeudasCell = (pp) => {
    const total = Number(pp.deudas_total || 0);
    const fechas = Array.isArray(pp.deudas_fechas) ? pp.deudas_fechas : [];
    if (!total) return `<span style="opacity:.6;">0</span>`;
    return `
      <div class="deuda-cell">
        <div class="deuda-count"><strong>${total}</strong></div>
        <div class="deuda-fechas">${fechas.map(escapeHtml).join("<br>")}</div>
      </div>
    `;
  };

  body.innerHTML = `
<div class="table-container">
  <table class="table-mini eliminados-detalle">
    <thead>
      <tr>
        <th style="width:40%;">Aportante</th>
        <th style="width:6%;">#</th>
        <th style="width:25%;">Fecha</th>
        <th style="width:14%;" class="right">Cantidad</th>
        <th style="width:10%;" class="right">Totales</th>
        <th style="width:10%;" class="right">Saldo</th>
        <th style="width:10%;" class="right">Deudas</th>
      </tr>
    </thead>

    <tbody>
      ${players.map(p => {
        const pr = rowsByPlayer.get(String(p.id)) || [];

        const baja = (p.fecha_baja || "");
        const bajaObj = baja ? new Date(baja + "T00:00:00") : null;
        const bajaMes  = bajaObj ? (bajaObj.getMonth() + 1) : null;
        const bajaAnio = bajaObj ? bajaObj.getFullYear() : null;
        const bajaInfo = (bajaMes && bajaAnio) ? `${bajaMes}/${bajaAnio}` : "sin fecha";
        const fechaBaja = escapeHtml(p.fecha_baja || "");

        const playerCell = `
          <div class="player-title">
            <strong>${escapeHtml(p.nombre || "")}</strong>
            <span class="baja-pill">Baja: ${fechaBaja}</span>
          </div>
        `;

        // sin movimientos
        if (!pr.length) {
          return `
            <tr class="row-empty">
              <td class="player-cell">${playerCell}</td>
              <td colspan="4" style="opacity:.85;">
                Este aportante fue eliminado en <strong>${bajaInfo}</strong>, pero <strong>no tiene aportes registrados en ${mes}/${anio}</strong>.<br>
                Si quieres ver sus aportes, revisa <strong>meses anteriores</strong> en la planilla.
              </td>
              <td class="right"><strong>${formatMoney(p.saldo_fin_mes || 0)}</strong></td>
              <td class="right">${renderDeudasCell(p)}</td>
            </tr>

            <tr class="player-total">
              <td><strong>Total Aportes(Mes)</strong></td>
              <td colspan="3"></td>
              <td class="right"><strong>${formatMoney(p.total_mes || 0)}</strong></td>
              <td class="right"><strong>${formatMoney(p.saldo_fin_mes || 0)}</strong></td>
              <td></td>
            </tr>

            <tr class="player-sep"><td colspan="7"></td></tr>
          `;
        }

        // con movimientos
        const rowsHtml = pr.map((r, i) => {
          const isLast = i === pr.length - 1;
          const cls = (r.kind === "otro") ? "row-otro" : "row-normal";
          const labelHtml = r.label ? `<div class="row-label">${escapeHtml(r.label)}</div>` : "";

          const saldoHtml  = isLast ? `<strong>${formatMoney(p.saldo_fin_mes || 0)}</strong>` : "";
          const deudasHtml = isLast ? renderDeudasCell(p) : "";

          if (i === 0) {
            return `
              <tr class="player-sep"><td colspan="7"></td></tr>
              <tr class="${cls}">
                <td class="player-cell" rowspan="${pr.length}">${playerCell}</td>
                <td>${r.n}</td>
                <td>${escapeHtml(r.fecha || "")}${labelHtml}</td>
                <td class="right">${formatMoney(r.cantidad || 0)}</td>
                <td class="right">${formatMoney(r.total || 0)}</td>
                <td class="right">${saldoHtml}</td>
                <td class="right">${deudasHtml}</td>
              </tr>
            `;
          }

          return `
            <tr class="${cls}">
              <td>${r.n}</td>
              <td>${escapeHtml(r.fecha || "")}${labelHtml}</td>
              <td class="right">${formatMoney(r.cantidad || 0)}</td>
              <td class="right">${formatMoney(r.total || 0)}</td>
              <td class="right">${saldoHtml}</td>
              <td class="right">${deudasHtml}</td>
            </tr>
          `;
        }).join("");

        const footJugador = `
          <tr class="player-total">
            <td><strong>Total Aportes</strong></td>
            <td colspan="3"></td>
            <td class="right"><strong>${formatMoney(p.total_mes || 0)}</strong></td>
            <td class="right"><strong>${formatMoney(p.saldo_fin_mes || 0)}</strong></td>
            <td></td>
          </tr>
          <tr class="player-sep"><td colspan="7"></td></tr>
        `;

        return rowsHtml + footJugador;
      }).join("")}
    </tbody>

    <tfoot>
      <tr class="total-general">
        <td><strong>Total General</strong></td>
        <td colspan="3"></td>
        <td class="right"><strong>${formatMoney(data.totales?.total_general_aportes || 0)}</strong></td>
        <td class="right"><strong>${formatMoney(data.totales?.total_general_saldo || 0)}</strong></td>
        <td></td>
      </tr>
    </tfoot>
  </table>
</div>
  `;

  // abrir limpio
  modal.classList.remove("closing");
  modal.classList.remove("hidden");
}

// Bind UNA SOLA VEZ (y sin closures de mes/año)
(function initEliminadosModalOnce(){
  const btn = document.getElementById("btnVerEliminados");
  if (!btn || btn.dataset.bound) return;
  btn.dataset.bound = "1";

  btn.addEventListener("click", async () => {
    const mesNow = parseInt(monthSelect.value, 10);
    const anioNow = parseInt(yearSelect.value, 10);
    const k = keyElim(anioNow, mesNow);

    try {
      // si no está cacheado, lo trae ahora mismo
      if (!window.__eliminadosMesCache[k]) {
        window.__eliminadosMesCache[k] = await fetchEliminadosMes(mesNow, anioNow);
      }
      renderModalEliminados(window.__eliminadosMesCache[k], mesNow, anioNow);
    } catch (e) {
      console.error("Error eliminados:", e);
      const modal = document.getElementById("modalEliminados");
      const body  = document.getElementById("modalEliminadosBody");
      if (body) body.innerHTML = `<div style="opacity:.85;">Error cargando eliminados. Revisa consola.</div>`;
      if (modal) { modal.classList.remove("closing"); modal.classList.remove("hidden"); }
    }
  });

  // cerrar (simple y estable)
  const close = document.getElementById("closeModalEliminados");
  document.addEventListener("click", (e) => {
    const modal = document.getElementById("modalEliminados");
    if (!modal) return;

    if (e.target === close || e.target === modal) {
      modal.classList.add("hidden");
      modal.classList.remove("closing");
    }
  });

  document.addEventListener("keydown", (e) => {
    if (e.key !== "Escape") return;
    const modal = document.getElementById("modalEliminados");
    if (!modal) return;
    modal.classList.add("hidden");
    modal.classList.remove("closing");
  });
})();

// Esta función la sigues llamando desde refreshSheet() para:
// - precargar cache
// - actualizar total en la tarjeta
async function cargarEliminadosMes(mes, anio) {
  const el = document.getElementById("totalEliminadosMes");
  const mesN = parseInt(mes, 10);
  const anioN = parseInt(anio, 10);
  const k = keyElim(anioN, mesN);

  try {
    const data = await fetchEliminadosMes(mesN, anioN);
    window.__eliminadosMesCache[k] = data;

    const totalMes = data?.totales?.eliminados_mes || 0;
    if (el) el.textContent = formatMoney(totalMes);
  } catch (e) {
    window.__eliminadosMesCache[k] = { ok:false };
    if (el) el.textContent = formatMoney(0);
  }
}


// ----------- REFRESH COMPLETA -----------------



async function refreshSheet() {
  const mes = monthSelect.value;
  const anio = yearSelect.value;


  // ✅ cargar el "otro día" guardado para ese mes/año
  currentOtroDia = getStoredOtroDia(mes, anio);

  await loadSheet(mes, anio);
  await loadTotals(mes, anio);
  try {
    await cargarAportesEsporadicos(mes, anio, currentOtroDia);
  } catch (e) {
    console.warn("Esporádicos falló, continúo:", e);
  }

  await cargarEliminadosMes(mes, anio);

  try {
    await loadGastos();
  } catch (e) {
    console.warn("Gastos falló:", e);
  }
  await loadOtrosPartidosInfo(mes, anio);
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


  // --- MOSTRAR / OCULTAR TABLA APORTANTES (con overlay + click fuera) ---
  const btnVer = document.getElementById("btnVerAportantes");
  const middlePanel = document.getElementById("middlePanel") || document.querySelector(".middle-panel");
  const overlay = document.getElementById("overlayAportantes");

  function openAportantes() {
    if (!middlePanel) return;
    middlePanel.classList.add("expanded");
    overlay?.classList.add("show");
    overlay?.setAttribute("aria-hidden", "false");
    if (btnVer) btnVer.textContent = "Ocultar Aportantes";

    // reset scroll
    middlePanel.scrollTop = 0;
    middlePanel.scrollLeft = 0;
  }

  function closeAportantes() {
    if (!middlePanel) return;
    middlePanel.classList.remove("expanded");
    overlay?.classList.remove("show");
    overlay?.setAttribute("aria-hidden", "true");
    if (btnVer) btnVer.textContent = "Ver Aportantes";
  }

  function toggleAportantes() {
    if (!middlePanel) return;
    const isOpen = middlePanel.classList.contains("expanded");
    isOpen ? closeAportantes() : openAportantes();
  }

  if (btnVer && middlePanel) {
    btnVer.addEventListener("click", (e) => {
      e.preventDefault();
      toggleAportantes();
    });
  }

  // click fuera (overlay)
  overlay?.addEventListener("click", closeAportantes);

  // ESC para cerrar
  document.addEventListener("keydown", (e) => {
    if (e.key === "Escape" && middlePanel?.classList.contains("expanded")) {
      closeAportantes();
    }
  });

  // evitar que clicks dentro de la tabla cierren (por si luego usas listener global)
  middlePanel?.addEventListener("click", (e) => e.stopPropagation());



})

// ----------- CERRAR SESION ----------------- //
const btnLogout = document.getElementById("btnLogout");
if (btnLogout) {
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

      Swal.fire({
        title: "¡Sesión Cerrada!",
        text: "¡Te Esperamos Pronto!",
        icon: "success",
        iconColor: "#0e9625ff",
        confirmButtonText: "OK"
      }).then(() => {

        // 🔥 Llamada real al logout.php (RUTA CORRECTA)
        fetch("/APORTES_FUTBOL/backend/auth/logout.php", {
          method: 'POST',
          credentials: 'include'   // incluye cookie de sesión sí o sí
        })
          .then(() => {
            // Redirigir al index público
            window.location.href = "/APORTES_FUTBOL/public/index.php";
          })
          .catch(() => {
            // Fallback al raíz del proyecto
            window.location.href = "/APORTES_FUTBOL/";
          });

      });
    });
  });
}



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
document.getElementById("btnAddGasto").addEventListener("click", async () => {
  const nombre = document.getElementById("gastoNombre").value.trim();
  const valor = parseInt(document.getElementById("gastoValor").value || 0);

  if (!nombre || valor <= 0) {
    Swal.fire({
      icon: 'info',
      title: "Datos Incompletos",
      text: "Debes Ingresar el Nombre del Gasto y un Valor Mayor a Cero."
    });
    return;
  }

  // 🟡 Confirmación antes de crear gasto
  const confirm = await Swal.fire({
    title: "¿Registrar este gasto?",
    html: `<b>${nombre}</b><br>Valor: <b>${valor.toLocaleString()}</b>`,
    icon: "question",
    iconColor: "#03d7fcff",
    showCancelButton: true,
    confirmButtonText: "Sí, registrar",
    cancelButtonText: "Cancelar",
    confirmButtonColor: "#28a745",
    cancelButtonColor: "#d33"
  });

  if (!confirm.isConfirmed) return;

  // 🟢 Enviar al backend
  const formData = new FormData();
  formData.append("nombre", nombre);
  formData.append("valor", valor);
  formData.append("mes", monthSelect.value);
  formData.append("anio", yearSelect.value);

  const res = await fetch(`${API}/aportes/add_gasto.php`, {
    method: "POST",
    body: formData
  });

  const data = await res.json();

  if (data.ok) {
    // 🟢 Mostrar mensaje de éxito
    Swal.fire({
      icon: "success",
      iconColor: "#28a745",
      title: "Gasto registrado",
      text: "Nuevo Gasto Agregado Correctamente."
    });

    // limpiar inputs
    document.getElementById("gastoNombre").value = "";
    document.getElementById("gastoValor").value = "";

    // 🟢 Actualizar totales y lista de gastos sin recargar página
    await loadGastos();
    await loadTotals(monthSelect.value, yearSelect.value);
  } else {
    Swal.fire({
      icon: "error",
      title: "Error",
      text: "No fue posible registrar el gasto."
    });
  }
});

async function loadGastos() {
  let res = await fetch(`${API}/aportes/listar_gastos.php?mes=${monthSelect.value}&anio=${yearSelect.value}`);
  let data = await res.json();
  const ul = document.getElementById("listaGastos");

  ul.innerHTML = "";

  data.gastos.forEach(g => {
    let li = document.createElement("li");
    li.classList.add("gastos-regist-card-items");


    li.innerHTML = `
        <span>${g.nombre}: <strong class="totales-gastos-item-value">${g.valor.toLocaleString()}</strong><p class="linea-span">___________________________</p></span>
        <div class="buttons-gastos-container">
         <button class="btnEditGasto" data-id="${g.id}" data-nombre="${g.nombre}" data-valor="${g.valor}">✏️</button>
         <button class="btnDeleteGasto" data-id="${g.id}">🗑️</button>
         </div>  
        `;

    ul.appendChild(li);
  });

  // Botones editar
  document.querySelectorAll(".btnEditGasto").forEach(b => {
    b.addEventListener("click", editarGasto);
  });

  // Botones borrar
  document.querySelectorAll(".btnDeleteGasto").forEach(b => {
    b.addEventListener("click", eliminarGasto);
  });
}


async function editarGasto(e) {
  const id = e.target.dataset.id;
  const nombre = e.target.dataset.nombre;
  const valor = e.target.dataset.valor;

  const result = await Swal.fire({
    title: "Editar Gasto",
    html: `
            <input id="editNombre" class="swal2-input" value="${nombre}">
            <input id="editValor" type="number" class="swal2-input" value="${valor}">
        `,
    confirmButtonText: "Sí, Editar",
    cancelButtonText: "Cancelar",
    confirmButtonColor: "#28a745",
    showCancelButton: true,
    cancelButtonColor: "#d33"
  });

  if (!result.isConfirmed) return;

  const nuevoNombre = document.getElementById("editNombre").value.trim();
  const nuevoValor = parseInt(document.getElementById("editValor").value || 0);

  let fd = new FormData();
  fd.append("id", id);
  fd.append("nombre", nuevoNombre);
  fd.append("valor", nuevoValor);

  let res = await fetch(`${API}/aportes/update_gasto.php`, {
    method: "POST",
    body: fd
  });

  let j = await res.json();

  if (j.ok) {
    Swal.fire({
      icon: "success",
      iconColor: "#28a745",
      title: "Gasto Editado",
      text: "El Gasto Fue Actualizado Correctamente."

    });
    loadGastos();
    loadTotals(monthSelect.value, yearSelect.value);
  }
}



async function eliminarGasto(e) {
  const id = e.target.dataset.id;

  const confirm = await Swal.fire({
    title: "¿Eliminar este gasto?",
    icon: "question",
    iconColor: "#03d7fcff",
    confirmButtonText: "Sí, Eliminar",
    cancelButtonText: "Cancelar",
    confirmButtonColor: "#28a745",
    showCancelButton: true,
    cancelButtonColor: "#d33"
  });

  if (!confirm.isConfirmed) return;

  let fd = new FormData();
  fd.append("id", id);

  let res = await fetch(`${API}/aportes/delete_gasto.php`, {
    method: "POST",
    body: fd
  });

  let j = await res.json();

  if (j.ok) {
    Swal.fire({
      icon: "success",
      iconColor: "#28a745",
      title: "Gasto Eliminado",
      text: "El Gasto Fue Eliminado Correctamente."
    });
    loadGastos();
    loadTotals(monthSelect.value, yearSelect.value);
  }
}


document.addEventListener("change", async (e) => {
  if (!e.target.classList.contains("chk-deuda")) return;

  const chk = e.target;
  const id = chk.dataset.player;
  const fecha = chk.dataset.fecha;

  // Clase temporal SOLO para animar el clickeado
  chk.classList.add("clicked-once");

  const accion = chk.checked ? "agregar" : "borrar";

  try {
    const res = await fetch(`${API}/aportes/deudas.php`, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ accion, id_jugador: id, fecha }),
    });

    const data = await res.json();
    if (data.ok) {
      setTimeout(() => refreshSheet(), 150);
    }

  } catch (err) {
    console.error("Error:", err);
  }
});


function mostrarDetalleAporte(e, texto) {
  e.stopPropagation();
  Swal.fire({
    icon: 'info',
    title: 'Detalle del aporte',
    text: texto,
    confirmButtonText: 'OK'
  });
}

/* ==========================================================
   TOOLTIP APORTE EXCEDENTE – ADMIN (MÓVIL + DESKTOP)
========================================================== */

document.addEventListener("pointerup", function (e) {

  const cell = e.target.closest(".aporte-excedente, .saldo-usado");
  if (!cell) return;

  // eliminar tooltip previo
  document.querySelectorAll(".tooltip-aporte").forEach(t => t.remove());

  let text = "";

  if (cell.classList.contains("aporte-excedente")) {
    const real = Number(cell.dataset.real || 0);
    if (!real) return;
    text = `Aportó ${real.toLocaleString("es-CO", {
      style: "currency",
      currency: "COP",
      maximumFractionDigits: 0
    })}`;
  } else {
    const usado = Number(cell.dataset.saldoUso || 0);
    if (!usado) return;
    text = `Usó saldo ${usado.toLocaleString("es-CO", {
      style: "currency",
      currency: "COP",
      maximumFractionDigits: 0
    })}`;
  }

  const tip = document.createElement("div");
  tip.className = "tooltip-aporte";
  tip.textContent = text;

  document.body.appendChild(tip);

  const rect = cell.getBoundingClientRect();

  tip.style.position = "absolute";
  tip.style.left = (window.scrollX + rect.left + rect.width / 2) + "px";
  tip.style.top = (window.scrollY + rect.top - 8) + "px";
  tip.style.transform = "translate(-50%, -100%)";

  setTimeout(() => tip.remove(), 2000);
});



// =============================
// EDITAR APORTANTE (NOMBRE / TEL)
// =============================
document.addEventListener("click", (e) => {
  const btn = e.target.closest(".btn-edit-player");
  if (!btn) return;

  console.log("CLICK EDIT:", btn.dataset);  // 👈 prueba
  const id = btn.dataset.id;
  const nombre = btn.dataset.nombre || "";
  const telefono = btn.dataset.telefono || "";

  Swal.fire({
    title: "Editar aportante",
    html: `
          <div style="text-align:left">
            <label>Nombre del aportante</label>
            <input id="swalNombre" class="swal2-input" value="${nombre}">
          </div>
          <div style="text-align:left; margin-top:8px;">
            <label>Teléfono</label>
            <input id="swalTelefono" class="swal2-input" value="${telefono}">
          </div>
        `,
    focusConfirm: false,
    showCancelButton: true,
    confirmButtonText: "Guardar cambios",
    cancelButtonText: "Cancelar",
    preConfirm: () => {
      const nuevoNombre = document.getElementById("swalNombre").value.trim();
      const nuevoTelefono = document.getElementById("swalTelefono").value.trim();

      if (!nuevoNombre) {
        Swal.showValidationMessage("El nombre no puede estar vacío");
        return false;
      }

      return {
        nombre: nuevoNombre,
        telefono: nuevoTelefono
      };
    }
  }).then((result) => {
    if (!result.isConfirmed) return;

    const { nombre: nuevoNombre, telefono: nuevoTelefono } = result.value;
    const fd = new FormData();
    fd.append("id", id);
    fd.append("nombre", nuevoNombre);
    fd.append("telefono", nuevoTelefono);

    fetch(`${API}/aportes/update_player.php`, {
      method: "POST",
      body: fd
    })
      .then(r => r.json())
      .then(res => {
        if (!res.ok) {
          Swal.fire("Error", res.msg || "No se pudo actualizar el aportante", "error");
          return;
        }

        Swal.fire("Actualizado", "Datos del aportante actualizados correctamente", "success");

        // 🔁 Refrescar tabla mensual
        if (typeof refreshSheet === "function") {
          refreshSheet();
        }

        // 🔁 Refrescar listado de aportantes del panel izquierdo (select)
        if (typeof loadPlayersList === "function") {
          loadPlayersList();
        }
      })
      .catch(() => {
        Swal.fire("Error", "Error de comunicación con el servidor", "error");
      });
  });
});



function findColumnIndex(table, headerText) {
  const head = table.tHead;
  if (!head || head.rows.length < 2) return -1;

  // segunda fila de headers
  const row = head.rows[1];
  const target = headerText.trim().toLowerCase();

  for (let i = 0; i < row.cells.length; i++) {
    const t = (row.cells[i].textContent || "").trim().toLowerCase();
    if (t === target) return row.cells[i].cellIndex;
  }
  return -1;
}

function parseCellInputValue(cell) {
  const input = cell?.querySelector?.("input.cell-aporte");
  if (!input) return 0;
  const raw = (input.value || "").toString().trim();
  if (!raw) return 0;
  const n = parseInt(raw.replace(/[^\d]/g, ""), 10);
  return isNaN(n) ? 0 : n;
}

function parseTextNumber(txt) {
  const n = parseInt(String(txt || "").replace(/[^\d]/g, ""), 10);
  return isNaN(n) ? 0 : n;
}

function recomputePlanilla(table) {
  const tbody = table.tBodies[0];
  const tfoot = table.tFoot?.rows?.[0];
  const head2 = table.tHead?.rows?.[1];
  if (!tbody || !tfoot || !head2) return;

  const idxTipo = findColumnIndex(table, "Tipo");
  const idxValor = findColumnIndex(table, "Valor");
  const idxPorJugador = findColumnIndex(table, "Por Jugador");

  if (idxTipo === -1 || idxValor === -1 || idxPorJugador === -1) return;

  // columnas de días: desde col 1 hasta antes de "Tipo"
  const firstDayCol = 1;
  const lastDayCol = idxTipo - 1;

  // total por columna día
  const colTotals = new Array(lastDayCol - firstDayCol + 1).fill(0);

  // recalcular filas
  for (const row of tbody.rows) {
    let sumDias = 0;

    for (let c = firstDayCol; c <= lastDayCol; c++) {
      const v = parseCellInputValue(row.cells[c]);
      sumDias += v;
      colTotals[c - firstDayCol] += v;
    }

    const otros = parseTextNumber(row.cells[idxValor]?.textContent);
    const totalJugador = sumDias + otros;

    const tdPJ = row.cells[idxPorJugador];
    if (tdPJ) {
      const strong = tdPJ.querySelector("strong");
      const txt = totalJugador.toLocaleString("es-CO");
      if (strong) strong.textContent = txt;
      else tdPJ.textContent = txt;
    }
  }

  // pintar footer TOTAL DÍA (mismos índices)
  for (let c = firstDayCol; c <= lastDayCol; c++) {
    const td = tfoot.cells[c];
    if (!td) continue;
    const strong = td.querySelector("strong");
    const val = colTotals[c - firstDayCol] || 0;
    const txt = val.toLocaleString("es-CO");
    if (strong) strong.textContent = txt;
    else td.textContent = txt;
  }

  // recalcular total mes del footer (suma totales día + total otros)
  // (tu footer tiene: [TOTAL DÍA] + ... días ... + [Otro juego] + [TOTAL OTROS label] + [TOTAL OTROS value] + [TOTAL MES] + [SALDO])
  // Solo actualizamos el "TOTAL MES" (la celda que está bajo "Total Mes")
  const idxTotalMes = findColumnIndex(table, "Por Jugador"); // OJO: NO ES el footer
  // Mejor: buscar en footer por posición fija: tu "TOTAL MES" es la celda antes del saldo.
  // Como tu estructura puede variar, dejamos solo totales por día y por jugador (lo crítico).
}







function applySaldoMarker(inputEl, resp) {
  const td = inputEl.closest("td.celda-dia");
  if (!td) return;

  const usado = Number(resp?.consumido_target || 0);
  const flag = td.querySelector(".saldo-uso-flag"); // ✨

  if (usado > 0) {
    // Guardar valor para tooltip/tap
    td.dataset.saldoUso = String(usado);

    // Marca visual + tooltip
    td.classList.add("saldo-usado");
    td.title = `Usó saldo: ${usado.toLocaleString("es-CO")}`;

    // Mostrar símbolo ✨
    if (flag) flag.classList.add("show");

  } else {
    // Si ya no usó saldo (o borró), limpiar
    delete td.dataset.saldoUso;
    td.classList.remove("saldo-usado");

    // Ocultar símbolo ✨
    if (flag) flag.classList.remove("show");

    // Si no es excedente, quitar title
    if (!td.classList.contains("aporte-excedente")) {
      td.removeAttribute("title");
    }
  }
}


function applyExcedenteMarker(inputEl, realValue) {
  const td = inputEl.closest("td.celda-dia");
  if (!td) return;

  const real = Number(realValue || 0);
  const star = td.querySelector(".saldo-flag"); // ⭐

  if (real > 3000) {
    td.classList.add("aporte-excedente");
    td.dataset.real = String(real);
    td.title = `Aportó ${real.toLocaleString("es-CO")}`;

    if (star) {
      star.textContent = "★";
      star.classList.add("show");
    }

  } else {
    td.classList.remove("aporte-excedente");
    delete td.dataset.real;

    if (star) {
      star.classList.remove("show");
      setTimeout(() => { star.textContent = "★"; }, 0); // opcional, mantener el símbolo
    }

    if (td.classList.contains("saldo-usado")) {
      const usado = parseInt(td.dataset.saldoUso || "0", 10) || 0;
      td.title = `Usó saldo: ${usado.toLocaleString("es-CO")}`;
    } else {
      td.removeAttribute("title");
    }
  }
}


/* ==========================================================
   INIT: activar saldo-usado desde HTML (data-saldo-uso)
   Esto hace que al recargar o cambiar de mes:
   - el tooltip siga saliendo
   - y el símbolo ✨ no desaparezca
========================================================== */
function initSaldoFromHTML(container) {
  container.querySelectorAll("td.celda-dia[data-saldo-uso]").forEach(td => {
    const usado = parseInt(td.dataset.saldoUso || "0", 10) || 0;

    if (usado > 0) {
      td.classList.add("saldo-usado");
      td.title = `Usó saldo: ${usado.toLocaleString("es-CO")}`;

      // asegurar que el icono ✨ quede visible
      const flag = td.querySelector(".saldo-uso-flag");
      if (flag) flag.classList.add("show");
    } else {
      td.classList.remove("saldo-usado");
      if (!td.classList.contains("aporte-excedente")) td.removeAttribute("title");

      const flag = td.querySelector(".saldo-uso-flag");
      if (flag) flag.classList.remove("show");
    }
  });
}


async function loadOtrosPartidosInfo(mes, anio) {
  const box = document.getElementById("otrosPartidosInfo");
  if (!box) return;

  let j = null;
  try {
    const r = await fetch(`${API}/aportes/get_otros_partidos_info.php?mes=${mes}&anio=${anio}`);
    j = await r.json();
  } catch (e) {
    box.innerHTML = "";
    return;
  }

  if (!j || !j.ok) {
    box.innerHTML = "";
    return;
  }

  const cantidad = Number(j.cantidad || 0);
  const totalGeneralFmt = Number(j.total_general || 0).toLocaleString("es-CO");

  if (!cantidad) {
    box.innerHTML = `
      <div class='no-otros-partidos-alert'>
       Sin Registros de Otros Partidos Jugados (Días NO miércoles/sábado).
      </div>
    `;
    return;
  }

  const rows = (j.items || []).map((it, idx) => {
    const fecha = it.fecha_label || it.fecha;
    const valFmt = Number(it.efectivo_total || 0).toLocaleString("es-CO");
    return `
      <tr>
        <td style="padding:6px 8px;">Partido ${idx + 1}</td>
        <td style="padding:6px 8px;">${fecha}</td>
        <td style="padding:6px 8px; text-align:right;"><strong>${valFmt}</strong></td>
      </tr>
    `;
  }).join("");

  box.innerHTML = `
    <div>
      <div class='otros-partidos-title'">
        <span>Otros partidos (${cantidad})<span>
      </div>

      <div class='otros-partidos-container-table'>
        <table class='otros-partidos-table'>
          <thead>
            <tr>
              <th>Partido</th>
              <th>Fecha</th>
              <th>Total</th>
            </tr>
          </thead>
          <tbody>
            ${rows}
          </tbody>

          
          </table>
          <div class='otros-partidos-tfoot-table' style="margin-top:10px;">
              <div>
                <span class="otros-general-total-label-span">Total otros partidos:
                <span class="otros-general-total-value-span">${totalGeneralFmt}</span></span>
          
              </div>
          </div>
      </div>

    </div>
  `;
}


async function cargarAportesEsporadicos(mes, anio, otroDia) {
  const wrap = document.getElementById("esporadicosWrap");
  const btnAdd = document.getElementById("btnAddEspRow");
  if (!wrap) return;

  if (btnAdd && !btnAdd.dataset.bound) {
    btnAdd.dataset.bound = "1";
    btnAdd.addEventListener("click", async () => {
      if (__espSlots >= 22) return;
      __espSlots++;
      await cargarAportesEsporadicos(mes, anio, otroDia);
    });
  }

  // const r = await fetch(`${API_ESP_GET}?mes=${mes}&anio=${anio}&otro=${otroDia||""}&slots=${__espSlots}`, { cache:"no-store" });
  // const data = await r.json();

  const url = `${API_ESP_GET}?mes=${mes}&anio=${anio}&otro=${otroDia || ""}&slots=${__espSlots}`;
  const r = await fetch(url, { cache: "no-store" });

  const txt = await r.text(); // 👈 siempre lee texto primero
  let data = null;

  try { data = JSON.parse(txt); }
  catch (e) {
    console.error("Respuesta NO JSON en esporádicos:", { url, status: r.status, txt });
    throw e; // para que refreshSheet lo capture
  }

  if (!r.ok || !data?.ok) {
    console.error("Error esporádicos:", { url, status: r.status, data });
    throw new Error("get esporádicos no ok");
  }
  __espCache = data;

  if (!data?.ok) {
    wrap.innerHTML = `<div style="opacity:.85;">No se pudo cargar aportes esporádicos.</div>`;
    return;
  }

  renderTablaEsporadicos(wrap, data);
}

function renderTablaEsporadicos(wrap, data) {
  const dias = Array.isArray(data.dias_validos) ? data.dias_validos : [];
  const anio = Number(data.anio);
  const mes = Number(data.mes);
  const otroDia = Number(data.otro_dia);

  const fechasDias = dias.map(d => `${anio}-${String(mes).padStart(2, "0")}-${String(d).padStart(2, "0")}`);
  const fechaOtro = data.fecha_otro;

  let html = `
    <div style="overflow:auto; max-height:60vh;">
      <table class="esp-table">
        <thead>
          <tr>
            <th>#</th>
            ${dias.map(d => `<th>${d}</th>`).join("")}
            <th>Otra Fecha (${String(otroDia).padStart(2, "0")})</th>
            <th>Otro Aporte</th>
            <th>Nota</th>
          </tr>
        </thead>
        <tbody>
  `;
  const meta = data.meta_by_slot || {};
  const rows = Array.isArray(data.rows) ? data.rows : [];
  rows.forEach(row => {
    const slot = Number(row.slot);

    html += `<tr data-slot="${slot}"><td><strong>${slot}</strong></td>`;

    // días mié/sáb
    fechasDias.forEach(f => {
      const v = Number(row.dias?.[f] || 0);
      html += `<td>${cellEspHtml(slot, f, v)}</td>`;
    });

    // otro día
    html += `<td>${cellEspHtml(slot, fechaOtro, Number(row.otro || 0))}</td>`;
    const m = meta[slot] || {};
    const metaOtro = Number(m.otro_aporte || 0);
    const metaNota = (m.nota || "");


    html += `<td><input class="form-control esp-otros-input" data-slot="${slot}" placeholder="$0" value="${metaOtro ? metaOtro : ""}" /></td>`;
    html += `<td><input class="form-control esp-nota-input" data-slot="${slot}" placeholder="Nota…" value="${escapeHtml(metaNota)}" /></td>`;

    html += `</tr>`;
  });

  // TFOOT (totales por columna)
  const totals = data.totals_by_date || {};
  html += `</tbody><tfoot class="esp-tfoot"><tr><td>Totales</td>`;
  fechasDias.forEach(f => {
    html += `<td><strong>${formatMoney(Number(totals[f] || 0))}</strong></td>`;
  });
  html += `<td><strong>${formatMoney(Number(totals[fechaOtro] || 0))}</strong></td>`;
  html += `<td><strong>${formatMoney(0)}</strong></td>`; // otro aporte total (lo activamos cuando guardemos meta)


  // ✅ dejar aviso único
  html += `
    <div style="margin-top:10px; opacity:.92;">
      ℹ️ Los aportes esporádicos se suman automáticamente a los totales diarios, mensuales y anuales de la planilla principal.
    </div>
  `;

  wrap.innerHTML = html;

  // bind interacción celdas
  wrap.querySelectorAll(".esp-cell").forEach(el => bindEspCell(el));

  // ✅ bind de "Otro Aporte" y "Nota"
  wrap.querySelectorAll(".esp-otros-input, .esp-nota-input").forEach(inp => {
    inp.addEventListener("blur", async () => {
      const slot = Number(inp.dataset.slot);
      const tr = inp.closest("tr");
      if (!tr) return;

      const otrosEl = tr.querySelector(".esp-otros-input");
      const notaEl = tr.querySelector(".esp-nota-input");

      const otrosVal = parseInt(String(otrosEl?.value || "").replace(/[^\d]/g, ""), 10) || 0;
      const notaVal = String(notaEl?.value || "").trim();

      const mes = monthSelect.value;
      const anio = yearSelect.value;

      await fetch(API_ESP_META_SAVE, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ slot, mes, anio, otro_aporte: otrosVal, nota: notaVal })
      });
      await loadTotals(mes, anio);
      // ✅ refrescar totales sin recargar
      await refreshAfterEsporadicoSave({ refreshOtros: false });
    });
  });

}

function cellEspHtml(slot, fecha, valor) {
  const v = Number(valor || 0);
  const checked = v > 0 ? "checked" : "";
  const chipClass = v > 0 ? "esp-chip" : "esp-chip zero";
  const chipText = v > 0 ? formatMoney(v) : "$0";

  const tip = v === ESP_BASE
    ? "Aportó valor estándar"
    : (v > 0 ? "Click para editar valor" : "Marcar para agregar $3.000");

  return `
    <div class="esp-cell" data-slot="${slot}" data-fecha="${fecha}">
      <input type="checkbox" class="esp-check" ${checked} title="${tip}">
      <span class="${chipClass} esp-value" title="Click para editar valor">${chipText}</span>
      <span class="esp-pencil" title="Click para editar">✎</span>
    </div>
  `;
}

function bindEspCell(cell) {
  const slot = Number(cell.dataset.slot);
  const fecha = cell.dataset.fecha;

  const chk = cell.querySelector(".esp-check");
  const valEl = cell.querySelector(".esp-value");
  const pencil = cell.querySelector(".esp-pencil");

  async function setValor(newVal) {
    const v = Math.max(0, Number(newVal || 0));

    const resp = await fetch(API_ESP_SAVE, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ slot, fecha, valor: v })
    });

    // si la celda guardada fue en un día NO miércoles/sábado,
    // entonces afecta el bloque "Otros Partidos"
    // (tu backend lo calcula con DAYOFWEEK NOT IN (4,7))
    const d = new Date(fecha + "T00:00:00");
    const day = d.getDay(); // 0 dom ... 6 sáb
    const isMiercoles = (day === 3);
    const isSabado = (day === 6);
    const refreshOtros = !(isMiercoles || isSabado);

    await refreshAfterEsporadicoSave({ refreshOtros });
  }

  chk.addEventListener("change", async () => {
    if (chk.checked) {
      await setValor(ESP_BASE);
    } else {
      await setValor(0); // borra
    }
  });

  function editar() {
    const actualText = (valEl.textContent || "").replace(/[^\d]/g, "");
    const actual = Number(actualText || 0);
    const input = prompt("Ingrese valor del aporte esporádico:", String(actual || ESP_BASE));
    if (input === null) return;
    const v = Number(String(input).replace(/[^\d]/g, ""));
    if (!Number.isFinite(v) || v < 0) return;
    setValor(v);
  }

  valEl.addEventListener("click", editar);
  pencil.addEventListener("click", editar);
}


