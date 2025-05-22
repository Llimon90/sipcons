document.addEventListener("DOMContentLoaded", function () {
  cargarIncidencias();

  document.getElementById("report-form").addEventListener("submit", function (e) {
    e.preventDefault();

    const fechaInicio = document.getElementById("fecha-inicio").value;
    const fechaFin = document.getElementById("fecha-fin").value;

    if (fechaInicio && fechaFin && fechaFin < fechaInicio) {
      alert("❌ La fecha de fin no puede ser menor que la fecha de inicio.");
      return;
    }

    cargarIncidencias();
  });
});

async function cargarIncidencias() {
  const cliente = document.getElementById("cliente").value;
  const fechaInicio = document.getElementById("fecha-inicio").value;
  const fechaFin = document.getElementById("fecha-fin").value;
  const estatus = document.getElementById("estatus").value;
  const sucursal = document.getElementById("sucursal").value;
  const tecnico = document.getElementById("tecnico").value;

  const url = `../backend/buscar_reportes.php?cliente=${encodeURIComponent(cliente)}&fecha_inicio=${encodeURIComponent(fechaInicio)}&fecha_fin=${encodeURIComponent(fechaFin)}&estatus=${encodeURIComponent(estatus)}&sucursal=${encodeURIComponent(sucursal)}&tecnico=${encodeURIComponent(tecnico)}`;

  console.log("📡 Enviando petición a:", url);

  try {
    const response = await fetch(url);
    const data = await response.json();

    console.log("✅ Datos recibidos:", data);

    const tablaBody = document.getElementById("tabla-body");
    tablaBody.innerHTML = "";

    if (data.message) {
      tablaBody.innerHTML = `<tr><td colspan="7">${data.message}</td></tr>`;
      return;
    }

    data.forEach(incidencia => {
      const row = document.createElement("tr");

      // Celda con enlace a detalle.html usando el ID
      const celdaInterna = document.createElement("td");
      const enlace = document.createElement("a");
      enlace.href = `detalle.html?id=${incidencia.id}`;
      enlace.textContent = incidencia.numero_incidente || "N/A";
      enlace.style.color = "#007BFF";
      enlace.style.textDecoration = "underline";
      celdaInterna.appendChild(enlace);
      row.appendChild(celdaInterna);

      // Celdas restantes
      const columnas = ['numero', 'cliente', 'sucursal', 'falla', 'fecha', 'estatus'];
      columnas.forEach(campo => {
        const td = document.createElement("td");
        td.textContent = incidencia[campo] || "N/A";
        row.appendChild(td);
      });

      tablaBody.appendChild(row);
    });
  } catch (error) {
    console.error("❌ Error al cargar las incidencias:", error);
    document.getElementById("tabla-body").innerHTML = `<tr><td colspan="7">Error al cargar los datos.</td></tr>`;
  }
}

// Cargar clientes al iniciar
document.addEventListener('DOMContentLoaded', async () => {
  try {
    const response = await fetch('../backend/obtener-clientes.php');
    const clientes = await response.json();

    const selectClientes = document.getElementById('cliente');
    selectClientes.innerHTML = '<option value="">Seleccionar Cliente</option>';

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
});
