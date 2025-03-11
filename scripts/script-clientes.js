document.getElementById('new-incidencia-form').addEventListener('submit', async (event) => {
    event.preventDefault();
  
    const formData = new FormData(event.target);
  
    try {
      const response = await fetch('../backend/guardar-cliente.php', {
        method: 'POST',
        body: formData
      });
  
      const result = await response.json();
  
      if (result.success) {
        alert('Cliente guardado exitosamente');
        event.target.reset(); // Limpia el formulario después de guardar
      } else {
        alert(`Error: ${result.message}`);
      }
    } catch (error) {
      console.error('Error al enviar datos:', error);
      alert('Hubo un error al guardar el cliente');
    }
  });
  