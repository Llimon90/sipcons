document.addEventListener("DOMContentLoaded", function () {
    // Función para cargar y mostrar las incidencias
    function cargarIncidencias() {
        fetch("server.php")
            .then(response => {
                if (!response.ok) {
                    throw new Error("Error en la solicitud: " + response.statusText);
                }
                return response.json();
            })
            .then(data => {
                const tbody = document.getElementById("tabla-body");
                tbody.innerHTML = ""; // Limpiar tabla antes de agregar nuevas filas

                // Manejo de errores o mensajes del servidor
                if (data.error) {
                    tbody.innerHTML = `<tr><td colspan="9">${data.error}</td></tr>`;
                    return;
                }

                if (data.message) {
                    tbody.innerHTML = `<tr><td colspan="9">${data.message}</td></tr>`;
                    return;
                }

                // Si hay datos, crear las filas de la tabla
                if (Array.isArray(data) && data.length > 0) {
                    data.forEach(incidencia => {
                        const fila = document.createElement("tr");
                        fila.innerHTML = `
                            <td>${incidencia.numero}</td>
                            <td>${incidencia.numero_incidente}</td>
                            <td>${incidencia.cliente}</td>     
                            <td>${incidencia.sucursal}</td>
                            <td>${incidencia.fecha}</td>
                            <td>${incidencia.estatus}</td>
                        `;
                        tbody.appendChild(fila);
                    });
                } else {
                    tbody.innerHTML = `<tr><td colspan="9">No hay incidencias registradas.</td></tr>`;
                }
            })
            .catch(error => {
                console.error("Error al cargar incidencias:", error);
                const tbody = document.getElementById("tabla-body");
                tbody.innerHTML = `<tr><td colspan="9">Error al cargar las incidencias. Inténtalo de nuevo más tarde.</td></tr>`;
            });
    }

    // Llamar a la función al cargar la página
    cargarIncidencias();
});