//ENVIA INCIDENCIAS A BD


document.getElementById('new-cliente-form').addEventListener('submit', function(event) {
    event.preventDefault(); // Prevenir envío por defecto
    
    const nuevaIncidencia = {
        numero: document.getElementById('nombre').value,
        cliente: document.getElementById('rfc').value,
        contacto: document.getElementById('direccion').value,
        sucursal: document.getElementById('telefono').value,
        falla: document.getElementById('contactos').value,
        fecha: document.getElementById('email').value,
        
    };

    fetch('server-clientes.php', { 
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(nuevoCliente),
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





