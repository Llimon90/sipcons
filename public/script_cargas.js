document.addEventListener("DOMContentLoaded", function () {
    function cargarIncidencias() {
        fetch("server.php")
            .then(response => {
                if (!response.ok) {
                    throw new Error(`Error HTTP: ${response.status}`);
                }
                return response.json();
            })
            .then(data => {
                const tbody = document.getElementById("tabla-body");
                tbody.innerHTML = ""; // Limpiar la tabla antes de agregar nuevas filas

                if (!Array.isArray(data)) {
                    tbody.innerHTML = `<tr><td colspan="9">Error al obtener datos</td></tr>`;
                    return;
                }

                if (data.length === 0) {
                    tbody.innerHTML = `<tr><td colspan="9">No hay incidencias registradas</td></tr>`;
                    return;
                }

                data.forEach(incidencia => {
                    const fila = document.createElement("tr");
                    fila.innerHTML = `
                        <td>${incidencia?.numero || 'No disponible'}</td>
                        <td>${incidencia?.cliente || 'No disponible'}</td>
                        <td>${incidencia?.contacto || 'No disponible'}</td>
                        <td>${incidencia?.sucursal || 'No disponible'}</td>
                        <td>${incidencia?.falla || 'No disponible'}</td>
                        <td>${incidencia?.fecha || 'No disponible'}</td>
                        <td>${incidencia?.tecnico || 'No disponible'}</td>
                        <td>${incidencia?.estatus || 'No disponible'}</td>
                    `;
                    tbody.appendChild(fila);
                });
            })
            .catch(error => {
                console.error("Error al cargar incidencias:", error);
                document.getElementById("tabla-body").innerHTML = `<tr><td colspan="9">Hubo un error al cargar las incidencias</td></tr>`;
            });
    }

    cargarIncidencias(); // Llamar a la función al cargar la página
});
