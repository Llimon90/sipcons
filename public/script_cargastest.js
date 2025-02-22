document.addEventListener("DOMContentLoaded", function () {
    function cargarIncidencias() {
        fetch("/server_cargas.php")
            .then(response => response.json())
            .then(data => {
                const tbody = document.getElementById("tabla-body");
                tbody.innerHTML = ""; // Limpiar tabla antes de agregar nuevas filas

                if (data.error) {
                    tbody.innerHTML = `<tr><td colspan="8">${data.error}</td></tr>`;
                    return;
                }

                if (data.message) {
                    tbody.innerHTML = `<tr><td colspan="8">${data.message}</td></tr>`;
                    return;
                }

                data.forEach(incidencia => {
                    const fila = document.createElement("tr");
                    fila.innerHTML = `
                        <td>${incidencia.numero}</td>
                
                        <td>${incidencia.cliente}</td>
                        <td>${incidencia.contacto}</td>
                        <td>${incidencia.sucursal}</td>
                        <td>${incidencia.falla}</td>
                        <td>${incidencia.fecha}</td>
                        <td>${incidencia.tecnico}</td>
                        <td>${incidencia.estatus}</td>
                        
                    `;
                    tbody.appendChild(fila);
                });
            })
            .catch(error => console.error("Error al cargar incidencias:", error));
    }

    cargarIncidencias(); // Llamar a la función al cargar la página
});
