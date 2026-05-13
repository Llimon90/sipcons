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

    // CORRECCIÓN: Volvemos a usar la etiqueta <a> pero con javascript:void(0) para evitar que herede los estilos del botón y no salte la página.
    let enlaceHTML = esProgramado 
      ? `<a href="javascript:void(0);" class="fw-bold text-primary text-decoration-none" onclick="abrirModalProgramada(${indiceGlobal})"><i class="bi bi-window-stack"></i> ${inc.numero_incidente}</a>`
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

// =====================================================================
// LÓGICA DE MODAL NATIVO (CON CALCULADORA DE GARANTÍA Y FILTRO DE PERIODO)
// =====================================================================
window.abrirModalProgramada = function(indice) {
  const d = incidenciasTotales[indice];
  if (!d) return;

  const lista = d.detalles_completos ? d.detalles_completos.split('||') : [];
  let equiposHTML = '<ul class="list-group list-group-flush border rounded" style="padding:0; margin:0;">';
  
  lista.forEach(e => { 
      // El PHP nos mandó: Marca~Modelo~Serie~Calibracion~Servicio~Garantia~FechaVenta
      const partes = e.split('~');
      const marca = partes[0] || '';
      const modelo = partes[1] || '';
      const serie = partes[2] || 'S/N';
      const calibracion = parseInt(partes[3]) || 0;
      const servicio = parseInt(partes[4]) || 0;
      const garantiaMeses = parseInt(partes[5]) || 0;
      const fechaVenta = partes[6] || '';

      // --- CÁLCULO DE GARANTÍA CON FECHA EXACTA ---
      let badgeGarantia = `<span style="background:#bdc3c7; color:white; padding:4px 8px; border-radius:4px; font-size:0.75rem; white-space:nowrap;"><i class="fas fa-shield-alt"></i> Sin Garantía</span>`;
      
      if (garantiaMeses > 0 && fechaVenta) {
          const fVenta = new Date(fechaVenta + 'T12:00:00'); // Evita desfases de zona horaria
          const fFinGarantia = new Date(fVenta.getTime());
          fFinGarantia.setMonth(fFinGarantia.getMonth() + garantiaMeses);
          const hoy = new Date();
          const fechaVencimientoTexto = fFinGarantia.toISOString().split('T')[0];

          if (fFinGarantia > hoy) {
              const diffTime = fFinGarantia - hoy;
              const diffDays = Math.ceil(diffTime / (1000 * 60 * 60 * 24));
              let tiempoRestante = diffDays > 30 ? Math.floor(diffDays / 30) + " meses" : diffDays + " días";
              
              badgeGarantia = `<span style="background:#27ae60; color:white; padding:4px 8px; border-radius:4px; font-size:0.75rem; white-space:nowrap;" title="Quedan ${tiempoRestante}"><i class="fas fa-check-circle"></i> Garantía hasta: ${fechaVencimientoTexto}</span>`;
          } else {
              badgeGarantia = `<span style="background:#e74c3c; color:white; padding:4px 8px; border-radius:4px; font-size:0.75rem; white-space:nowrap;"><i class="fas fa-times-circle"></i> Venció: ${fechaVencimientoTexto}</span>`;
          }
      }

      // --- TEXTOS DE PERIODICIDAD SEPARADOS POR TIPO DE TICKET ---
      let textoPeriodo = "";
      if (d.numero_incidente === 'PROG-CAL' && calibracion > 0) {
          textoPeriodo = `<div style="font-size: 0.8rem; color:#7f8c8d; margin-top:4px;"><i class="fas fa-sync-alt"></i> Frecuencia: Calibración cada ${calibracion} meses</div>`;
      } else if (d.numero_incidente === 'PROG-SERV' && servicio > 0) {
          textoPeriodo = `<div style="font-size: 0.8rem; color:#7f8c8d; margin-top:4px;"><i class="fas fa-sync-alt"></i> Frecuencia: Mantenimiento cada ${servicio} meses</div>`;
      }

      // --- CONSTRUCCIÓN DEL ROW DEL EQUIPO ---
      equiposHTML += `
        <li class="list-group-item" style="border-bottom: 1px solid #eee; padding: 12px 15px;">
            <div style="display:flex; justify-content:space-between; align-items:flex-start;">
                <div>
                    <div style="font-weight:bold; color:#2c3e50;"><i class="bi bi-cpu text-primary me-2"></i> ${marca} ${modelo}</div>
                    <div style="font-size:0.85rem; color:#7f8c8d; margin-left: 23px;">Serie: ${serie}</div>
                    <div style="margin-left: 23px;">${textoPeriodo}</div>
                </div>
                <div style="margin-left: 15px; margin-top:2px;">
                    ${badgeGarantia}
                </div>
            </div>
        </li>
      `;
  });
  equiposHTML += '</ul>';

  const modalLabel = document.getElementById("modalProgramadaLabel");
  const modalBody = document.getElementById("modalProgramadaBody");

  if(modalLabel) {
      modalLabel.innerHTML = `<i class="bi bi-calendar-check text-primary"></i> Programación: ${d.numero_incidente} - ${d.numero}`;
  }
  
  if(modalBody) {
    modalBody.innerHTML = `
      <div class="row g-3 mb-3">
        <div class="col-md-6">
          <label class="text-muted small d-block" style="text-transform:uppercase; font-weight:bold;">Cliente</label>
          <p class="mb-0" style="font-size:1.1rem; color:#2c3e50;"><strong>${d.cliente}</strong></p>
          <label class="text-muted small d-block mt-3" style="text-transform:uppercase; font-weight:bold;">Sucursal</label>
          <p class="mb-0">${d.sucursal}</p>
        </div>
        <div class="col-md-6">
          <label class="text-muted small d-block" style="text-transform:uppercase; font-weight:bold;">Fecha Agendada</label>
          <p class="mb-0"><span class="badge bg-warning text-dark px-3 py-2" style="font-size:1rem; border:1px solid #e1b100;">${d.fecha}</span></p>
          <label class="text-muted small d-block mt-3" style="text-transform:uppercase; font-weight:bold;">Tipo de Visita</label>
          <p class="mb-0 fw-bold" style="color:#2980b9;">${d.numero_incidente === 'PROG-CAL' ? 'CALIBRACIÓN' : 'MANTENIMIENTO PREVENTIVO'}</p>
        </div>
      </div>
      <div class="bg-light p-3 rounded border">
        <h6 class="fw-bold mb-3" style="color:#2c3e50;"><i class="bi bi-list-check"></i> Equipos vinculados a la visita:</h6>
        ${equiposHTML}
      </div>
    `;
  }

  const modalEl = document.getElementById('modalProgramada');
  if (modalEl) {
      modalEl.style.display = 'flex';
  }
};

window.cerrarModalProgramada = function() {
  const modalEl = document.getElementById('modalProgramada');
  if (modalEl) {
      modalEl.style.display = 'none';
  }
};

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