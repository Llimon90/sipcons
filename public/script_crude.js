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
    
    // Obtener el ID de la incidencia seleccionada
    const id = this.dataset.id;

    // Crear un objeto con los datos del formulario
    const dataToSend = {
        id: id,
        numero: document.getElementById("numero").value,
        cliente: document.getElementById("cliente").value,
        contacto: document.getElementById("contacto").value,
        sucursal: document.getElementById("sucursal").value,
        fecha: document.getElementById("fecha").value,
        tecnico: document.getElementById("tecnico").value,
        estatus: document.getElementById("estatus").value,
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

