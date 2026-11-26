(function () {
    var ETIQUETAS_MODULOS = {
        incidencias: 'Órdenes de servicio',
        reportes: 'Buscar órdenes de servicio',
        clientes: 'Clientes',
        cotizaciones: 'Cotizaciones',
        ventas: 'Ventas',
        inventario: 'Inventario',
        finanzas: 'Finanzas',
        usuarios: 'Usuarios',
        soporte: 'Soporte Técnico',
        informes: 'Estadísticas',
        historial: 'Historial de cambios',
    };

    // Aviso estándar del sistema; el texto también queda junto al formulario
    function mostrarMensaje(elId, texto, tipo) {
        var el = document.getElementById(elId);
        el.textContent = texto;
        el.style.color = tipo === 'error' ? '#e74c3c' : '#27ae60';
        if (texto && !/…$/.test(texto) && window.AppAviso) AppAviso.mostrar(texto, tipo === 'error' ? 'error' : 'ok');
    }

    // --- Cambiar mi propia contraseña ---
    function initCambioPassword() {
        var form = document.getElementById('form-cambiar-password');
        // L-13 (H-11): el aviso depende de la sesión, no de la dirección; auth-guard trae aquí
        // a quien tiene la contraseña temporal del panel
        var temporal = false;
        if (window.appOnAuthReady) window.appOnAuthReady(function (auth) {
            temporal = !!(auth.user && auth.user.debe_cambiar_password);
            var aviso = document.getElementById('aviso-password-temporal');
            if (temporal && aviso) { aviso.hidden = false; document.getElementById('password-actual').focus(); }
        });
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
                        if (temporal) setTimeout(function () { window.location.replace('../index.html'); }, 1200);
                    } else {
                        mostrarMensaje('mensaje-password', data.message || 'No se pudo cambiar la contraseña.', 'error');
                    }
                })
                .catch(function () {
                    mostrarMensaje('mensaje-password', 'Error de red al cambiar la contraseña.', 'error');
                });
        });
    }

    // --- Apariencia: tema claro/oscuro (cualquier rol) ---
    function initTema() {
        var radios = document.querySelectorAll('input[name="tema"]');
        if (!radios.length || !window.appTema) return;

        function marcar() {
            var actual = window.appTema.obtener();
            Array.prototype.forEach.call(radios, function (r) { r.checked = r.value === actual; });
        }
        marcar();
        // Si el tema de la cuenta llega del servidor después de cargar la página
        window.addEventListener('app-tema', marcar);

        Array.prototype.forEach.call(radios, function (r) {
            r.addEventListener('change', function () {
                if (!r.checked) return;
                mostrarMensaje('mensaje-tema', 'Guardando…', 'ok');
                window.appTema.establecer(r.value).then(function (res) {
                    if (res.guardado) {
                        mostrarMensaje('mensaje-tema', 'Tema guardado en tu cuenta.', 'ok');
                    } else {
                        mostrarMensaje('mensaje-tema', res.mensaje || 'No se pudo guardar el tema en tu cuenta.', 'error');
                    }
                });
            });
        });
    }

    // --- Checklist de privilegios por rol (solo Administrador) ---
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

    // Plan y pagos: visible solo para quien puede contratar (session_check.php)
    function initPlan() {
        if (!window.appOnAuthReady) return;
        window.appOnAuthReady(function (auth) {
            if (auth.empresa && auth.empresa.puede_contratar) {
                document.getElementById('seccion-plan').hidden = false;
            }
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        initCambioPassword();
        initTema();
        initPrivilegios();
        initPlan();
    });
})();
