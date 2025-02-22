//ENVIA INCIDENCIAS A BD


document.getElementById('new-cliente-form').addEventListener('submit', function(event) {
    event.preventDefault(); // Prevenir envío por defecto
    
    const nuevoCliente = {
        nombre: document.getElementById('nombre').value,
        rfc: document.getElementById('rfc').value,
        direccion: document.getElementById('direccion').value,
        telefono: document.getElementById('telefono').value,
        contactos: document.getElementById('contactos').value,
        email: document.getElementById('email').value,
        
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





