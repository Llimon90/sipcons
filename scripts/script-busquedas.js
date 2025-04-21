document.addEventListener("DOMContentLoaded", function() {
  // Cargar incidencias al inicio sin filtros
  cargarIncidencias();

  // Evento del formulario
  document.getElementById("report-form").addEventListener("submit", function(e) {
    e.preventDefault();

    const fechaInicio = document.getElementById("fecha-inicio").value;
    const fechaFin = document.getElementById("fecha-fin").value;

    // Validar que la fecha de fin no sea menor que la fecha de inicio
    if (fechaInicio && fechaFin && fechaFin < fechaInicio) {
      alert("❌ La fecha de fin no puede ser menor que la fecha de inicio.");
      return; // Detener ejecución si la validación falla
    }

    cargarIncidencias();
  });





  function cargarIncidencias() {
    const cliente = document.getElementById("cliente").value;
    const fechaInicio = document.getElementById("fecha-inicio").value;
    const fechaFin = document.getElementById("fecha-fin").value;
    const estatus = document.getElementById("estatus").value;
    const sucursal = document.getElementById("sucursal").value;
  
    let url = `../backend/buscar_reportes.php?cliente=${encodeURIComponent(cliente)}&fecha_inicio=${encodeURIComponent(fechaInicio)}&fecha_fin=${encodeURIComponent(fechaFin)}&estatus=${encodeURIComponent(estatus)}&sucursal=${encodeURIComponent(sucursal)}`;
  
    console.log("📡 Enviando petición a:", url);
  
    fetch(url)
      .then(response => response.json())
      .then(data => {
        console.log("✅ Datos recibidos:", data);
  
        const tablaBody = document.getElementById("tabla-body");
        tablaBody.innerHTML = "";
  
        if (data.message) {
          tablaBody.innerHTML = `<tr><td colspan="7">${data.message}</td></tr>`;
          return;
        }
  
        data.forEach(incidencia => {
          const row = document.createElement("tr");
  
          // Crear la celda con el hipervínculo
          const celdaNumeroIncidente = document.createElement("td");
          const enlace = document.createElement("a");
          enlace.href = `detalle.html?id=${incidencia.id}`;
          enlace.textContent = incidencia.numero_incidente || "N/A";
          enlace.style.color = "blue";
          enlace.style.textDecoration = "underline";
          celdaNumeroIncidente.appendChild(enlace);
          row.appendChild(celdaNumeroIncidente);
  
          // Agregar las demás celdas
          row.innerHTML += `
            <td>${incidencia.numero || "N/A"}</td>
            <td>${incidencia.cliente || "N/A"}</td>
            <td>${incidencia.sucursal || "N/A"}</td>
            <td>${incidencia.falla || "N/A"}</td>
            <td>${incidencia.fecha || "N/A"}</td>
            <td>${incidencia.estatus || "N/A"}</td>
          `;
  
          tablaBody.appendChild(row);
        });
      })
      .catch(error => {
        console.error("❌ Error en la petición:", error);
        document.getElementById("tabla-body").innerHTML = `<tr><td colspan="7">Error al cargar los datos.</td></tr>`;
      });
  }
  

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
    alert('Error al cargar clientes en el select');
  }
}

// Cargar clientes cuando se cargue la página
document.addEventListener('DOMContentLoaded', cargarClientesEnSelect);
