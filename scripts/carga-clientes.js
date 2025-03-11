// Función para obtener y mostrar clientes
async function cargarClientes() {
    try {
      const response = await fetch('../backend/obtener-clientes.php');
      const clientes = await response.json();
  
      const listaClientes = document.getElementById('lista-clientes');
      listaClientes.innerHTML = ''; // Limpia la lista antes de mostrar nuevos datos
  
      clientes.forEach(cliente => {
        const row = document.createElement('tr');
  
        row.innerHTML = `
          <td>${cliente.nombre}</td>
          <td>${cliente.rfc}</td>
          <td>${cliente.direccion}</td>
          <td>${cliente.telefono}</td>
          <td>${cliente.contactos}</td>
          <td>${cliente.email}</td>
        `;
  
        listaClientes.appendChild(row);
      });
    } catch (error) {
      console.error('Error al cargar clientes:', error);
      alert('Error al cargar clientes');
    }
  }
  
  // Cargar clientes automáticamente al cargar la página
  document.addEventListener('DOMContentLoaded', cargarClientes);
  