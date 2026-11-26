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
        inventario: 'Inventario',
        cotizaciones: 'Cotizaciones',
        cuentas_por_cobrar: 'Cuentas por Cobrar',
        finanzas: 'Finanzas',
        problemas: 'Gestión de Problemas',
        cambios: 'Gestión de Cambios',
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

    // --- Reglas de automatización (solo Administrador/Programador) ---
    var automatizacionOpciones = { categorias: [], tecnicos: [], prioridades: [] };

    function filaReglaAutomatizacionHTML(regla) {
        regla = regla || { nombre: '', campo_condicion: 'categoria', valor_condicion: '', accion: 'asignar_tecnico', valor_accion: '', activo: 1, orden: 0 };
        var tr = document.createElement('tr');
        if (regla.id) tr.dataset.id = regla.id;

        var tdNombre = document.createElement('td');
        var inputNombre = document.createElement('input');
        inputNombre.type = 'text';
        inputNombre.className = 'auto-nombre';
        inputNombre.value = regla.nombre || '';
        inputNombre.style.width = '140px';
        tdNombre.appendChild(inputNombre);
        tr.appendChild(tdNombre);

        var tdCampo = document.createElement('td');
        var selectCampo = document.createElement('select');
        selectCampo.className = 'auto-campo-condicion';
        [['categoria', 'Categoría'], ['sucursal', 'Sucursal'], ['prioridad', 'Prioridad']].forEach(function (par) {
            var opt = document.createElement('option');
            opt.value = par[0];
            opt.textContent = par[1];
            if (par[0] === regla.campo_condicion) opt.selected = true;
            selectCampo.appendChild(opt);
        });
        tdCampo.appendChild(selectCampo);
        tr.appendChild(tdCampo);

        var tdValorCondicion = document.createElement('td');
        var inputValorCondicion = document.createElement('input');
        inputValorCondicion.type = 'text';
        inputValorCondicion.className = 'auto-valor-condicion';
        inputValorCondicion.value = regla.valor_condicion || '';
        inputValorCondicion.setAttribute('list', 'auto-categorias-datalist');
        inputValorCondicion.style.width = '130px';
        tdValorCondicion.appendChild(inputValorCondicion);
        tr.appendChild(tdValorCondicion);

        var tdAccion = document.createElement('td');
        var selectAccion = document.createElement('select');
        selectAccion.className = 'auto-accion';
        [['asignar_tecnico', 'Asignar técnico'], ['cambiar_prioridad', 'Cambiar prioridad']].forEach(function (par) {
            var opt = document.createElement('option');
            opt.value = par[0];
            opt.textContent = par[1];
            if (par[0] === regla.accion) opt.selected = true;
            selectAccion.appendChild(opt);
        });
        tdAccion.appendChild(selectAccion);
        tr.appendChild(tdAccion);

        var tdValorAccion = document.createElement('td');
        var inputValorAccion = document.createElement('input');
        inputValorAccion.type = 'text';
        inputValorAccion.className = 'auto-valor-accion';
        inputValorAccion.value = regla.valor_accion || '';
        inputValorAccion.setAttribute('list', 'auto-tecnicos-datalist');
        inputValorAccion.style.width = '130px';
        tdValorAccion.appendChild(inputValorAccion);
        tr.appendChild(tdValorAccion);

        var tdOrden = document.createElement('td');
        var inputOrden = document.createElement('input');
        inputOrden.type = 'number';
        inputOrden.className = 'auto-orden';
        inputOrden.value = regla.orden || 0;
        inputOrden.style.width = '60px';
        tdOrden.appendChild(inputOrden);
        tr.appendChild(tdOrden);

        var tdActivo = document.createElement('td');
        tdActivo.style.textAlign = 'center';
        var checkboxActivo = document.createElement('input');
        checkboxActivo.type = 'checkbox';
        checkboxActivo.className = 'auto-activo';
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

    function renderTablaAutomatizacion(reglas) {
        ['auto-categorias-datalist', 'auto-tecnicos-datalist'].forEach(function (id, i) {
            var datalist = document.getElementById(id);
            if (!datalist) {
                datalist = document.createElement('datalist');
                datalist.id = id;
                document.body.appendChild(datalist);
            }
            datalist.innerHTML = '';
            var fuente = i === 0 ? automatizacionOpciones.categorias : automatizacionOpciones.tecnicos;
            (fuente || []).forEach(function (valor) {
                var opt = document.createElement('option');
                opt.value = valor;
                datalist.appendChild(opt);
            });
        });

        var cuerpo = document.getElementById('cuerpo-automatizacion');
        cuerpo.innerHTML = '';
        if (!reglas.length) {
            cuerpo.appendChild(filaReglaAutomatizacionHTML());
            return;
        }
        reglas.forEach(function (regla) { cuerpo.appendChild(filaReglaAutomatizacionHTML(regla)); });
    }

    function guardarReglasAutomatizacion() {
        var reglas = [];
        document.querySelectorAll('#cuerpo-automatizacion tr').forEach(function (tr) {
            var nombre = tr.querySelector('.auto-nombre');
            var campo = tr.querySelector('.auto-campo-condicion');
            var valorCondicion = tr.querySelector('.auto-valor-condicion');
            var accion = tr.querySelector('.auto-accion');
            var valorAccion = tr.querySelector('.auto-valor-accion');
            var orden = tr.querySelector('.auto-orden');
            var activo = tr.querySelector('.auto-activo');
            if (!nombre || !campo) return;
            reglas.push({
                id: tr.dataset.id ? parseInt(tr.dataset.id, 10) : undefined,
                nombre: nombre.value.trim(),
                campo_condicion: campo.value,
                valor_condicion: valorCondicion.value.trim(),
                accion: accion.value,
                valor_accion: valorAccion.value.trim(),
                orden: parseInt(orden.value, 10) || 0,
                activo: activo.checked ? 1 : 0,
            });
        });

        fetch('../backend/guardar-reglas-automatizacion.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ reglas: reglas }),
        })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data.success) {
                    mostrarMensaje('mensaje-automatizacion', 'Reglas de automatización guardadas.', 'ok');
                    initAutomatizacion();
                } else {
                    mostrarMensaje('mensaje-automatizacion', data.message || 'No se pudieron guardar.', 'error');
                }
            })
            .catch(function () { mostrarMensaje('mensaje-automatizacion', 'Error de red al guardar.', 'error'); });
    }

    function initAutomatizacion() {
        fetch('../backend/obtener-reglas-automatizacion.php')
            .then(function (r) { return r.status === 403 ? null : r.json(); })
            .then(function (data) {
                if (!data || !data.success) return;
                automatizacionOpciones = { categorias: data.categorias, tecnicos: data.tecnicos, prioridades: data.prioridades };

                document.getElementById('seccion-automatizacion').style.display = 'block';
                renderTablaAutomatizacion(data.reglas);

                document.getElementById('btn-guardar-automatizacion').addEventListener('click', guardarReglasAutomatizacion);
                document.getElementById('btn-agregar-regla-automatizacion').addEventListener('click', function () {
                    document.getElementById('cuerpo-automatizacion').appendChild(filaReglaAutomatizacionHTML());
                });
            })
            .catch(function () { /* sin acceso: la sección queda oculta */ });
    }

    // --- Plantillas de respuesta rápida (solo Administrador/Programador) ---
    function filaPlantillaHTML(plantilla) {
        plantilla = plantilla || { titulo: '', contenido: '', categoria: '', activo: 1 };
        var tr = document.createElement('tr');
        if (plantilla.id) tr.dataset.id = plantilla.id;

        var tdTitulo = document.createElement('td');
        var inputTitulo = document.createElement('input');
        inputTitulo.type = 'text';
        inputTitulo.className = 'plantilla-titulo';
        inputTitulo.value = plantilla.titulo || '';
        inputTitulo.style.width = '150px';
        tdTitulo.appendChild(inputTitulo);
        tr.appendChild(tdTitulo);

        var tdContenido = document.createElement('td');
        var textareaContenido = document.createElement('textarea');
        textareaContenido.className = 'plantilla-contenido';
        textareaContenido.value = plantilla.contenido || '';
        textareaContenido.rows = 2;
        textareaContenido.style.width = '280px';
        tdContenido.appendChild(textareaContenido);
        tr.appendChild(tdContenido);

        var tdCategoria = document.createElement('td');
        var inputCategoria = document.createElement('input');
        inputCategoria.type = 'text';
        inputCategoria.className = 'plantilla-categoria';
        inputCategoria.value = plantilla.categoria || '';
        tdCategoria.appendChild(inputCategoria);
        tr.appendChild(tdCategoria);

        var tdActivo = document.createElement('td');
        tdActivo.style.textAlign = 'center';
        var checkboxActivo = document.createElement('input');
        checkboxActivo.type = 'checkbox';
        checkboxActivo.className = 'plantilla-activo';
        checkboxActivo.checked = !!Number(plantilla.activo);
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

    function guardarPlantillas() {
        var plantillas = [];
        document.querySelectorAll('#cuerpo-plantillas tr').forEach(function (tr) {
            var titulo = tr.querySelector('.plantilla-titulo');
            var contenido = tr.querySelector('.plantilla-contenido');
            if (!titulo || !contenido) return;
            plantillas.push({
                id: tr.dataset.id ? parseInt(tr.dataset.id, 10) : undefined,
                titulo: titulo.value.trim(),
                contenido: contenido.value.trim(),
                categoria: tr.querySelector('.plantilla-categoria').value.trim(),
                activo: tr.querySelector('.plantilla-activo').checked ? 1 : 0,
            });
        });

        fetch('../backend/guardar-plantillas-respuesta.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ plantillas: plantillas }),
        })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data.success) {
                    mostrarMensaje('mensaje-plantillas', 'Plantillas guardadas.', 'ok');
                    initPlantillas();
                } else {
                    mostrarMensaje('mensaje-plantillas', data.message || 'No se pudieron guardar.', 'error');
                }
            })
            .catch(function () { mostrarMensaje('mensaje-plantillas', 'Error de red al guardar.', 'error'); });
    }

    function initPlantillas() {
        fetch('../backend/obtener-plantillas-respuesta.php')
            .then(function (r) { return r.status === 403 ? null : r.json(); })
            .then(function (data) {
                if (!data || !data.success) return;

                document.getElementById('seccion-plantillas').style.display = 'block';
                var cuerpo = document.getElementById('cuerpo-plantillas');
                cuerpo.innerHTML = '';
                (data.plantillas.length ? data.plantillas : [null]).forEach(function (p) {
                    cuerpo.appendChild(filaPlantillaHTML(p));
                });

                document.getElementById('btn-guardar-plantillas').addEventListener('click', guardarPlantillas);
                document.getElementById('btn-agregar-plantilla').addEventListener('click', function () {
                    document.getElementById('cuerpo-plantillas').appendChild(filaPlantillaHTML());
                });
            })
            .catch(function () { /* sin acceso: la sección queda oculta */ });
    }

    // --- Etiquetas (solo Administrador/Programador) ---
    function filaEtiquetaHTML(etiqueta) {
        etiqueta = etiqueta || { nombre: '', color: '#888888' };
        var tr = document.createElement('tr');
        if (etiqueta.id) tr.dataset.id = etiqueta.id;

        var tdNombre = document.createElement('td');
        var inputNombre = document.createElement('input');
        inputNombre.type = 'text';
        inputNombre.className = 'etiqueta-nombre';
        inputNombre.value = etiqueta.nombre || '';
        tdNombre.appendChild(inputNombre);
        tr.appendChild(tdNombre);

        var tdColor = document.createElement('td');
        var inputColor = document.createElement('input');
        inputColor.type = 'color';
        inputColor.className = 'etiqueta-color';
        inputColor.value = etiqueta.color || '#888888';
        tdColor.appendChild(inputColor);
        tr.appendChild(tdColor);

        var tdQuitar = document.createElement('td');
        var btnQuitar = document.createElement('button');
        btnQuitar.type = 'button';
        btnQuitar.textContent = 'Quitar';
        btnQuitar.addEventListener('click', function () { tr.remove(); });
        tdQuitar.appendChild(btnQuitar);
        tr.appendChild(tdQuitar);

        return tr;
    }

    function guardarEtiquetas() {
        var etiquetas = [];
        document.querySelectorAll('#cuerpo-etiquetas tr').forEach(function (tr) {
            var nombre = tr.querySelector('.etiqueta-nombre');
            if (!nombre) return;
            etiquetas.push({
                id: tr.dataset.id ? parseInt(tr.dataset.id, 10) : undefined,
                nombre: nombre.value.trim(),
                color: tr.querySelector('.etiqueta-color').value,
            });
        });

        fetch('../backend/guardar-etiquetas.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ etiquetas: etiquetas }),
        })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data.success) {
                    mostrarMensaje('mensaje-etiquetas', 'Etiquetas guardadas.', 'ok');
                    initEtiquetas();
                } else {
                    mostrarMensaje('mensaje-etiquetas', data.message || 'No se pudieron guardar.', 'error');
                }
            })
            .catch(function () { mostrarMensaje('mensaje-etiquetas', 'Error de red al guardar.', 'error'); });
    }

    function initEtiquetas() {
        fetch('../backend/obtener-etiquetas.php')
            .then(function (r) { return r.status === 403 ? null : r.json(); })
            .then(function (data) {
                if (!data || !data.success) return;

                document.getElementById('seccion-etiquetas').style.display = 'block';
                var cuerpo = document.getElementById('cuerpo-etiquetas');
                cuerpo.innerHTML = '';
                (data.etiquetas.length ? data.etiquetas : [null]).forEach(function (e) {
                    cuerpo.appendChild(filaEtiquetaHTML(e));
                });

                document.getElementById('btn-guardar-etiquetas').addEventListener('click', guardarEtiquetas);
                document.getElementById('btn-agregar-etiqueta').addEventListener('click', function () {
                    document.getElementById('cuerpo-etiquetas').appendChild(filaEtiquetaHTML());
                });
            })
            .catch(function () { /* sin acceso: la sección queda oculta */ });
    }

    document.addEventListener('DOMContentLoaded', function () {
        initCambioPassword();
        initTema();
        initPrivilegios();
        initSla();
        initAutomatizacion();
        initPlantillas();
        initEtiquetas();
    });
})();
