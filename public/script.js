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





        // Función para cargar los datos de la base de datos
        function cargarDatos() {
            fetch('https://darkgoldenrod-duck-950402.hostingersite.com/public/server.php')
                .then(response => response.json())
                .then(data => {
                    if (data.error) {
                        alert(data.error);
                    } else {
                        const tabla = document.getElementById('tabla-datos').getElementsByTagName('tbody')[0];
                        // Limpiar la tabla antes de agregar los nuevos datos
                        tabla.innerHTML = '';

                        // Recorrer los datos y agregarlos a la tabla
                        data.forEach(fila => {
                            const tr = document.createElement('tr');
                            tr.innerHTML = `
                                <td>${fila.numero}</td>
                                <td>${fila.cliente}</td>
                                <td>${fila.sucursal}</td>
                                <td>${fila.fecha}</td>
                            `;
                            tabla.appendChild(tr);
                        });
                    }
                })
                .catch(error => console.error('Error al cargar los datos:', error));
        }

        // Cargar los datos cuando la página esté lista
        window.onload = cargarDatos;