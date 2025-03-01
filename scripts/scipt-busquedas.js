document.addEventListener('DOMContentLoaded', function () {
    const form = document.getElementById('report-form');
    const reportResults = document.getElementById('report-data');
    
    form.addEventListener('submit', function (event) {
      event.preventDefault(); // Evita que el formulario se envíe de manera tradicional
      
      const cliente = document.getElementById('cliente').value;
      const fechaInicio = document.getElementById('fecha-inicio').value;
      const fechaFin = document.getElementById('fecha-fin').value;
  
      // Validación básica
      if (!cliente || !fechaInicio || !fechaFin) {
        alert('Por favor, complete todos los campos.');
        return;
      }
  
      // Realizar la solicitud al servidor para obtener los reportes
      fetch(`buscar_reportes.php?cliente=${cliente}&fecha-inicio=${fechaInicio}&fecha-fin=${fechaFin}`)
        .then(response => response.json())
        .then(data => {
          // Mostrar los resultados
          mostrarResultados(data);
        })
        .catch(error => {
          console.error("Error al obtener los reportes:", error);
          alert("Hubo un problema al obtener los reportes.");
        });
    });
  
    function mostrarResultados(reportData) {
      // Limpiar los resultados anteriores
      reportResults.innerHTML = '';
  
      // Verificar si se encontraron reportes
      if (reportData.error) {
        reportResults.innerHTML = `<p>${reportData.error}</p>`;
      } else if (reportData.mensaje) {
        reportResults.innerHTML = `<p>${reportData.mensaje}</p>`;
      } else {
        // Crear la tabla
        const table = document.createElement('table');
        table.classList.add('report-table');
        
        // Crear la fila de cabecera
        const headerRow = document.createElement('tr');
        headerRow.innerHTML = '<th>ID</th><th>Cliente</th><th>Fecha</th><th>Descripción</th>';
        table.appendChild(headerRow);
  
        // Agregar las filas de los reportes
        reportData.forEach((reporte) => {
          const row = document.createElement('tr');
          row.innerHTML = `
            <td>${reporte.id}</td>
            <td>${reporte.cliente}</td>
            <td>${reporte.fecha}</td>
            <td>${reporte.descripcion}</td>
          `;
          table.appendChild(row);
        });
  
        // Insertar la tabla en el contenedor de resultados
        reportResults.appendChild(table);
      }
    }
  });
  