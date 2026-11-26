//alta usuarios
document.addEventListener('DOMContentLoaded', () => {
  const form = document.getElementById('alta-usuarios-form');

  form.addEventListener('submit', async (e) => {
    e.preventDefault();

    const nombre = document.getElementById('nombre').value.trim();
    const correo = document.getElementById('correo').value.trim();
    const telefono = document.getElementById('telefono').value.trim();
    const password = document.getElementById('password').value;
    const confirmPassword = document.getElementById('confirm-password').value;
    const rol = document.getElementById('rol').value;

    if (password !== confirmPassword) {
      AppAviso.alerta('Las contraseñas no coinciden');
      return;
    }

    const datos = new FormData();
    datos.append('nombre', nombre);
    datos.append('correo', correo);
    datos.append('telefono', telefono);
    datos.append('password', password);
    datos.append('rol', rol);

    try {
      const respuesta = await fetch('../backend/alta-user.php', {
        method: 'POST',
        body: datos
      });

      const resultado = await respuesta.json();

      if (resultado.success) {
        AppAviso.alerta('Usuario registrado exitosamente');
        form.reset();
        cargarUsuarios(); // Recargar la lista después de agregar
      } else {
        AppAviso.alerta('Error: ' + resultado.message);
      }
    } catch (error) {
      console.error('Error al enviar los datos:', error);
      AppAviso.alerta('Ocurrió un error al registrar el usuario.');
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
                    <td>${escHTML(usuario.nombre)}</td>
                    <td>${escHTML(usuario.usuario)}</td>
                    <td>${escHTML(usuario.rol)}</td>
                    <td>
                        <div class="acciones-usuario">
                            <a class="btn-editar" href="perfil.html?id=${encodeURIComponent(usuario.id)}" title="Ver perfil" aria-label="Ver perfil de ${escHTML(usuario.nombre)}">
                                <i class="fas fa-id-badge" aria-hidden="true"></i>
                            </a>
                            <button class="btn-editar" data-fila-accion data-id="${escHTML(usuario.id)}" title="Editar" aria-label="Editar a ${escHTML(usuario.nombre)}">
                                <i class="fas fa-edit" aria-hidden="true"></i>
                            </button>
                            <button class="btn-eliminar" data-id="${escHTML(usuario.id)}" title="Eliminar" aria-label="Eliminar a ${escHTML(usuario.nombre)}">
                                <i class="fas fa-trash-alt" aria-hidden="true"></i>
                            </button>
                        </div>
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
            AppAviso.alerta('Error al obtener los usuarios: ' + resultado.message);
        }
    } catch (error) {
        console.error('Error al cargar los usuarios:', error);
        AppAviso.alerta('Ocurrió un error al cargar los usuarios.');
    }
}

// Función para editar usuario (modal)
async function editarUsuario(id) {
    try {
        const respuesta = await fetch(`../backend/detalle-usuario.php?id=${id}`);
        const usuario = await respuesta.json();

        if (usuario.error) {
            AppAviso.alerta(usuario.error);
            return;
        }

        const formulario = document.getElementById('formulario-edicion');
        formulario.innerHTML = `
            <form id="form-editar-usuario">
                <input type="hidden" id="id-usuario" value="${escHTML(usuario.id)}">
                <div class="form-row">
                    <div>
                        <label for="nombre-editar">Nombre completo:</label>
                        <input type="text" id="nombre-editar" value="${escHTML(usuario.nombre)}" required>
                    </div>
                    <div>
                        <label for="correo-editar">Correo electrónico (usuario de acceso):</label>
                        <input type="email" id="correo-editar" value="${escHTML(usuario.correo)}" required maxlength="100">
                        <small class="ayuda-campo">Si lo cambias, entrará con el nuevo correo.</small>
                    </div>
                </div>
                <div class="form-row">
                    <div>
                        <label for="telefono-editar">Teléfono:</label>
                        <input type="text" id="telefono-editar" value="${escHTML(usuario.telefono)}">
                    </div>
                    <div>
                        <label>Usuario de acceso actual:</label>
                        <input type="text" value="${escHTML(usuario.usuario)}" disabled>
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
                            <option value="Técnico" ${usuario.rol === 'Técnico' ? 'selected' : ''}>Técnico</option>
                            <option value="Administrativo" ${usuario.rol === 'Administrativo' ? 'selected' : ''}>Administrativo</option>
                            <option value="Supervisor" ${usuario.rol === 'Supervisor' ? 'selected' : ''}>Supervisor</option>
                            <option value="Administrador" ${usuario.rol === 'Administrador' ? 'selected' : ''}>Administrador</option>
                            
                        </select>
                    </div>
                </div>
                <div class="form-row">
                    <div>
                        <label class="check-permiso">
                            <input type="checkbox" id="puede-cerrar-editar" ${usuario.puede_cerrar === undefined || String(usuario.puede_cerrar) === '1' ? 'checked' : ''}>
                            Puede cerrar órdenes de servicio (Cerrado con factura / sin factura)
                        </label>
                        <small style="color:#7f8c8d;">El rol Técnico nunca puede cerrarlas, aunque esté marcado.</small>
                    </div>
                </div>
                <div class="form-row">
                    <div>
                        <label class="check-permiso">
                            <input type="checkbox" id="autoriza-descuentos-editar" ${String(usuario.puede_autorizar_descuentos) === '1' ? 'checked' : ''}>
                            Puede autorizar descuentos (en cotizaciones)
                        </label>
                        <small style="color:#7f8c8d;">Permite rebasar el descuento máximo de la empresa (Mi empresa → Cotizaciones). El Administrador siempre puede.</small>
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
        AppAviso.alerta('Error al cargar los datos del usuario');
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
        password: document.getElementById('password-editar').value,
        rol: document.getElementById('rol-editar').value,
        puede_cerrar: document.getElementById('puede-cerrar-editar').checked ? 1 : 0,
        puede_autorizar_descuentos: document.getElementById('autoriza-descuentos-editar').checked ? 1 : 0
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
            AppAviso.alerta(resultado.acceso_cambiado
                ? 'Usuario actualizado. A partir de ahora inicia sesión con: ' + resultado.usuario
                : 'Usuario actualizado correctamente');
            document.getElementById('modal-edicion').style.display = 'none';
            cargarUsuarios(); // Recargar la lista de usuarios
        } else {
            AppAviso.alerta(resultado.message || 'Error al actualizar el usuario');
        }
    } catch (error) {
        console.error('Error al actualizar usuario:', error);
        AppAviso.alerta('Error al actualizar el usuario');
    }
}

// Función para eliminar usuario
async function eliminarUsuario(id) {
    if (await AppAviso.confirmar('La persona ya no podrá entrar al sistema. Su historial se conserva.', { peligro: true, boton: 'Sí, eliminar', titulo: '¿Eliminar este usuario?' })) {
        try {
            const respuesta = await fetch('../backend/eliminar_usuario.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ id })
            });
            const resultado = await respuesta.json();

            if (resultado.success) {
                AppAviso.alerta('Usuario eliminado exitosamente.');
                cargarUsuarios(); // Recargar la lista de usuarios
            } else {
                AppAviso.alerta('Error al eliminar el usuario: ' + resultado.message);
            }
        } catch (error) {
            console.error('Error al eliminar el usuario:', error);
            AppAviso.alerta('Ocurrió un error al eliminar el usuario.');
        }
    }
}