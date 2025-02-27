document.addEventListener("DOMContentLoaded", function () {
    function cargarIncidencias() {
        fetch("server.php")
            .then(response => response.json())
            .then(data => {
                const tbody = document.getElementById("tabla-body");
                tbody.innerHTML = ""; 

                if (data.error) {
                    tbody.innerHTML = `<tr><td colspan="8">${data.error}</td></tr>`;
                    return;
                }

                data.forEach(incidencia => {
                    const fila = document.createElement("tr");
                    fila.innerHTML = `
                        <td>${incidencia.numero}</td>
                        <td>${incidencia.numero_incidente}</td>
                        <td>${incidencia.cliente}</td>
                        <td>${incidencia.sucursal}</td>
                        <td>${incidencia.falla}</td>
                        <td>${incidencia.fecha}</td>
                        <td>${incidencia.tecnico}</td>
                        <td>${incidencia.estatus}</td>
                    `;

                    // Añadir evento de clic a la fila
                    fila.addEventListener("click", () => {
                        mostrarDetalles(incidencia);
                    });

                    tbody.appendChild(fila);
                });
            })
            .catch(error => console.error("Error al cargar incidencias:", error));
    }

    // Función para mostrar detalles de la incidencia seleccionada
    function mostrarDetalles(incidencia) {
        // Cargar los datos en el formulario
        document.getElementById("numero").value = incidencia.numero;
        document.getElementById("cliente").value = incidencia.cliente;
        document.getElementById("contacto").value = incidencia.contacto;
        document.getElementById("sucursal").value = incidencia.sucursal;
        document.getElementById("falla").value = incidencia.falla;
        document.getElementById("fecha").value = incidencia.fecha;
        document.getElementById("tecnico").value = incidencia.tecnico;
        document.getElementById("estatus").value = incidencia.estatus;

        // Almacenar el ID de la incidencia seleccionada
        document.getElementById("new-incidencia-form").dataset.id = incidencia.id;

        // Mostrar el formulario
        document.getElementById("new-incidencia-form").style.display = "block";
    }

    // Manejar el envío del formulario
    document.getElementById("new-incidencia-form").addEventListener("submit", function(event) {
        event.preventDefault();
        
        const formData = new FormData(this);
        const id = this.dataset.id; // Obtener el ID de la incidencia seleccionada

        // Añadir el ID a los datos del formulario
        const dataToSend = {
            id: id,
            numero: formData.get("numero"),
            cliente: formData.get("cliente"),
            contacto: formData.get("contacto"),
            sucursal: formData.get("sucursal"),
            fecha: formData.get("fecha"),
            tecnico: formData.get("tecnico"),
            estatus: formData.get("estatus"),
        };

        // Enviar los datos actualizados al servidor
        fetch("crude.php", {
            method: "POST",
            body: JSON.stringify(dataToSend),
            headers: {
                "Content-Type": "application/json"
            }
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                alert("Incidencia actualizada correctamente");
                cargarIncidencias(); // Recargar las incidencias para mostrar los cambios
                this.style.display = "none"; // Ocultar el formulario
            } else {
                alert("Error al actualizar incidencia: " + data.error);
            }
        })
        .catch(error => console.error("Error al actualizar incidencia:", error));
    });

    cargarIncidencias(); // Llamar a la función al cargar la página
});
