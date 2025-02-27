document.getElementById("actualizar").addEventListener("click", function() {
    const id = document.getElementById("id").value; // Obtener el ID
    const formData = new FormData(document.getElementById("new-incidencia-form"));
    
    // Enviar los datos al servidor para actualizar
    fetch("crude.php", {
        method: "POST",
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert("Incidencia actualizada correctamente");
            cargarIncidencias(); // Recargar las incidencias para mostrar los cambios
        } else {
            alert("Error al actualizar incidencia: " + data.error);
        }
    })
    .catch(error => console.error("Error al actualizar incidencia:", error));
});