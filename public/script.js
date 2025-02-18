document.getElementById("new-incidencia-form").addEventListener("submit", function (event) {
    event.preventDefault(); // Evita el envío predeterminado

    let datosFormulario = {
        numero: document.getElementById("numero").value.trim(),
        cliente: document.getElementById("cliente").value.trim(),
        contacto: document.getElementById("contacto").value.trim(),
        sucursal: document.getElementById("sucursal").value.trim(),
        fecha: document.getElementById("fecha").value,
        tecnico: document.getElementById("tecnico").value.trim(),
        status: document.getElementById("status").value,
        falla: document.getElementById("falla").value.trim()
    };

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
            
            // Vaciar inputs después de 300ms (para evitar que se borren antes de ver la respuesta)
            setTimeout(() => {
                document.getElementById("new-incidencia-form").reset();
            }, 300);
        }
    })
    .catch(error => console.error("Error al enviar los datos:", error));
});


// OBTENER INCIDENCIAS ABIERTAS AL CARGAR LA PAGINA

window.addEventListener("load", function() {
    fetch("public/server.php", {
        method: "GET"
    })
    .then(response => response.json())
    .then(data => {
        if (Array.isArray(data) && data.length > 0) {
            const tableBody = document.querySelector("#incidencias-table tbody");
            tableBody.innerHTML = ''; // Limpiar tabla antes de agregar nuevas filas

            // Agregar las incidencias al cuerpo de la tabla
            data.forEach(incidencia => {
                const row = document.createElement("tr");

                // Crear celdas con los datos de cada incidencia
                row.innerHTML = `
                    <td>${incidencia.numero}</td>
                    <td>${incidencia.numero_incidente}</td>
                    <td>${incidencia.fecha}</td>
                    <td>${incidencia.cliente}</td>
                `;
                tableBody.appendChild(row);
            });
        } else {
            console.log("No hay incidencias abiertas");
        }
    })
    .catch(error => console.error("Error al obtener las incidencias:", error));
});
