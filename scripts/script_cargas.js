

// Función para cargar los nombres de los clientes en el select
async function cargarClientesEnSelect() {
    try {
      const response = await fetch('../backend/obtener-clientes.php');
      const clientes = await response.json();
  
      const selectClientes = document.getElementById('cliente');
      selectClientes.innerHTML = '<option value="">Seleccione un cliente</option>';
  
      clientes.forEach(cliente => {
        const option = document.createElement('option');
        option.value = cliente.nombre; // Usa el ID del cliente como valor
        option.textContent = cliente.nombre; // Muestra el nombre en el select
        selectClientes.appendChild(option);
      });
    } catch (error) {
      console.error('Error al cargar clientes:', error);
      AppAviso.alerta('Error al cargar clientes en el select');
    }
  }
  
 // --- Cargar clientes ---
async function cargarClientesEnSelect() {
  try {
    const response = await fetch(`../backend/obtener-clientes.php?t=${Date.now()}`, { cache: "no-store" });
    const clientes = await response.json();

    const selectClientes = document.getElementById('cliente');
    selectClientes.innerHTML = '<option value="">Seleccione un cliente</option>';

    clientes.forEach(cliente => {
      const option = document.createElement('option');
      option.value = cliente.nombre;
      option.textContent = cliente.nombre;
      selectClientes.appendChild(option);
    });
  } catch (error) {
    console.error('Error al cargar clientes:', error);
    AppAviso.alerta('Error al cargar clientes en el select');
  }
}

document.addEventListener('DOMContentLoaded', cargarClientesEnSelect);


// --- Cargar incidencias ---
document.addEventListener("DOMContentLoaded", function () {
  // D-42: "Programadas" muestra las órdenes con fecha futura (no salen en activas hasta su día)
  let vista = '';
  document.querySelectorAll('.lista-vistas [data-vista]').forEach(boton => boton.addEventListener('click', () => {
    vista = boton.dataset.vista;
    document.querySelectorAll('.lista-vistas [data-vista]').forEach(b => b.setAttribute('aria-pressed', String(b === boton)));
    document.getElementById('titulo-lista').textContent = vista ? 'Órdenes de servicio programadas' : 'Órdenes de servicio activas';
    cargarIncidencias();
  }));

  async function cargarIncidencias() {
    try {
      const response = await fetch(`../backend/server.php?t=${Date.now()}${vista ? '&programadas=1' : ''}`, { cache: "no-store" });
      const data = await response.json();
      const tbody = document.getElementById("tabla-body");
      tbody.innerHTML = "";

      if (data.error) {
        tbody.innerHTML = `<tr><td colspan="10">${escHTML(data.error)}</td></tr>`;
        return;
      }

      if (data.message) {
        tbody.innerHTML = `<tr><td colspan="10">${escHTML(data.message)}</td></tr>`;
        return;
      }

      let incidenciasArray = data.map(incidencia => ({
        id: incidencia.id,
        numero_incidente: incidencia.numero_incidente,
        reporte_cliente: incidencia.reporte_cliente,
        cliente: incidencia.cliente,
        sucursal: incidencia.sucursal,
        falla: incidencia.falla,
        fecha: incidencia.fecha,
        estatus: incidencia.estatus
      }));

      incidenciasArray.forEach(incidencia => {
        const fila = document.createElement("tr");

        const celdaNumeroIncidente = document.createElement("td");
        const enlace = document.createElement("a");
        enlace.href = `detalle.html?id=${escHTML(incidencia.id)}`;
        enlace.textContent = incidencia.numero_incidente;

        celdaNumeroIncidente.appendChild(enlace);

        const estatus = window.AppTablas
          ? AppTablas.estatusHTML(incidencia.estatus)
          : incidencia.estatus;

        fila.innerHTML = `
          <td></td>
          <td>${escHTML(incidencia.reporte_cliente ?? '')}</td>
          <td>${escHTML(incidencia.cliente)}</td>
          <td>${escHTML(incidencia.sucursal)}</td>
          <td>${escHTML(incidencia.falla)}</td>
          <td>${escHTML(incidencia.fecha)}</td>
          <td>${estatus}</td>
        `;

        fila.children[0].replaceWith(celdaNumeroIncidente);
        tbody.appendChild(fila);
      });
    } catch (error) {
      console.error("Error al cargar órdenes de servicio:", error);
    }
  }

  // Para refrescar la lista tras registrar una incidencia sin recargar la página
  window.recargarListaIncidencias = cargarIncidencias;

  cargarIncidencias();
});
