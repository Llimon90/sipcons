document.addEventListener("DOMContentLoaded", function () {
    function cargarIncidencias() {
        fetch("servertest.php")
            .then(response => response.json())
            .then(data => {
                const tbody = document.getElementById("tabla-body");
                // tbody.innerHTML = ""; // Limpiar tabla antes de agregar nuevas filas

                if (data.error) {
                    tbody.innerHTML = `<tr><td colspan="8">${data.error}</td></tr>`;
                    return;
                }

                if (data.message) {
                    tbody.innerHTML = `<tr><td colspan="9">${data.message}</td></tr>`;
                    return;
                }

                data.forEach(incidencia=> {
                    
                    console.log(incidencias);


                    const fila = document.createElement("tr");
                    fila.innerHTML = `
                        <td>${incidencias.id ?? ''}</td>
                        <td>${incidencias.numero}</td>
                        <td>${incidencias.cliente}</td>
                        <td>${incidencias.contacto}</td>
                        <td>${incidencias.sucursal}</td>
                        <td>${incidencias.falla}</td>
                        <td>${incidencias.fecha}</td>
                        <td>${incidencias.tecnico}</td>
                        <td>${incidencias.estatus}</td>
                        <td>${incidencias.numero_incidente ?? ''}</td>
                        <td>${incidencias.copia_numero_incidente }</td>


                        
                    `;

                    console.log(incidencias);

                    tbody.appendChild(fila);
                });
            })
            .catch(error => console.error("Error al cargar incidencias:", error));
    }

    cargarIncidencias(); // Llamar a la función al cargar la página
});
