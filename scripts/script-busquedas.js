document.addEventListener("DOMContentLoaded", function() {
  // Función para cargar las incidencias desde el servidor
  function cargarIncidencias() {
    fetch('../backend/buscar_reportes.php') // Asegúrate de que la ruta del archivo es correcta
      .then(response => response.json())
      .then(data => {
        if (data.error) {
          document.getElementById('report-data').innerHTML = `<p>${data.error}</p>`;
        } else if (data.message) {
          document.getElementById('report-data').innerHTML = `<p>${data.message}</p>`;
        } else {
          mostrarIncidencias(data);
        }
      })
      .catch(error => {
        console.error('Error al cargar las incidencias:', error);
        document.getElementById('report-data').innerHTML = "<p>Error al cargar los datos.</p>";
      });
  }

  // Función para mostrar las incidencias en una tabla
  function mostrarIncidencias(incidencias) {
    let html = `
      <div style="overflow-x: auto;">
        <table border="1" style="width: 100%; border-collapse: collapse;">
          <thead>
            <tr>
              <th># REPORTE INTERNO</th>
              <th># INCIDENCIA CLIENTE</th>
              <th>CLIENTE</th>
              <th>CONTACTO</th>
              <th>SUCURSAL</th>
              <th>FECHA</th>
              <th>TÉCNICO</th>
              <th>FALLA</th>
              <th>ESTATUS</th>  
              <th>TRABAJO REALIZADO</th>
            </tr>
          </thead>
          <tbody>
    `;

    incidencias.forEach(incidencia => {
      html += `
        <tr>
          <td>${incidencia.numero_incidente || 'N/A'}</td>
          <td>${incidencia.numero || 'N/A'}</td>
          <td>${incidencia.cliente || 'N/A'}</td>
          <td>${incidencia.contacto || 'N/A'}</td>
          <td>${incidencia.sucursal || 'N/A'}</td>
          <td>${incidencia.fecha || 'N/A'}</td>
          <td>${incidencia.tecnico || 'N/A'}</td>
          <td>${incidencia.falla || 'N/A'}</td>
          <td>${incidencia.estatus || 'N/A'}</td>
          <td>${incidencia.accion || 'N/A'}</td>
        </tr>
      `;
    });

    html += `
          </tbody>
        </table>
      </div>
    `;

    document.getElementById('report-data').innerHTML = html;
  }

  // Cargar las incidencias al cargar la página
  cargarIncidencias();
});
