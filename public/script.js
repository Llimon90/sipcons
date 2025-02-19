document.getElementById('new-incidencia-form').addEventListener('submit', function(event) {
    event.preventDefault(); // Prevenir envío por defecto
    
    const nuevaIncidencia = {
        numero: document.getElementById('numero').value,
        cliente: document.getElementById('cliente').value,
        contacto: document.getElementById('contacto').value,
        sucursal: document.getElementById('sucursal').value,
        falla: document.getElementById('falla').value,
        fecha: document.getElementById('fecha').value,
        tecnico: document.getElementById('tecnico').value,
        status: document.getElementById('estatus').value,
    };

    fetch('server.php', { // Ahora llama a server.php
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(nuevaIncidencia),
    })
    .then(response => response.json())
    .then(data => {
        console.log('Respuesta del servidor:', data);
        alert(data.message || data.error);
    })
    .catch(error => {
        console.error('Error al enviar los datos:', error);
        alert('Hubo un error al enviar los datos');
    });
});


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



        