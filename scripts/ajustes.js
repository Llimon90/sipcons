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

    // --- Apariencia: tema claro/oscuro (cualquier rol) ---
    function initTema() {
        var radios = document.querySelectorAll('input[name="tema"]');
        if (!radios.length || !window.sipconsTema) return;

        function marcar() {
            var actual = window.sipconsTema.obtener();
            Array.prototype.forEach.call(radios, function (r) { r.checked = r.value === actual; });
        }
        marcar();
        // Si el tema de la cuenta llega del servidor después de cargar la página
        window.addEventListener('sipcons-tema', marcar);

        Array.prototype.forEach.call(radios, function (r) {
            r.addEventListener('change', function () {
                if (!r.checked) return;
                mostrarMensaje('mensaje-tema', 'Guardando…', 'ok');
                window.sipconsTema.establecer(r.value).then(function (res) {
                    if (res.guardado) {
                        mostrarMensaje('mensaje-tema', 'Tema guardado en tu cuenta.', 'ok');
                    } else {
                        mostrarMensaje('mensaje-tema', res.mensaje || 'No se pudo guardar el tema en tu cuenta.', 'error');
                    }
                });
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

    // --- Reglas de SLA por categoría/prioridad (solo Administrador/Programador) ---
    var PRIORIDADES_SLA = ['Baja', 'Media', 'Alta', 'Crítica'];
    var categoriasDisponibles = [];

    function filaReglaSlaHTML(regla) {
        regla = regla || { categoria: '', prioridad: 'Media', horas_respuesta: 4, horas_cierre: 168, activo: 1 };
        var tr = document.createElement('tr');

        var tdCategoria = document.createElement('td');
        var inputCategoria = document.createElement('input');
        inputCategoria.type = 'text';
        inputCategoria.className = 'sla-categoria';
        inputCategoria.setAttribute('list', 'sla-categorias-datalist');
        inputCategoria.value = regla.categoria || '';
        inputCategoria.placeholder = '(general)';
        tdCategoria.appendChild(inputCategoria);
        tr.appendChild(tdCategoria);

        var tdPrioridad = document.createElement('td');
        var selectPrioridad = document.createElement('select');
        selectPrioridad.className = 'sla-prioridad';
        PRIORIDADES_SLA.forEach(function (p) {
            var opt = document.createElement('option');
            opt.value = p;
            opt.textContent = p;
            if (p === regla.prioridad) opt.selected = true;
            selectPrioridad.appendChild(opt);
        });
        tdPrioridad.appendChild(selectPrioridad);
        tr.appendChild(tdPrioridad);

        var tdRespuesta = document.createElement('td');
        var inputRespuesta = document.createElement('input');
        inputRespuesta.type = 'number';
        inputRespuesta.min = '1';
        inputRespuesta.className = 'sla-horas-respuesta';
        inputRespuesta.value = regla.horas_respuesta;
        inputRespuesta.style.width = '90px';
        tdRespuesta.appendChild(inputRespuesta);
        tr.appendChild(tdRespuesta);

        var tdCierre = document.createElement('td');
        var inputCierre = document.createElement('input');
        inputCierre.type = 'number';
        inputCierre.min = '1';
        inputCierre.className = 'sla-horas-cierre';
        inputCierre.value = regla.horas_cierre;
        inputCierre.style.width = '90px';
        tdCierre.appendChild(inputCierre);
        tr.appendChild(tdCierre);

        var tdActivo = document.createElement('td');
        tdActivo.style.textAlign = 'center';
        var checkboxActivo = document.createElement('input');
        checkboxActivo.type = 'checkbox';
        checkboxActivo.className = 'sla-activo';
        checkboxActivo.checked = !!Number(regla.activo);
        tdActivo.appendChild(checkboxActivo);
        tr.appendChild(tdActivo);

        var tdQuitar = document.createElement('td');
        var btnQuitar = document.createElement('button');
        btnQuitar.type = 'button';
        btnQuitar.textContent = 'Quitar';
        btnQuitar.addEventListener('click', function () { tr.remove(); });
        tdQuitar.appendChild(btnQuitar);
        tr.appendChild(tdQuitar);

        return tr;
    }

    function renderTablaSla(reglas, categorias) {
        categoriasDisponibles = categorias || [];
        var datalist = document.getElementById('sla-categorias-datalist');
        if (!datalist) {
            datalist = document.createElement('datalist');
            datalist.id = 'sla-categorias-datalist';
            document.body.appendChild(datalist);
        }
        datalist.innerHTML = '';
        categoriasDisponibles.forEach(function (c) {
            var opt = document.createElement('option');
            opt.value = c;
            datalist.appendChild(opt);
        });

        var cuerpo = document.getElementById('cuerpo-sla');
        cuerpo.innerHTML = '';
        if (!reglas.length) {
            cuerpo.appendChild(filaReglaSlaHTML());
            return;
        }
        reglas.forEach(function (regla) { cuerpo.appendChild(filaReglaSlaHTML(regla)); });
    }

    function guardarReglasSla() {
        var reglas = [];
        document.querySelectorAll('#cuerpo-sla tr').forEach(function (tr) {
            var categoria = tr.querySelector('.sla-categoria');
            var prioridad = tr.querySelector('.sla-prioridad');
            var horasRespuesta = tr.querySelector('.sla-horas-respuesta');
            var horasCierre = tr.querySelector('.sla-horas-cierre');
            var activo = tr.querySelector('.sla-activo');
            if (!categoria || !prioridad) return;
            reglas.push({
                categoria: categoria.value.trim(),
                prioridad: prioridad.value,
                horas_respuesta: parseInt(horasRespuesta.value, 10) || 0,
                horas_cierre: parseInt(horasCierre.value, 10) || 0,
                activo: activo.checked ? 1 : 0,
            });
        });

        fetch('../backend/guardar_sla_reglas.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ reglas: reglas }),
        })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data.success) {
                    mostrarMensaje('mensaje-sla', 'Reglas de SLA guardadas.', 'ok');
                    initSla();
                } else {
                    mostrarMensaje('mensaje-sla', data.message || 'No se pudieron guardar las reglas de SLA.', 'error');
                }
            })
            .catch(function () {
                mostrarMensaje('mensaje-sla', 'Error de red al guardar.', 'error');
            });
    }

    function initSla() {
        fetch('../backend/obtener_sla_reglas.php')
            .then(function (r) {
                if (r.status === 403) return null; // rol sin acceso: la sección queda oculta
                return r.json();
            })
            .then(function (data) {
                if (!data || !data.success) return;

                document.getElementById('seccion-sla').style.display = 'block';
                renderTablaSla(data.reglas, data.categorias);

                document.getElementById('btn-guardar-sla').addEventListener('click', guardarReglasSla);
                document.getElementById('btn-agregar-regla-sla').addEventListener('click', function () {
                    document.getElementById('cuerpo-sla').appendChild(filaReglaSlaHTML());
                });
            })
            .catch(function () {
                // Sin acceso o error de red: la sección simplemente permanece oculta
            });
    }

    document.addEventListener('DOMContentLoaded', function () {
        initCambioPassword();
        initTema();
        initPrivilegios();
        initSla();
    });
})();
