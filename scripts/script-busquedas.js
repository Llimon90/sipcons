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
  // Cargar incidencias al cargar la página sin filtros
  cargarIncidencias(true);

  // Agregar evento al formulario para aplicar filtros
  document.getElementById('report-form').addEventListener('submit', function(e) {
    e.preventDefault(); // Evitar que el formulario se recargue
    cargarIncidencias(false); // Llamar con filtros
  });

  // Función para cargar incidencias con o sin filtros
  function cargarIncidencias(sinFiltros = false) {
    let url = "../backend/buscar_reportes.php";

    if (!sinFiltros) {
      // Obtener valores de los filtros y asegurarse de que no sean undefined
      const cliente = document.getElementById('cliente')?.value || "";
      const fechaInicio = document.getElementById('fecha-inicio')?.value || "";
      const fechaFin = document.getElementById('fecha-fin')?.value || "";
      const estatus = document.getElementById('estatus')?.value || "";
      const sucursal = document.getElementById('sucursal')?.value || "";

      // Construir la URL solo si hay filtros aplicados
      url += `?cliente=${encodeURIComponent(cliente)}&fecha_inicio=${encodeURIComponent(fechaInicio)}&fecha_fin=${encodeURIComponent(fechaFin)}&estatus=${encodeURIComponent(estatus)}&sucursal=${encodeURIComponent(sucursal)}`;
    }

    console.log("📡 Enviando petición a:", url); // Verificar URL en consola

    fetch(url)
      .then(response => {
        if (!response.ok) {
          throw new Error(`Error en la respuesta del servidor: ${response.status}`);
        }
        return response.json();
      })
      .then(data => {
        console.log("✅ Datos recibidos:", data);

        if (!data || data.length === 0) {
          document.getElementById('report-data').innerHTML = "<p>No se encontraron resultados.</p>";
          return;
        }

        mostrarIncidencias(data);
      })
      .catch(error => {
        console.error("❌ Error en la petición:", error);
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

  // Inicializar el selector de fecha
  flatpickr("#fecha", {
    clickOpens: true,
    dateFormat: "Y-m-d"
  });
});
