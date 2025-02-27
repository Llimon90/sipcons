document.addEventListener("DOMContentLoaded", function () {
    function cargarIncidencias() {
        fetch("server.php")
            .then(response => response.json())
            .then(data => {
                const tbody = document.getElementById("tabla-body");
                tbody.innerHTML = ""; // Limpiar tabla antes de agregar nuevas filas

                if (data.error) {
                    tbody.innerHTML = `<tr><td colspan="8">${data.error}</td></tr>`;
                    return;
                }

                data.forEach(incidencia => {
                    const fila = document.createElement("tr");
                    fila.innerHTML = `
                        <td>${incidencia.numero_incidente}</td>
                        <td>${incidencia.cliente}</td>
                        <td>${incidencia.contacto}</td>
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

    // BLOQUE PARA EDITAR CAMPOS DE LAS INCIDENCIAS 
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

        // Mostrar el formulario
        document.getElementById("formulario-editar").style.display = "block";
    }

    // Manejar el envío del formulario
    document.getElementById("form-modificar").addEventListener("submit", function(event) {
        event.preventDefault();
        
        const formData = new FormData(this);
        
        // Enviar los datos actualizados al servidor
        fetch("crude.php", {
            method: "POST",
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                alert("Incidencia actualizada correctamente");
                cargarIncidencias(); // Recargar las incidencias para mostrar los cambios
                document.getElementById("formulario-editar").style.display = "none"; // Ocultar el formulario
            } else {
                alert("Error al actualizar incidencia: " + data.error);
            }
        })
        .catch(error => console.error("Error al actualizar incidencia:", error));
    });

    // Cargar las incidencias al cargar la página
    cargarIncidencias(); 

    // Recargar datos al hacer clic en el icono o botón
    document.getElementById("reload-data").addEventListener("click", function () {
        cargarIncidencias();
    });
});
