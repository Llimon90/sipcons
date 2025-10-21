// Variables globales para paginación
let paginaActual = 1;
let registrosPorPagina = 10;
let incidenciasTotales = [];

document.addEventListener("DOMContentLoaded", function () {
  // Configuración de Flatpickr para las fechas
  flatpickr("#fecha-inicio", {
    dateFormat: "Y-m-d",
    allowInput: true
  });
  
  flatpickr("#fecha-fin", {
    dateFormat: "Y-m-d",
    allowInput: true
  });

  // Inicializar tooltips de Bootstrap
  const tooltipTriggerList = document.querySelectorAll('[data-bs-toggle="tooltip"]');
  const tooltipList = [...tooltipTriggerList].map(tooltipTriggerEl => new bootstrap.Tooltip(tooltipTriggerEl));

  cargarIncidencias();
  cargarClientes();

  document.getElementById("report-form").addEventListener("submit", function (e) {
    e.preventDefault();
    paginaActual = 1; // Resetear a primera página al aplicar nuevos filtros
    cargarIncidencias();
  });

  // Event listeners para paginación
  document.getElementById("btn-prev").addEventListener("click", function(e) {
    e.preventDefault();
    if (paginaActual > 1) {
      paginaActual--;
      mostrarIncidenciasPagina();
    }
  });

  document.getElementById("btn-next").addEventListener("click", function(e) {
    e.preventDefault();
    const totalPaginas = Math.ceil(incidenciasTotales.length / registrosPorPagina);
    if (paginaActual < totalPaginas) {
      paginaActual++;
      mostrarIncidenciasPagina();
    }
  });

  document.getElementById("select-registros").addEventListener("change", function(e) {
    registrosPorPagina = parseInt(e.target.value);
    paginaActual = 1;
    mostrarIncidenciasPagina();
  });

  // Botones para filtros rápidos - INTEGRADO
  document.querySelectorAll('.btn-filtro-rapido').forEach(button => {
    button.addEventListener('click', function() {
      const filtro = this.getAttribute('data-filtro');
      
      // Remover clase active de todos los botones
      document.querySelectorAll('.btn-filtro-rapido').forEach(btn => {
        btn.classList.remove('active');
      });
      
      // Agregar clase active al botón clickeado
      this.classList.add('active');
      
      // Resetear todos los filtros del formulario
      document.getElementById("report-form").reset();
      
      // Referencia al checkbox "solo activas"
      const soloActivasCheckbox = document.getElementById("solo-activas");
      
      // Configurar el filtro rápido seleccionado
      switch(filtro) {
        case 'Mr. Tienda/Mr. Chef':
          document.getElementById("tipo-equipo").value = "Mr. Tienda/Mr. Chef";
          soloActivasCheckbox.checked = true;   // activar el checkbox
          break;
        case 'Otros':
          document.getElementById("tipo-equipo").value = "Otros";
          soloActivasCheckbox.checked = true;   // activar el checkbox
          break;
        case 'todos':
          document.getElementById("tipo-equipo").value = "";
          soloActivasCheckbox.checked = false;  // desactivar el checkbox
          break;
      }
      
      // Aplicar filtro
      paginaActual = 1;
      cargarIncidencias();
    });
  });
});

async function cargarIncidencias() {
  const cliente = document.getElementById("cliente").value;
  const fechaInicio = document.getElementById("fecha-inicio").value;
  const fechaFin = document.getElementById("fecha-fin").value;
  const estatus = document.getElementById("estatus").value;
  const sucursal = document.getElementById("sucursal").value;
  const tecnico = document.getElementById("tecnico").value;
  const tipoEquipo = document.getElementById("tipo-equipo").value;
  const soloActivas = document.getElementById("solo-activas").checked;

  // DEBUG: Ver qué valores se están enviando
  console.log("Filtros aplicados:", {
    tipoEquipo: tipoEquipo,
    cliente: cliente,
    estatus: estatus,
    soloActivas: soloActivas
  });

  // Validación de fechas
  if (fechaInicio && fechaFin && fechaFin < fechaInicio) {
    alert("❌ La fecha de fin no puede ser menor que la fecha de inicio.");
    return;
  }

  // Construir URL con parámetros
  let url = `../backend/buscar_reportes.php?cliente=${encodeURIComponent(cliente)}&fecha_inicio=${encodeURIComponent(fechaInicio)}&fecha_fin=${encodeURIComponent(fechaFin)}&estatus=${encodeURIComponent(estatus)}&sucursal=${encodeURIComponent(sucursal)}&tecnico=${encodeURIComponent(tecnico)}`;
  
  // Agregar parámetros nuevos si existen
  if (tipoEquipo) {
    url += `&tipo_equipo=${encodeURIComponent(tipoEquipo)}`;
  }
  
  if (soloActivas) {
    url += `&solo_activas=1`;
  }

  console.log("URL de búsqueda:", url); // DEBUG

  try {
    // Mostrar indicador de carga
    document.getElementById("tabla-body").innerHTML = `<tr><td colspan="8" class="text-center">Buscando incidencias...</td></tr>`;
    
    const response = await fetch(url);
    const data = await response.json();

    console.log("Datos recibidos:", data); // DEBUG

    if (data.message) {
      document.getElementById("tabla-body").innerHTML = `<tr><td colspan="8">${data.message}</td></tr>`;
      incidenciasTotales = [];
      actualizarControlesPaginacion();
      return;
    }

    incidenciasTotales = data;
    mostrarIncidenciasPagina();
  } catch (error) {
    console.error("❌ Error al cargar las incidencias:", error);
    document.getElementById("tabla-body").innerHTML = `<tr><td colspan="8">Error al cargar los datos.</td></tr>`;
    incidenciasTotales = [];
    actualizarControlesPaginacion();
  }
}

function mostrarIncidenciasPagina() {
  const inicio = (paginaActual - 1) * registrosPorPagina;
  const fin = inicio + registrosPorPagina;
  const incidenciasPagina = incidenciasTotales.slice(inicio, fin);

  const tablaBody = document.getElementById("tabla-body");
  tablaBody.innerHTML = "";

  if (incidenciasPagina.length === 0) {
    tablaBody.innerHTML = `<tr><td colspan="8" class="text-center">No se encontraron incidencias con los filtros aplicados</td></tr>`;
  } else {
    incidenciasPagina.forEach(incidencia => {
      const row = document.createElement("tr");

      // Celda con enlace a detalle.html usando el ID
      const celdaInterna = document.createElement("td");
      const enlace = document.createElement("a");
      enlace.href = `detalle.html?id=${incidencia.id}`;
      enlace.textContent = incidencia.numero_incidente || "N/A";
      enlace.className = "text-decoration-none";
      celdaInterna.appendChild(enlace);
      row.appendChild(celdaInterna);

      // Celdas restantes (sin la columna de tipo equipo)
      const columnas = ['numero', 'cliente', 'sucursal', 'falla', 'fecha', 'estatus'];
      columnas.forEach(campo => {
        const td = document.createElement("td");
        td.textContent = incidencia[campo] || "N/A";
        row.appendChild(td);
      });

      // Celda para estado activo/inactivo
      const tdEstado = document.createElement("td");
      const esActiva = ['Abierto', 'Asignado', 'Pendiente', 'Completado'].includes(incidencia.estatus);
      const badge = document.createElement("span");
      badge.className = esActiva ? "badge-activo" : "badge-inactivo";
      badge.textContent = esActiva ? "Activa" : "Inactiva";
      tdEstado.appendChild(badge);
      row.appendChild(tdEstado);

      tablaBody.appendChild(row);
    });
  }

  actualizarControlesPaginacion();
}

function actualizarControlesPaginacion() {
  const totalPaginas = Math.ceil(incidenciasTotales.length / registrosPorPagina) || 1;
  const totalRegistros = incidenciasTotales.length;
  const inicio = Math.min((paginaActual - 1) * registrosPorPagina + 1, totalRegistros);
  const fin = Math.min(inicio + registrosPorPagina - 1, totalRegistros);
  
  // Actualizar contador de registros
  document.getElementById("contador-registros").textContent = 
    `Mostrando ${inicio}-${fin} de ${totalRegistros} registros`;
  
  // Actualizar estado de botones
  const btnPrev = document.getElementById("btn-prev");
  const btnNext = document.getElementById("btn-next");
  
  btnPrev.classList.toggle("disabled", paginaActual <= 1);
  btnNext.classList.toggle("disabled", paginaActual >= totalPaginas);
}

async function cargarClientes() {
  try {
    const response = await fetch('../backend/obtener-clientes.php');
    const clientes = await response.json();

    const selectClientes = document.getElementById('cliente');
    selectClientes.innerHTML = '<option value="">Seleccionar Cliente</option><option value="todos">Todos los clientes</option>';

    clientes.forEach(cliente => {
      const option = document.createElement('option');
      option.value = cliente.nombre;
      option.textContent = cliente.nombre;
      selectClientes.appendChild(option);
    });
  } catch (error) {
    console.error('Error al cargar clientes:', error);
    alert('Error al cargar clientes en el select');
  }
}

// Función para limpiar filtros
function limpiarFiltros() {
  document.getElementById("report-form").reset();
  
  // Remover clase active de todos los botones de filtros rápidos
  document.querySelectorAll('.btn-filtro-rapido').forEach(btn => {
    btn.classList.remove('active');
  });
  
  paginaActual = 1;
  cargarIncidencias();
}