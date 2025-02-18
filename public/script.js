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

// para obtener incidencias abiertas 

fetch('https://darkgoldenrod-duck-950402.hostingersite.com/public/server.php?incidencias_abiertas')
  .then(response => response.json())
  .then(data => console.log(data))
  .catch(error => console.error('Error:', error));


//BLOQUE PARA ENVIAR USUARIOS Y TECNICOS A BD

