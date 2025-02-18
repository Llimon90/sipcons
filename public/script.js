document.getElementById("new-incidencia-form").addEventListener("submit", function (event) {
    event.preventDefault(); // Evita el envío predeterminado

    let datosFormulario = {
        numero: document.getElementById("numero").value.trim(),
        cliente: document.getElementById("cliente").value.trim(),
        contacto: document.getElementById("contacto").value.trim(),
        sucursal: document.getElementById("sucursal").value.trim(),
        fecha: document.getElementById("fecha").value,
        tecnico: document.getElementById("tecnico").value.trim(),
        status: document.getElementById("estatus").value, // Este es el campo
        falla: document.getElementById("falla").value.trim()
    };
    

    console.log(datosFormulario); // Verifica el contenido de los datos enviados

    fetch("public/server.php", {
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
            setTimeout(() => {
                document.getElementById("new-incidencia-form").reset();
            }, 300);
        }
    })
    .catch(error => {
        console.error("Error en la solicitud:", error);
    });
});
