document.getElementById('new-cliente-form').addEventListener('submit', function(event) {
    event.preventDefault(); // Prevenir envío por defecto
    
    const nuevoCliente = { // Cambiado a 'nuevoCliente'
        nombre: document.getElementById('nombre').value, // Cambiado a 'nombre'
        rfc: document.getElementById('rfc').value, // Cambiado a 'rfc'
        direccion: document.getElementById('direccion').value, // Cambiado a 'direccion'
        telefono: document.getElementById('telefono').value, // Cambiado a 'telefono'
        contactos: document.getElementById('contacto').value, // Cambiado a 'contactos'
        email: document.getElementById('email').value, // Cambiado a 'email'
    };

    fetch('server-clientes.php', { 
        method: 'POST', // Asegúrate de especificar el método
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
