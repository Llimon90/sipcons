document.getElementById('new-incidencia-form').addEventListener('submit', function(event) {
    event.preventDefault(); // Prevenir envío por defecto

    // Obtener los valores del formulario
    const numero = document.getElementById('numero').value.trim();
    const cliente = document.getElementById('cliente').value.trim();
    const contacto = document.getElementById('contacto').value.trim();
    const sucursal = document.getElementById('sucursal').value.trim();
    const falla = document.getElementById('falla').value.trim();
    const fecha = document.getElementById('fecha').value.trim();
    const tecnico = document.getElementById('tecnico').value;
    const estatus = document.getElementById('estatus').value;

    // Validar que todos los campos obligatorios estén llenos
    if (!numero || !cliente || !contacto || !sucursal || !falla || !fecha || !tecnico || !estatus) {
        alert('Por favor, completa todos los campos antes de enviar.');
        return;
    }

    const nuevaIncidencia = { numero, cliente, contacto, sucursal, falla, fecha, tecnico, estatus };

    fetch('server.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(nuevaIncidencia),
    })
    .then(response => {
        if (!response.ok) {
            throw new Error(`Error HTTP: ${response.status}`);
        }
        return response.json();
    })
    .then(data => {
        console.log('Respuesta del servidor:', data);
        alert(data.message || 'Incidencia registrada correctamente');

        // Opcional: Limpiar el formulario después de un envío exitoso
        document.getElementById('new-incidencia-form').reset();
    })
    .catch(error => {
        console.error('Error al enviar los datos:', error);
        alert('Hubo un error al enviar los datos. Intenta nuevamente.');
    });
});
