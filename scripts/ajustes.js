(function () {
    var ETIQUETAS_MODULOS = {
        incidencias: 'Incidencias',
        reportes: 'Buscar Incidencias',
        clientes: 'Clientes',
        ventas: 'Ventas',
        usuarios: 'Usuarios',
        soporte: 'Soporte Técnico',
        informes: 'Estadísticas',
        historial: 'Historial de cambios',
    };

    function mostrarMensaje(elId, texto, tipo) {
        var el = document.getElementById(elId);
        el.textContent = texto;
        el.style.color = tipo === 'error' ? '#e74c3c' : '#27ae60';
    }

    // --- Cambiar mi propia contraseña ---
    function initCambioPassword() {
        var form = document.getElementById('form-cambiar-password');
        form.addEventListener('submit', function (ev) {
            ev.preventDefault();

            var actual = document.getElementById('password-actual').value;
            var nueva = document.getElementById('password-nueva').value;
            var confirmar = document.getElementById('password-nueva-confirmar').value;

            if (nueva !== confirmar) {
                mostrarMensaje('mensaje-password', 'La confirmación no coincide con la contraseña nueva.', 'error');
                return;
            }

            fetch('../backend/cambiar_password.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ password_actual: actual, password_nueva: nueva }),
            })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (data.success) {
                        mostrarMensaje('mensaje-password', data.message || 'Contraseña actualizada.', 'ok');
                        form.reset();
                    } else {
                        mostrarMensaje('mensaje-password', data.message || 'No se pudo cambiar la contraseña.', 'error');
                    }
                })
                .catch(function () {
                    mostrarMensaje('mensaje-password', 'Error de red al cambiar la contraseña.', 'error');
                });
        });
    }

    // --- Checklist de privilegios por rol (solo Administrador/Programador) ---
    function renderTablaPrivilegios(roles, modulos, matriz) {
        var filaEncabezado = document.getElementById('fila-encabezado-privilegios');
        roles.forEach(function (rol) {
            var th = document.createElement('th');
            th.textContent = rol;
            filaEncabezado.appendChild(th);
        });

        var cuerpo = document.getElementById('cuerpo-privilegios');
        cuerpo.innerHTML = '';

        modulos.forEach(function (modulo) {
            var tr = document.createElement('tr');
            var tdModulo = document.createElement('td');
            tdModulo.textContent = ETIQUETAS_MODULOS[modulo] || modulo;
            tr.appendChild(tdModulo);

            roles.forEach(function (rol) {
                var td = document.createElement('td');
                td.style.textAlign = 'center';
                var checkbox = document.createElement('input');
                checkbox.type = 'checkbox';
                checkbox.dataset.rol = rol;
                checkbox.dataset.modulo = modulo;
                checkbox.checked = !!(matriz[rol] && matriz[rol][modulo]);
                checkbox.style.width = '18px';
                checkbox.style.height = '18px';
                checkbox.style.cursor = 'pointer';
                td.appendChild(checkbox);
                tr.appendChild(td);
            });

            cuerpo.appendChild(tr);
        });
    }

    function guardarPrivilegios() {
        var matriz = {};
        document.querySelectorAll('#cuerpo-privilegios input[type="checkbox"]').forEach(function (chk) {
            var rol = chk.dataset.rol;
            var modulo = chk.dataset.modulo;
            if (!matriz[rol]) matriz[rol] = {};
            matriz[rol][modulo] = chk.checked;
        });

        fetch('../backend/guardar_permisos.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ matriz: matriz }),
        })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data.success) {
                    mostrarMensaje('mensaje-privilegios', 'Privilegios guardados.', 'ok');
                } else {
                    mostrarMensaje('mensaje-privilegios', data.message || 'No se pudieron guardar los privilegios.', 'error');
                }
            })
            .catch(function () {
                mostrarMensaje('mensaje-privilegios', 'Error de red al guardar.', 'error');
            });
    }

    function initPrivilegios() {
        fetch('../backend/obtener_permisos.php')
            .then(function (r) {
                if (r.status === 403) return null; // rol sin acceso a esta sección, se queda oculta
                return r.json();
            })
            .then(function (data) {
                if (!data || !data.success) return;

                document.getElementById('seccion-privilegios').style.display = 'block';
                renderTablaPrivilegios(data.roles, data.modulos, data.matriz);

                document.getElementById('btn-guardar-privilegios').addEventListener('click', guardarPrivilegios);
            })
            .catch(function () {
                // Sin acceso o error de red: la sección simplemente permanece oculta
            });
    }

    document.addEventListener('DOMContentLoaded', function () {
        initCambioPassword();
        initPrivilegios();
    });
})();
