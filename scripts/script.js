const form = document.getElementById('new-incidencia-form');
const btnRegistrar = document.getElementById('btn-registrar');
const btnActualizar = document.getElementById('btn-actualizar');
const btnEliminar = document.getElementById('btn-eliminar');

// Establece fecha actual al cargar
document.getElementById('fecha').value = new Date().toISOString().split('T')[0];

form.addEventListener('submit', function(event) {
  event.preventDefault();

  // Prepara objeto con los datos actuales del formulario
  const nuevaIncidencia = {
    id: document.getElementById('incidencia-id').value || null,
    numero: document.getElementById('numero').value,
    cliente: document.getElementById('cliente').value,
    contacto: document.getElementById('contacto').value,
    sucursal: document.getElementById('sucursal').value,
    equipo: document.getElementById('equipo').value, // Nuevo campo
    falla: document.getElementById('falla').value,
    fecha: document.getElementById('fecha').value,
    tecnico: document.getElementById('tecnico').value,
    status: document.getElementById('estatus').value,
    notas: document.getElementById('notas').value,
  };

  fetch('../backend/server.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(nuevaIncidencia),
  })
  .then(response => response.json())
  .then(data => {
    alert(data.message || data.error);
    // Recarga completa al cerrar el alert
    window.location.reload();
  })
  .catch(error => {
    console.error('Error al enviar los datos:', error);
    alert('Hubo un error al enviar los datos');
  });
});