document.addEventListener('DOMContentLoaded', function() {
    const form = document.getElementById('new-cliente-form');
    if (form) { // Verifica si el formulario existe
        form.addEventListener('submit', function(event) {
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
                method: 'POST', // Asegúrate de especificar el método POST
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(nuevoCliente), // Envía los datos como JSON
            })
            .then(response => response.json())
            .then(data => {
                console.log('Respuesta del servidor:', data);
                alert(data.message || data.error);
                if (data.message) {
                    form.reset(); // Limpiar el formulario
                }
            })
            .catch(error => {
                console.error('Error al enviar los datos:', error);
                alert('Hubo un error al enviar los datos');
            });
        });
    } else {
        console.error("El formulario no se encontró");
    }
});
