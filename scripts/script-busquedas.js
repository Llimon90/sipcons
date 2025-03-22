document.addEventListener("DOMContentLoaded", function() {
  // Cargar incidencias al inicio sin filtros
  cargarIncidencias();

  // Evento del formulario
  document.getElementById("report-form").addEventListener("submit", function(e) {
    e.preventDefault();
    cargarIncidencias();
  });

  // Función para cargar incidencias
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

        // Limpiar tabla antes de insertar datos nuevos
        const tablaBody = document.getElementById("tabla-body");
        tablaBody.innerHTML = "";

        if (data.message) {
          // Mostrar mensaje cuando no hay resultados
          tablaBody.innerHTML = `<tr><td colspan="7">${data.message}</td></tr>`;
          return;
        }

        // Agregar filas a la tabla
        data.forEach(incidencia => {
          const row = document.createElement("tr");
          row.innerHTML = `
            <td>${incidencia.numero_incidente || "N/A"}</td>
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
});
