document.getElementById("formIncidencia").addEventListener("submit", function (event) {
    event.preventDefault(); // Evita recargar la página

    let datosFormulario = {
        numero: document.getElementById("numero").value,
        cliente: document.getElementById("cliente").value,
        contacto: document.getElementById("contacto").value,
        sucursal: document.getElementById("sucursal").value,
        fecha: document.getElementById("fecha").value,
        tecnico: document.getElementById("tecnico").value,
        status: document.getElementById("status").value,
        falla: document.getElementById("falla").value
    };

    fetch("public/servidor.php", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify(datosFormulario)
    })
    .then(response => response.json())
    .then(data => {
        if (data.error) {
            console.error("Error:", data.error);
        } else {
            console.log("Incidencia registrada:", data);
            document.getElementById("formIncidencia").reset(); // Vacía los inputs
        }
    })
    .catch(error => console.error("Error al enviar los datos:", error));
});
