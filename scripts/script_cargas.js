

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
  


document.addEventListener("DOMContentLoaded", function () {
    function cargarIncidencias() {
        fetch("../backend/server.php")
            .then(response => response.json())
            .then(data => {
                const tbody = document.getElementById("tabla-body");
                tbody.innerHTML = ""; // Limpiar la tabla antes de cargar datos nuevos

                if (data.error) {
                    tbody.innerHTML = `<tr><td colspan="10">${data.error}</td></tr>`;
                    return;
                }

                if (data.message) {
                    tbody.innerHTML = `<tr><td colspan="10">${data.message}</td></tr>`;
                    return;
                }

                // Convertir la BD en un arreglo de objetos
                let incidenciasArray = data.map(incidencia => ({
                    id: incidencia.id,
                    numero_incidente: incidencia.numero_incidente,
                    numero: incidencia.numero,
                    
                    cliente: incidencia.cliente,
                    sucursal: incidencia.sucursal,
                    falla: incidencia.falla,
                    fecha: incidencia.fecha,
                    estatus: incidencia.estatus
                }));

                console.log("Arreglo de incidencias:", incidenciasArray);

                // Mostrar el arreglo en la tabla
                incidenciasArray.forEach(incidencia => {
                    const fila = document.createElement("tr");

                    // Crear la celda con el hipervínculo
                    const celdaNumeroIncidente = document.createElement("td");
                    const enlace = document.createElement("a");
                    enlace.href = `detalle.html?id=${incidencia.id}`;
                    enlace.textContent = incidencia.numero_incidente;
                    enlace.style.color = "blue";
                    enlace.style.textDecoration = "underline";

                    celdaNumeroIncidente.appendChild(enlace);

                    // Resto de las celdas
                    fila.innerHTML = `
                        
                        
                        
                        <td></td> <!-- Esta celda se llenará con el hipervínculo -->
                        <td>${incidencia.numero}</td>
                        <td>${incidencia.cliente}</td>
                        <td>${incidencia.sucursal}</td>
                        <td>${incidencia.falla}</td>
                        <td>${incidencia.fecha}</td>
                        <td>${incidencia.estatus}</td>
                    `;

                    // Insertar la celda con el hipervínculo en la posición correcta
                    fila.children[0].replaceWith(celdaNumeroIncidente);

                    tbody.appendChild(fila);
                });
            })
            .catch(error => console.error("Error al cargar incidencias:", error));
    }

    cargarIncidencias(); // Cargar los datos cuando la página se carga
});


