// Función para obtener y mostrar clientes
async function cargarClientes(busqueda = '') {
  try {
    const response = await fetch(`../backend/obtener-clientes.php?busqueda=${encodeURIComponent(busqueda)}`);
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
        <td>
          <div class="acciones-cliente">
            <button class="btn-editar" data-id="${cliente.id}" title="Editar">
              <i class="fas fa-edit"></i>
            </button>
            <button class="btn-eliminar" data-id="${cliente.id}" title="Eliminar">
              <i class="fas fa-trash-alt"></i>
            </button>
          </div>
        </td>
      `;

      listaClientes.appendChild(row);
    });

    // Agregar eventos a los botones de edición
    document.querySelectorAll('.btn-editar').forEach(boton => {
      boton.addEventListener('click', function(e) {
        e.preventDefault();
        const id = this.getAttribute('data-id');
        cargarFormularioEdicion(id);
      });
    });

    // Agregar eventos a los botones de eliminación
    document.querySelectorAll('.btn-eliminar').forEach(boton => {
      boton.addEventListener('click', function(e) {
        e.preventDefault();
        const id = this.getAttribute('data-id');
        confirmarEliminacion(id);
      });
    });
  } catch (error) {
    console.error('Error al cargar clientes:', error);
    alert('Error al cargar clientes');
  }
}

// Función para confirmar eliminación
function confirmarEliminacion(id) {
  if (confirm('¿Estás seguro de que deseas eliminar este cliente?')) {
    eliminarCliente(id);
  }
}

// Función para eliminar cliente
async function eliminarCliente(id) {
  try {
    const response = await fetch(`../backend/eliminar-cliente.php?id=${id}`, {
      method: 'DELETE'
    });

    const resultado = await response.json();

    if (resultado.success) {
      alert('Cliente eliminado correctamente');
      cargarClientes(); // Recargar la lista de clientes
    } else {
      alert(resultado.error || 'Error al eliminar el cliente');
    }
  } catch (error) {
    console.error('Error al eliminar cliente:', error);
    alert('Error al eliminar el cliente');
  }
}

// Función para cargar el formulario de edición
async function cargarFormularioEdicion(id) {
  try {
    const response = await fetch(`../backend/detalle-cliente.php?id=${id}`);
    const cliente = await response.json();
    
    if (cliente.error) {
      alert(cliente.error);
      return;
    }

    const formulario = document.getElementById('formulario-edicion');
    formulario.innerHTML = `
      <form id="form-editar-cliente">
        <input type="hidden" id="id-cliente" value="${cliente.id}">
        <div class="form-row">
          <div>  
            <label for="nombre-editar">Nombre:</label>
            <input type="text" id="nombre-editar" value="${cliente.nombre}" required>
          </div>
          <div>
            <label for="rfc-editar">RFC:</label>
            <input type="text" id="rfc-editar" value="${cliente.rfc}">
          </div>
        </div>

        <div class="form-row">
          <div>  
            <label for="direccion-editar">Dirección:</label>
            <input type="text" id="direccion-editar" value="${cliente.direccion}">
          </div>
          <div>
            <label for="telefono-editar">Teléfono:</label>
            <input type="text" id="telefono-editar" value="${cliente.telefono}">
          </div>
        </div>

        <div class="form-row">
          <div>
            <label for="contactos-editar">Contactos:</label>
            <input type="text" id="contactos-editar" value="${cliente.contactos}">
          </div>
          <div>
            <label for="email-editar">E-mail:</label>
            <input type="text" id="email-editar" value="${cliente.email}">
          </div>
        </div>
        
        <button type="submit">Guardar Cambios</button>
      </form>
    `;

    // Mostrar el modal
    document.getElementById('modal-edicion').style.display = 'block';

    // Agregar evento al formulario de edición
    document.getElementById('form-editar-cliente').addEventListener('submit', function(e) {
      e.preventDefault();
      actualizarCliente();
    });
  } catch (error) {
    console.error('Error al cargar cliente:', error);
    alert('Error al cargar los datos del cliente');
  }
}

// Función para actualizar el cliente
async function actualizarCliente() {
  const id = document.getElementById('id-cliente').value;
  const datos = {
    id: id,
    nombre: document.getElementById('nombre-editar').value,
    rfc: document.getElementById('rfc-editar').value,
    direccion: document.getElementById('direccion-editar').value,
    telefono: document.getElementById('telefono-editar').value,
    contactos: document.getElementById('contactos-editar').value,
    email: document.getElementById('email-editar').value
  };

  try {
    const response = await fetch('../backend/actualiza-cliente.php', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded',
      },
      body: new URLSearchParams(datos)
    });

    const resultado = await response.json();

    if (resultado.success) {
      alert('Cliente actualizado correctamente');
      document.getElementById('modal-edicion').style.display = 'none';
      cargarClientes(); // Recargar la lista de clientes
    } else {
      alert(resultado.error || 'Error al actualizar el cliente');
    }
  } catch (error) {
    console.error('Error al actualizar cliente:', error);
    alert('Error al actualizar el cliente');
  }
}

// Cargar clientes automáticamente al cargar la página
document.addEventListener('DOMContentLoaded', () => {
  cargarClientes();

  // Agregar evento al campo de búsqueda
  const campoBusqueda = document.getElementById('campo-busqueda');
  let timeout = null;

  campoBusqueda.addEventListener('input', () => {
    clearTimeout(timeout);
    timeout = setTimeout(() => {
      const query = campoBusqueda.value.trim();
      cargarClientes(query);
    }, 300); // Retraso de 300 milisegundos
  });
});
