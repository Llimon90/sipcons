document.addEventListener("DOMContentLoaded", function () {
    // Seleccionar la tabla donde se listan las incidencias
    const tablaBody = document.getElementById("tabla-body");

    // Cargar incidencias existentes y permitir edición de estatus
    function cargarIncidencias() {
        fetch("../backend/server.php")
            .then(response => response.json())
            .then(incidencias => {
                tablaBody.innerHTML = ""; // Limpiar tabla antes de llenar

                incidencias.forEach(incidencia => {
                    const fila = document.createElement("tr");

                    fila.innerHTML = `
                        <td>${incidencia.numero}</td>
                        <td>${incidencia.numero_incidente}</td>
                        <td>${incidencia.cliente}</td>
                        <td>${incidencia.sucursal}</td>
                        <td>${incidencia.falla}</td>
                        <td>${incidencia.fecha}</td>
                        <td>
                            <select class="estatus-select" data-id="${incidencia.id}">
                                <option value="Abierta" ${incidencia.estatus === "Abierta" ? "selected" : ""}>Abierta</option>
                                <option value="Pendiente" ${incidencia.estatus === "Pendiente" ? "selected" : ""}>Pendiente</option>
                                <option value="En seguimiento" ${incidencia.estatus === "En seguimiento" ? "selected" : ""}>En Seguimiento</option>
                                <option value="Facturada" ${incidencia.estatus === "Facturada" ? "selected" : ""}>Facturada</option>
                            </select>
                        </td>
                    `;

                    tablaBody.appendChild(fila);
                });

                // Agregar evento para actualizar estatus en la BD
                document.querySelectorAll(".estatus-select").forEach(select => {
                    select.addEventListener("change", function () {
                        const id = this.dataset.id;
                        const nuevoEstatus = this.value;

                        fetch("../backend/actualiza.php", {
                            method: "POST",
                            headers: { "Content-Type": "application/json" },
                            body: JSON.stringify({ id, estatus: nuevoEstatus }),
                        })
                        .then(response => response.json())
                        .then(data => {
                            alert(data.message || data.error);
                        })
                        .catch(error => {
                            console.error("Error al actualizar estatus:", error);
                            alert("Hubo un error al actualizar el estatus");
                        });
                    });
                });
            })
            .catch(error => console.error("Error al cargar incidencias:", error));
    }

    // Cargar incidencias al cargar la página
    cargarIncidencias();
});
