// script-busquedas.js
let paginaActual = 1;
let registrosPorPagina = 10;
let incidenciasTotales = [];

document.addEventListener("DOMContentLoaded", function () {
  flatpickr("#fecha-inicio", { dateFormat: "Y-m-d", allowInput: true });
  flatpickr("#fecha-fin", { dateFormat: "Y-m-d", allowInput: true });

  cargarIncidencias();
  cargarClientes();

  const form = document.getElementById("report-form");
  if (form) {
    form.addEventListener("submit", function (e) {
      e.preventDefault();
      paginaActual = 1;
      cargarIncidencias();
    });
  }

  const btnPrev = document.getElementById("btn-prev");
  if (btnPrev) {
    btnPrev.addEventListener("click", (e) => { 
      e.preventDefault(); 
      if (paginaActual > 1) { paginaActual--; mostrarIncidenciasPagina(); } 
    });
  }
  
  const btnNext = document.getElementById("btn-next");
  if (btnNext) {
    btnNext.addEventListener("click", (e) => { 
      e.preventDefault(); 
      if (paginaActual < Math.ceil(incidenciasTotales.length / registrosPorPagina)) { paginaActual++; mostrarIncidenciasPagina(); } 
    });
  }

  const selectReg = document.getElementById("select-registros");
  if (selectReg) {
    selectReg.addEventListener("change", function(e) {
      registrosPorPagina = parseInt(e.target.value);
      paginaActual = 1;
      mostrarIncidenciasPagina();
    });
  }

  document.querySelectorAll('.btn-filtro-rapido').forEach(button => {
    button.addEventListener('click', function() {
      const filtro = this.getAttribute('data-filtro');
      document.querySelectorAll('.btn-filtro-rapido').forEach(btn => btn.classList.remove('active'));
      this.classList.add('active');
      
      const reportForm = document.getElementById("report-form");
      if(reportForm) reportForm.reset();

      if (filtro === 'programadas') {
        const checkProg = document.getElementById("solo-programadas");
        if(checkProg) checkProg.checked = true;
      } else if (filtro !== 'todos') {
        const selectEquipo = document.getElementById("tipo-equipo");
        const checkActivas = document.getElementById("solo-activas");
        if(selectEquipo) selectEquipo.value = filtro;
        if(checkActivas) checkActivas.checked = true;
      }
      paginaActual = 1;
      cargarIncidencias();
    });
  });
});

async function cargarIncidencias() {
  const getVal = (id) => document.getElementById(id) ? document.getElementById(id).value : '';
  const isChecked = (id) => document.getElementById(id) && document.getElementById(id).checked ? '1' : '';

  const params = {
    cliente: getVal("cliente"),
    fecha_inicio: getVal("fecha-inicio"),
    fecha_fin: getVal("fecha-fin"),
    estatus: getVal("estatus"),
    sucursal: getVal("sucursal"),
    tecnico: getVal("tecnico"),
    tipo_equipo: getVal("tipo-equipo"),
    solo_activas: isChecked("solo-activas"),
    solo_programadas: isChecked("solo-programadas"),
    t: Date.now()
  };

  const url = `../backend/buscar_reportes.php?${new URLSearchParams(params).toString()}`;
  const tablaBody = document.getElementById("tabla-body");
  
  if (!tablaBody) return;

  try {
    tablaBody.innerHTML = `<tr><td colspan="8" class="text-center">Consultando datos...</td></tr>`;
    
    const response = await fetch(url, { cache: 'no-store' });
    const rawText = await response.text(); 
    
    let data;
    try {
      data = JSON.parse(rawText);
    } catch (parseError) {
      console.error("El servidor devolvió un texto inválido:", rawText);
      tablaBody.innerHTML = `<tr><td colspan="8" class="text-center text-danger fw-bold">Error de lectura de datos. Revisa la consola (F12).</td></tr>`;
      return;
    }

    if (data.error) {
      tablaBody.innerHTML = `<tr><td colspan="8" class="text-center text-danger fw-bold">Error SQL: ${data.error}</td></tr>`;
      incidenciasTotales = [];
      return;
    }

    if (data.message) {
      tablaBody.innerHTML = `<tr><td colspan="8" class="text-center">${data.message}</td></tr>`;
      incidenciasTotales = [];
    } else {
      incidenciasTotales = data;
    }
    
    mostrarIncidenciasPagina();
    
  } catch (error) {
    console.error("Error de Fetch:", error);
    tablaBody.innerHTML = `<tr><td colspan="8" class="text-center text-danger">Falla de red / Error al conectar con servidor</td></tr>`;
  }
}

function mostrarIncidenciasPagina() {
  const inicio = (paginaActual - 1) * registrosPorPagina;
  const items = incidenciasTotales.slice(inicio, inicio + registrosPorPagina);
  const tablaBody = document.getElementById("tabla-body");
  if (!tablaBody) return;
  
  tablaBody.innerHTML = "";

  if (items.length === 0 && incidenciasTotales.length > 0) {
    tablaBody.innerHTML = `<tr><td colspan="8" class="text-center">No hay más páginas</td></tr>`;
    return;
  }

  items.forEach((inc, indexArray) => {
    const row = document.createElement("tr");
    const esProgramado = inc.estatus === 'Programado';
    const esActiva = ['Abierto', 'Asignado', 'Pendiente', 'Completado', 'Programado'].includes(inc.estatus);
    const indiceGlobal = inicio + indexArray;

    let enlaceHTML = esProgramado 
      ? `<a href="#" class="fw-bold text-primary text-decoration-none" onclick="abrirModalProgramada(${indiceGlobal}); return false;"><i class="bi bi-window-stack"></i> ${inc.numero_incidente}</a>`
      : `<a href="detalle.html?id=${inc.id}" class="text-decoration-none">${inc.numero_incidente || "N/A"}</a>`;

    row.innerHTML = `
      <td>${enlaceHTML}</td>
      <td>${inc.numero || "N/A"}</td>
      <td>${inc.cliente}</td>
      <td>${inc.sucursal}</td>
      <td>${inc.falla}</td>
      <td>${inc.fecha}</td>
      <td>${inc.estatus}</td>
      <td><span class="${esActiva ? "badge-activo" : "badge-inactivo"}">${esActiva ? "Activa" : "Inactiva"}</span></td>
    `;
    tablaBody.appendChild(row);
  });
  actualizarControlesPaginacion();
}

function abrirModalProgramada(indice) {
  const d = incidenciasTotales[indice];
  if (!d) return;

  const lista = d.detalles_completos ? d.detalles_completos.split('||') : [];
  
  let equiposHTML = '<ul class="list-group list-group-flush border rounded">';
  lista.forEach(e => { equiposHTML += `<li class="list-group-item"><i class="bi bi-cpu text-primary me-2"></i>${e}</li>`; });
  equiposHTML += '</ul>';

  const modalLabel = document.getElementById("modalProgramadaLabel");
  const modalBody = document.getElementById("modalProgramadaBody");

  if(modalLabel) modalLabel.innerHTML = `Programación: ${d.numero_incidente} - ${d.numero}`;
  
  if(modalBody) {
    modalBody.innerHTML = `
      <div class="row g-3 mb-3">
        <div class="col-md-6">
          <label class="text-muted small d-block">CLIENTE</label>
          <p class="fw-bold mb-0">${d.cliente}</p>
          <label class="text-muted small d-block mt-2">SUCURSAL</label>
          <p class="mb-0">${d.sucursal}</p>
        </div>
        <div class="col-md-6">
          <label class="text-muted small d-block">FECHA AGENDADA</label>
          <p class="mb-0"><span class="badge bg-warning text-dark px-3 py-2">${d.fecha}</span></p>
          <label class="text-muted small d-block mt-2">TIPO</label>
          <p class="mb-0 fw-bold">${d.numero_incidente === 'PROG-CAL' ? 'CALIBRACIÓN' : 'MANTENIMIENTO'}</p>
        </div>
      </div>
      <div class="bg-light p-3 rounded">
        <h6 class="fw-bold mb-3"><i class="bi bi-list-check"></i> Equipos vinculados a la visita:</h6>
        ${equiposHTML}
      </div>
    `;
  }

  const modalEl = document.getElementById('modalProgramada');
  if (modalEl) {
    // Solo obtenemos o creamos la instancia, sin mover el elemento HTML de su lugar
    let modalInstance = bootstrap.Modal.getInstance(modalEl);
    if (!modalInstance) {
        modalInstance = new bootstrap.Modal(modalEl);
    }
    modalInstance.show();
  }
}

function actualizarControlesPaginacion() {
  const total = incidenciasTotales.length;
  
  const btnPrev = document.getElementById("btn-prev");
  if (btnPrev) btnPrev.classList.toggle("disabled", paginaActual === 1);
  
  const btnNext = document.getElementById("btn-next");
  if (btnNext) btnNext.classList.toggle("disabled", paginaActual >= Math.ceil(total / registrosPorPagina) || total === 0);
  
  const contador = document.getElementById("contador-registros");
  if (contador) contador.textContent = `Total: ${total} registros`;
}

async function cargarClientes() {
  try {
    const response = await fetch(`../backend/obtener-clientes.php`);
    const clientes = await response.json();
    const select = document.getElementById('cliente');
    if (select) {
      clientes.forEach(c => { select.innerHTML += `<option value="${c.nombre}">${c.nombre}</option>`; });
    }
  } catch(e) { console.warn("Error cargando clientes"); }
}

function limpiarFiltros() {
  const form = document.getElementById("report-form");
  if (form) form.reset();
  
  const checkProg = document.getElementById("solo-programadas");
  if (checkProg) checkProg.checked = false;
  
  document.querySelectorAll('.btn-filtro-rapido').forEach(btn => btn.classList.remove('active'));
  paginaActual = 1;
  cargarIncidencias();
}