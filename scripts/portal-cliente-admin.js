// Sección "Acceso al portal de clientes" dentro de perfil-cliente.html.
// Independiente de perfil-cliente.js: solo necesita el ?id= de la URL.
(function () {
    const clienteId = new URLSearchParams(window.location.search).get('id');
    if (!clienteId) return;

    function mostrarMensaje(texto, tipo) {
        const el = document.getElementById('mensaje-portal-usuario');
        el.textContent = texto;
        el.style.color = tipo === 'error' ? '#e74c3c' : '#27ae60';
    }

    function renderTabla(usuarios) {
        const cuerpo = document.getElementById('tabla-portal-usuarios');
        if (!usuarios.length) {
            cuerpo.innerHTML = '<tr><td colspan="4">Sin accesos de portal todavía.</td></tr>';
            return;
        }
        cuerpo.innerHTML = usuarios.map(u => `
            <tr>
                <td>${u.email}</td>
                <td>${u.activo ? 'Sí' : 'No'}</td>
                <td>${u.ultimo_acceso || 'Nunca'}</td>
                <td>${u.activo ? `<button type="button" data-id="${u.id}" class="btn-quitar-portal" style="background:#e74c3c; color:white; border:none; border-radius:4px; padding:4px 10px; cursor:pointer;">Desactivar</button>` : ''}</td>
            </tr>
        `).join('');

        cuerpo.querySelectorAll('.btn-quitar-portal').forEach(btn => {
            btn.addEventListener('click', async function () {
                if (!confirm('¿Desactivar este acceso al portal?')) return;
                const formData = new FormData();
                formData.append('id', btn.dataset.id);
                const res = await fetch('../backend/eliminar-portal-usuario.php', { method: 'POST', body: formData });
                const data = await res.json();
                if (data.success) { cargarUsuariosPortal(); } else { mostrarMensaje(data.error || 'No se pudo desactivar.', 'error'); }
            });
        });
    }

    async function cargarUsuariosPortal() {
        try {
            const res = await fetch('../backend/obtener-portal-usuarios.php?cliente_id=' + encodeURIComponent(clienteId));
            if (res.status === 403) return; // rol sin acceso: la sección queda oculta
            const data = await res.json();
            if (!data.success) return;

            document.getElementById('seccion-portal-cliente').style.display = 'block';
            renderTabla(data.usuarios);
        } catch (_) {
            // Sin acceso o error de red: la sección simplemente permanece oculta
        }
    }

    document.getElementById('form-portal-usuario').addEventListener('submit', async function (e) {
        e.preventDefault();
        const formData = new FormData();
        formData.append('cliente_id', clienteId);
        formData.append('email', document.getElementById('portal-email').value);
        formData.append('password', document.getElementById('portal-password').value);

        const res = await fetch('../backend/guardar-portal-usuario.php', { method: 'POST', body: formData });
        const data = await res.json();
        if (data.success) {
            mostrarMensaje('Acceso creado. Comparte el correo y la contraseña inicial con el cliente.', 'ok');
            this.reset();
            cargarUsuariosPortal();
        } else {
            mostrarMensaje(data.error || 'No se pudo crear el acceso.', 'error');
        }
    });

    document.addEventListener('DOMContentLoaded', cargarUsuariosPortal);
    if (document.readyState !== 'loading') cargarUsuariosPortal();
})();
