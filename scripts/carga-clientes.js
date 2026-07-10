function escapeHtml(valor) {
    if (valor === null || valor === undefined) return '';
    return String(valor)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}

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
        listaClientes.innerHTML = `<tr><td colspan="6" style="text-align:center; padding: 20px; color: #7f8c8d;">No se encontraron clientes.</td></tr>`;
        return;
    }

    clientes.forEach(cliente => {
      const row = document.createElement('tr');

      // Convertimos toda la fila en un enlace gigante
      row.style.cursor = 'pointer';
      row.style.transition = 'background-color 0.2s ease';
      
      // Efecto hover (cambia de color al pasar el ratón)
      row.addEventListener('mouseenter', () => row.style.backgroundColor = '#f1f5f9');
      row.addEventListener('mouseleave', () => row.style.backgroundColor = 'transparent');
      
      // Redirección a la nueva página de perfil enviando el ID por la URL
      row.onclick = () => {
          window.location.href = `perfil-cliente.html?id=${cliente.id}`;
      };

      // Inyectamos solo las 6 columnas de datos (Eliminamos la columna de acciones)
      row.innerHTML = `
        <td style="font-weight:bold; color:#2980b9;">${escapeHtml(cliente.nombre)}</td>
        <td>${escapeHtml(cliente.rfc) || '-'}</td>
        <td>${escapeHtml(cliente.direccion) || '-'}</td>
        <td>${escapeHtml(cliente.telefono) || '-'}</td>
        <td>${escapeHtml(cliente.contactos) || '-'}</td>
        <td>${escapeHtml(cliente.email) || '-'}</td>
      `;

      listaClientes.appendChild(row);
    });

  } catch (error) {
    console.error('Error al cargar clientes:', error);
    const listaClientes = document.getElementById('lista-clientes');
    if (listaClientes) {
        listaClientes.innerHTML = `<tr><td colspan="6" style="text-align:center; color:#e74c3c; font-weight:bold;">Error de conexión con el servidor.</td></tr>`;
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