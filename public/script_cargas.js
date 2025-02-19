//CARGAR INCIDENCIAS ACTIVAS

document.addEventListener("DOMContentLoaded", function () {
    // Función para cargar las incidencias desde la base de datos
    function cargarIncidencias() {
        fetch("server.php")

            .then(response => response.json())
            .then(data => {
                const listaIncidencias = document.getElementById("lista-incidencias");
                listaIncidencias.innerHTML = ""; // Limpiar lista antes de agregar nuevas incidencias

                if (data.error) {
                    listaIncidencias.innerHTML = `<p>${data.error}</p>`;
                    return;
                }

                if (data.message) {
                    listaIncidencias.innerHTML = `<p>${data.message}</p>`;
                    return;
                }

                data.forEach(incidencia => {
                    const incidenciaItem = document.createElement("div");
                    incidenciaItem.classList.add("incidencia-item");
                    incidenciaItem.innerHTML = `
                        <p><strong># Incidencia:</strong> ${incidencia.numero}</p>
                        <p><strong>Cliente:</strong> ${incidencia.cliente}</p>
                        <p><strong>Reporta:</strong> ${incidencia.contacto}</p>
                        <p><strong>Sucursal:</strong> ${incidencia.sucursal}</p>
                        <p><strong>Falla:</strong> ${incidencia.falla}</p>
                        <p><strong>Fecha:</strong> ${incidencia.fecha}</p>
                        <p><strong>Técnico:</strong> ${incidencia.tecnico}</p>
                        <p><strong>Estatus:</strong> ${incidencia.estatus}</p>
                        
                        <hr>
                    `;
                    listaIncidencias.appendChild(incidenciaItem);
                });
            })
            .catch(error => console.error("Error al cargar incidencias:", error));
    }

    // Llamar a la función cuando la página cargue
    cargarIncidencias();
});
