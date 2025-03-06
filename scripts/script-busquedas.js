document.addEventListener("DOMContentLoaded", function() {
  // Función para cargar las incidencias desde el servidor
  function cargarIncidencias() {
    fetch('../backend/buscar_reportes.php') // Cambia el archivo según tu nombre PHP
      .then(response => response.json())
      .then(data => {
        if (data.error) {
          document.getElementById('report-data').innerHTML = `<p>${data.error}</p>`;
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
      <table>
        <thead>
          <tr>
            <th># Incidencia</th>
            <th>Cliente</th>
            <th>Fecha</th>
            <th>Estatus</th>
            <th>Acción</th>
          </tr>
        </thead>
        <tbody>
    `;

    incidencias.forEach(incidencia => {
      html += `
        <tr>
          <td>${incidencia.numero}</td>
          <td>${incidencia.cliente}</td>
          <td>${incidencia.fecha}</td>
          <td>${incidencia.estatus}</td>
          <td><a href="detalle-incidencia.html?id=${incidencia.id}">Ver detalle</a></td>
        </tr>
      `;
    });

    html += `</tbody></table>`;
    document.getElementById('report-data').innerHTML = html;
  }

  // Cargar las incidencias al cargar la página
  cargarIncidencias();

  // Filtrar incidencias
  const form = document.getElementById('report-form');
  form.addEventListener('submit', function(e) {
    e.preventDefault();

    const cliente = document.getElementById('cliente').value;
    const fechaInicio = document.getElementById('fecha-inicio').value;
    const fechaFin = document.getElementById('fecha-fin').value;

    // Enviar los datos de filtro al backend
    fetch(`../backend/filtrar_incidencias.php?cliente=${cliente}&fecha_inicio=${fechaInicio}&fecha_fin=${fechaFin}`)
      .then(response => response.json())
      .then(data => {
        if (data.error) {
          document.getElementById('report-data').innerHTML = `<p>${data.error}</p>`;
        } else {
          mostrarIncidencias(data);
        }
      })
      .catch(error => {
        console.error('Error al filtrar incidencias:', error);
        document.getElementById('report-data').innerHTML = "<p>Error al filtrar los datos.</p>";
      });
  });
});
