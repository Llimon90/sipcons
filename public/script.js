document.getElementById('new-incidencia-form').addEventListener('submit', function(event) {
  event.preventDefault(); // Prevenir el envío por defecto del formulario
  console.log('Formulario enviado');
  
  const nuevaIncidencia = {
    cliente: document.getElementById('cliente').value,
    contacto: document.getElementById('contacto').value,
    sucursal: document.getElementById('sucursal').value,
    falla: document.getElementById('falla').value,
    fecha: document.getElementById('fecha').value,
    tecnico: document.getElementById('tecnico').value,
    status: document.getElementById('status').value
  };

  fetch('https://darkgoldenrod-duck-950402.hostingersite.com/api.php', {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json'
    },
    body: JSON.stringify(nuevaIncidencia)
  })
  .then(response => response.json())
  .then(data => {
    console.log('Respuesta del servidor:', data);
    alert(data.message); // Mostrar un mensaje de éxito o error
  })
  .catch(error => {
    console.error('Error al enviar los datos:', error);
    alert('Hubo un error al enviar los datos');
  });
});

//INSERTA CONSECUTIVO DE INCIDENCIA EN FORMULARIO
