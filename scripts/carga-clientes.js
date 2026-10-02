// Función para obtener y mostrar clientes
async function cargarClientes(busqueda = '') {
  try {
    // Usamos el archivo buscar-clientes.php que me compartiste antes
    const response = await fetch(`../backend/buscar-clientes.php?q=${encodeURIComponent(busqueda)}`);
    const clientes = await response.json();

    const listaClientes = document.getElementById('lista-clientes');
    if (!listaClientes) return;
    
    listaClientes.innerHTML = ''; // Limpia la lista antes de mostrar nuevos datos

    // Si no hay resultados
    if (!clientes || clientes.length === 0) {
        listaClientes.innerHTML = `<tr><td colspan="6">No se encontraron clientes.</td></tr>`;
        return;
    }

    clientes.forEach(cliente => {
      const row = document.createElement('tr');

      // Toda la fila abre el perfil (cursor y hover vienen de tr.fila-clic en styles.css)
      row.className = 'fila-clic';
      
      // Redirección a la nueva página de perfil enviando el ID por la URL
      row.onclick = () => {
          window.location.href = `perfil-cliente.html?id=${cliente.id}`;
      };

      // Inyectamos solo las 6 columnas de datos (Eliminamos la columna de acciones)
      row.innerHTML = `
        <td><a href="perfil-cliente.html?id=${cliente.id}">${cliente.nombre}</a></td>
        <td>${cliente.rfc || '-'}</td>
        <td>${cliente.direccion || '-'}</td>
        <td>${cliente.telefono || '-'}</td>
        <td>${cliente.contactos || '-'}</td>
        <td>${cliente.email || '-'}</td>
      `;

      listaClientes.appendChild(row);
    });

  } catch (error) {
    console.error('Error al cargar clientes:', error);
    const listaClientes = document.getElementById('lista-clientes');
    if (listaClientes) {
        listaClientes.innerHTML = `<tr><td colspan="6" class="celda-error">Error de conexión con el servidor.</td></tr>`;
    }
  }
}

// Cargar clientes automáticamente al inicializar la página
document.addEventListener('DOMContentLoaded', () => {
  cargarClientes();

  // Agregar evento al campo de búsqueda conservando tu lógica de retraso (debounce)
  const campoBusqueda = document.getElementById('campo-busqueda');
  if (campoBusqueda) {
      let timeout = null;
      campoBusqueda.addEventListener('input', () => {
        clearTimeout(timeout);
        timeout = setTimeout(() => {
          const query = campoBusqueda.value.trim();
          cargarClientes(query);
        }, 300); // Retraso de 300 milisegundos
      });
  }
});