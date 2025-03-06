// document.addEventListener("DOMContentLoaded", function() {
//   // Función para cargar las incidencias desde el servidor
//   function cargarIncidencias() {
//     fetch('../backend/buscar_reportes.php') // Asegúrate de que la ruta del archivo es correcta
//       .then(response => response.json())
//       .then(data => {
//         if (data.error) {
//           document.getElementById('report-data').innerHTML = `<p>${data.error}</p>`;
//         } else if (data.message) {
//           document.getElementById('report-data').innerHTML = `<p>${data.message}</p>`;
//         } else {
//           mostrarIncidencias(data);
//         }
//       })
//       .catch(error => {
//         console.error('Error al cargar las incidencias:', error);
//         document.getElementById('report-data').innerHTML = "<p>Error al cargar los datos.</p>";
//       });
//   }

//   // Función para mostrar las incidencias en una tabla
//   function mostrarIncidencias(incidencias) {
//     let html = `
//       <div style="overflow-x: auto;">
//         <table border="1" style="width: 100%; border-collapse: collapse;">
//           <thead>
//             <tr>
//               <th># REPORTE INTERNO</th>
//               <th># INCIDENCIA CLIENTE</th>
//               <th>CLIENTE</th>
//               <th>CONTACTO</th>
//               <th>SUCURSAL</th>
//               <th>FECHA</th>
//               <th>TÉCNICO</th>
//               <th>FALLA</th>
//               <th>ESTATUS</th>  
//               <th>TRABAJO REALIZADO</th>
//             </tr>
//           </thead>
//           <tbody>
//     `;

//     incidencias.forEach(incidencia => {
//       html += `
//         <tr>
//           <td>${incidencia.numero_incidente || 'N/A'}</td>
//           <td>${incidencia.numero || 'N/A'}</td>
//           <td>${incidencia.cliente || 'N/A'}</td>
//           <td>${incidencia.contacto || 'N/A'}</td>
//           <td>${incidencia.sucursal || 'N/A'}</td>
//           <td>${incidencia.fecha || 'N/A'}</td>
//           <td>${incidencia.tecnico || 'N/A'}</td>
//           <td>${incidencia.falla || 'N/A'}</td>
//           <td>${incidencia.estatus || 'N/A'}</td>
//           <td>${incidencia.accion || 'N/A'}</td>
//         </tr>
//       `;
//     });

//     html += `
//           </tbody>
//         </table>
//       </div>
//     `;

//     document.getElementById('report-data').innerHTML = html;
//   }

//   // Cargar las incidencias al cargar la página
//   cargarIncidencias();
// });


document.addEventListener("DOMContentLoaded", function() {
  // Función para cargar las incidencias desde el servidor con los filtros
  function cargarIncidencias() {
    // Obtener los valores de los filtros
    const cliente = document.getElementById('cliente').value;
    const fechaInicio = document.getElementById('fecha-inicio').value;
    const fechaFin = document.getElementById('fecha-fin').value;
    const estatus = document.getElementById('estatus').value;
    const sucursal = document.getElementById('sucursal').value;

    // Construir la URL con los parámetros de búsqueda
    let url = `../backend/buscar_reportes.php?cliente=${cliente}&fecha_inicio=${fechaInicio}&fecha_fin=${fechaFin}&estatus=${estatus}&sucursal=${sucursal}`;

    fetch(url)
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

  // Escuchar el evento de envío del formulario y cargar incidencias
  document.getElementById('report-form').addEventListener('submit', function(e) {
    e.preventDefault(); // Evitar el comportamiento por defecto
    cargarIncidencias(); // Cargar incidencias con los filtros seleccionados
  });



   // Agregar funcionalidad al botón de limpiar filtros
   document.getElementById('limpiar-filtros').addEventListener('click', function() {
    // Limpiar todos los filtros
    document.getElementById('report-form').reset();

    // Recargar la página para limpiar los datos cargados
    window.location.reload();
  });

  
  // Cargar las incidencias al cargar la página (sin filtros)
  cargarIncidencias();
});
