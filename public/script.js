document.getElementById('new-incidencia-form').addEventListener('submit', function(event) {
    event.preventDefault(); // Prevenir el envío por defecto del formulario
    console.log('Formulario enviado');
  
    const nuevaIncidencia = {
      numero: document.getElementById('numero').value,
      cliente: document.getElementById('cliente').value,
      contacto: document.getElementById('contacto').value,
      sucursal: document.getElementById('sucursal').value,
      falla: document.getElementById('falla').value,
      fecha: document.getElementById('fecha').value,
      tecnico: document.getElementById('tecnico').value,
      status: document.getElementById('status').value
    };
  
    fetch('http://localhost:3000/nueva-incidencia', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json'
      },
      body: JSON.stringify(nuevaIncidencia)
    })
    .then(response => response.json())
    .then(data => {
      console.log('Respuesta del servidor:', data);
    })
    .catch(error => {
      console.error('Error al enviar los datos:', error);
    });
  });
  
  //INSERTA CONSECUTIVO DE INCIDENCIA EN FORMULARIO
  