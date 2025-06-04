//alta usuarios
document.addEventListener('DOMContentLoaded', () => {
  const form = document.getElementById('alta-usuarios-form');

  form.addEventListener('submit', async (e) => {
    e.preventDefault();

    const nombre = document.getElementById('nombre').value.trim();
    const correo = document.getElementById('correo').value.trim();
    const telefono = document.getElementById('telefono').value.trim();
    const usuario = document.getElementById('usuario').value.trim();
    const password = document.getElementById('password').value;
    const confirmPassword = document.getElementById('confirm-password').value;
    const rol = document.getElementById('rol').value;

    if (password !== confirmPassword) {
      alert('Las contraseñas no coinciden');
      return;
    }

    const datos = new FormData();
    datos.append('nombre', nombre);
    datos.append('correo', correo);
    datos.append('telefono', telefono);
    datos.append('usuario', usuario);
    datos.append('password', password);
    datos.append('rol', rol);

    try {
      const respuesta = await fetch('../backend/alta-user.php', {
        method: 'POST',
        body: datos
      });

      const resultado = await respuesta.json();

      if (resultado.success) {
        alert('Usuario registrado exitosamente');
        form.reset();
        cargarUsuarios(); // Recargar la lista después de agregar
      } else {
        alert('Error: ' + resultado.message);
      }
    } catch (error) {
      console.error('Error al enviar los datos:', error);
      alert('Ocurrió un error al registrar el usuario.');
    }
  });

  cargarUsuarios();
});
// Función para cargar usuarios
async function cargarUsuarios() {
    try {
        const respuesta = await fetch('../backend/obtener-user.php');
        const resultado = await respuesta.json();

        if (resultado.success) {
            const tbody = document.querySelector('#tabla-usuarios tbody');
            tbody.innerHTML = ''; // Limpiar contenido existente

            resultado.data.forEach(usuario => {
                const fila = document.createElement('tr');

                fila.innerHTML = `
                    <td>${usuario.nombre}</td>
                    <td>${usuario.usuario}</td>
                    <td>${usuario.rol}</td>
                    <td>
                        <button class="btn-editar" data-id="${usuario.id}">Editar</button>
                        <button class="btn-eliminar" data-id="${usuario.id}">Eliminar</button>
                    </td>
                `;

                tbody.appendChild(fila);
            });

            // Agregar eventos a los botones de edición
            document.querySelectorAll('.btn-editar').forEach(boton => {
                boton.addEventListener('click', function() {
                    const id = this.getAttribute('data-id');
                    editarUsuario(id);
                });
            });

            // Agregar eventos a los botones de eliminación
            document.querySelectorAll('.btn-eliminar').forEach(boton => {
                boton.addEventListener('click', function() {
                    const id = this.getAttribute('data-id');
                    eliminarUsuario(id);
                });
            });
        } else {
            alert('Error al obtener los usuarios: ' + resultado.message);
        }
    } catch (error) {
        console.error('Error al cargar los usuarios:', error);
        alert('Ocurrió un error al cargar los usuarios.');
    }
}

// Función para editar usuario (modal)
async function editarUsuario(id) {
    try {
        const respuesta = await fetch(`../backend/detalle-usuario.php?id=${id}`);
        const usuario = await respuesta.json();

        if (usuario.error) {
            alert(usuario.error);
            return;
        }

        const formulario = document.getElementById('formulario-edicion');
        formulario.innerHTML = `
            <form id="form-editar-usuario">
                <input type="hidden" id="id-usuario" value="${usuario.id}">
                <div class="form-row">
                    <div>
                        <label for="nombre-editar">Nombre completo:</label>
                        <input type="text" id="nombre-editar" value="${usuario.nombre}" required>
                    </div>
                    <div>
                        <label for="correo-editar">Correo electrónico:</label>
                        <input type="email" id="correo-editar" value="${usuario.correo}" required>
                    </div>
                </div>
                <div class="form-row">
                    <div>
                        <label for="telefono-editar">Teléfono:</label>
                        <input type="text" id="telefono-editar" value="${usuario.telefono}">
                    </div>
                    <div>
                        <label for="usuario-editar">Usuario:</label>
                        <input type="text" id="usuario-editar" value="${usuario.usuario}" required>
                    </div>
                </div>
                <div class="form-row">
                    <div>
                        <label for="password-editar">Contraseña (dejar en blanco para no cambiar):</label>
                        <input type="password" id="password-editar">
                    </div>
                    <div>
                        <label for="rol-editar">Rol:</label>
                        <select id="rol-editar" required>
                            <option value="administrador" ${usuario.rol === 'administrador' ? 'selected' : ''}>Administrador</option>
                            <option value="tecnico" ${usuario.rol === 'tecnico' ? 'selected' : ''}>Técnico</option>
                            <option value="administrativo" ${usuario.rol === 'administrativo' ? 'selected' : ''}>Administrativo</option>
                        </select>
                    </div>
                </div>
                <button type="submit">Guardar Cambios</button>
            </form>
        `;

        // Mostrar el modal
        document.getElementById('modal-edicion').style.display = 'block';

        // Agregar evento al formulario de edición
        document.getElementById('form-editar-usuario').addEventListener('submit', function(e) {
            e.preventDefault();
            actualizarUsuario();
        });

    } catch (error) {
        console.error('Error al cargar usuario:', error);
        alert('Error al cargar los datos del usuario');
    }
}

// Función para actualizar usuario
async function actualizarUsuario() {
    const id = document.getElementById('id-usuario').value;
    const datos = {
        id: id,
        nombre: document.getElementById('nombre-editar').value,
        correo: document.getElementById('correo-editar').value,
        telefono: document.getElementById('telefono-editar').value,
        usuario: document.getElementById('usuario-editar').value,
        password: document.getElementById('password-editar').value,
        rol: document.getElementById('rol-editar').value
    };

    try {
        const respuesta = await fetch('../backend/actualiza-usuario.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify(datos)
        });

        const resultado = await respuesta.json();

        if (resultado.success) {
            alert('Usuario actualizado correctamente');
            document.getElementById('modal-edicion').style.display = 'none';
            cargarUsuarios(); // Recargar la lista de usuarios
        } else {
            alert(resultado.message || 'Error al actualizar el usuario');
        }
    } catch (error) {
        console.error('Error al actualizar usuario:', error);
        alert('Error al actualizar el usuario');
    }
}

// Función para eliminar usuario
async function eliminarUsuario(id) {
    if (confirm('¿Estás seguro de que deseas eliminar este usuario?')) {
        try {
            const respuesta = await fetch(`../backend/eliminar_usuario.php?id=${id}`);
            const resultado = await respuesta.json();

            if (resultado.success) {
                alert('Usuario eliminado exitosamente.');
                cargarUsuarios(); // Recargar la lista de usuarios
            } else {
                alert('Error al eliminar el usuario: ' + resultado.message);
            }
        } catch (error) {
            console.error('Error al eliminar el usuario:', error);
            alert('Ocurrió un error al eliminar el usuario.');
        }
    }
}