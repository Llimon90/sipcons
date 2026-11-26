// Sección "Conceptos aplicados" del detalle de la orden de servicio (public/detalle.html).
// D-23: tres clases de conceptos:
//  - refacción o consumible: sale del inventario (se puede devolver);
//  - servicio o mano de obra: no tiene existencias (se puede quitar);
//  - equipo de reemplazo: sale del inventario y se da de alta, con su serie, en el
//    padrón del cliente.
// O-7: quien tiene Inventario da de alta un artículo sin salir de la orden; quien no,
// solicita el alta y queda como nota en la orden.
// Es independiente de detalle.js: solo necesita el ?id= de la URL y #refacciones-incidencia.
(function () {
    var API = '../backend/inventario.php';
    var SIN_EXISTENCIA = ['Servicio', 'Mano de obra'];

    function esc(v) {
        return String(v == null ? '' : v)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }
    function num(n) {
        n = parseFloat(n) || 0;
        return n % 1 === 0 ? String(n) : n.toFixed(2);
    }
    function dinero(n) {
        return (parseFloat(n) || 0).toLocaleString('es-MX', { style: 'currency', currency: 'MXN' });
    }
    function avisar(texto, tipo) {
        AppAviso.mostrar(texto, tipo === 'success' || !tipo ? 'ok' : tipo);
    }
    function api(accion, datos) {
        var opciones = { credentials: 'same-origin', cache: 'no-store' };
        var url = API + '?accion=' + accion;
        if (datos && datos.metodo === 'GET') {
            url += '&' + new URLSearchParams(datos.params).toString();
        } else if (datos) {
            opciones.method = 'POST';
            opciones.headers = { 'Content-Type': 'application/json' };
            opciones.body = JSON.stringify(datos);
        }
        return fetch(url, opciones).then(function (r) {
            return r.json().catch(function () { return {}; }).then(function (j) {
                if (!r.ok || !j.exito) throw new Error(j.mensaje || 'Error ' + r.status);
                return j;
            });
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        var cont = document.getElementById('refacciones-incidencia');
        var incidenciaId = new URLSearchParams(window.location.search).get('id');
        if (!cont || !incidenciaId) return;
        var articulos = [];
        var puedeInventario = false;

        cont.innerHTML =
            '<form class="inv-consumo-form" autocomplete="off">' +
            '  <div class="inv-consumo-campo inv-consumo-articulo">' +
            '    <label for="inv-articulo">Concepto</label>' +
            '    <select id="inv-articulo" required><option value="">Cargando catálogo...</option></select>' +
            '  </div>' +
            '  <div class="inv-consumo-campo">' +
            '    <label for="inv-cantidad">Cantidad</label>' +
            '    <input type="number" id="inv-cantidad" min="0.01" step="0.01" value="1" required>' +
            '  </div>' +
            '  <button type="submit" class="btn-secundario"><i class="fas fa-plus"></i> Aplicar</button>' +
            '</form>' +
            '<div class="inv-reemplazo" hidden>' +
            '  <label class="inv-check"><input type="checkbox" id="inv-es-reemplazo" checked> Es un <b>equipo de reemplazo</b>: darlo de alta en el padrón del cliente</label>' +
            '  <div class="inv-reemplazo-campos">' +
            '    <label>Número de serie <input type="text" id="inv-serie" maxlength="100" placeholder="Serie del equipo instalado"></label>' +
            '    <label>Garantía (meses) <input type="number" id="inv-garantia" min="0" max="120" step="1" value="0"></label>' +
            '  </div>' +
            '</div>' +
            '<p class="inv-alta-articulo"></p>' +
            '<table class="tabla-ventas inv-consumo-tabla">' +
            '  <thead><tr><th>Tipo</th><th>Concepto</th><th>Cantidad</th><th>Importe</th><th></th></tr></thead>' +
            '  <tbody><tr><td colspan="5">Cargando...</td></tr></tbody>' +
            '</table>';

        var form = cont.querySelector('form');
        var select = cont.querySelector('#inv-articulo');
        var inputCant = cont.querySelector('#inv-cantidad');
        var tbody = cont.querySelector('tbody');
        var bloqueReemplazo = cont.querySelector('.inv-reemplazo');
        var chkReemplazo = cont.querySelector('#inv-es-reemplazo');
        var pAlta = cont.querySelector('.inv-alta-articulo');

        function articulo(id) {
            return articulos.filter(function (a) { return String(a.id) === String(id); })[0] || null;
        }
        function esServicio(a) { return a && SIN_EXISTENCIA.indexOf(a.categoria) !== -1; }

        function cargarOpciones(seleccionar) {
            return api('opciones', { metodo: 'GET', params: { con_servicios: 1 } }).then(function (j) {
                articulos = j.articulos || [];
                var grupos = { fisicos: [], equipos: [], servicios: [] };
                articulos.forEach(function (a) {
                    var g = esServicio(a) ? 'servicios' : (a.categoria === 'Equipo' ? 'equipos' : 'fisicos');
                    var sin = g !== 'servicios' && parseFloat(a.existencia) <= 0;
                    grupos[g].push('<option value="' + a.id + '"' + (sin ? ' disabled' : '') + '>' +
                        esc(a.sku + ' · ' + a.nombre) + (g !== 'servicios' ? ' — ' + num(a.existencia) + ' ' + esc(a.unidad) + (sin ? ' (agotado)' : '') : '') + '</option>');
                });
                select.innerHTML = '<option value="">Selecciona un concepto...</option>' +
                    (grupos.fisicos.length ? '<optgroup label="Refacciones y consumibles">' + grupos.fisicos.join('') + '</optgroup>' : '') +
                    (grupos.servicios.length ? '<optgroup label="Servicios y mano de obra">' + grupos.servicios.join('') + '</optgroup>' : '') +
                    (grupos.equipos.length ? '<optgroup label="Equipos">' + grupos.equipos.join('') + '</optgroup>' : '');
                if (seleccionar) { select.value = seleccionar; actualizarTipo(); }
            }).catch(function (e) {
                select.innerHTML = '<option value="">Catálogo no disponible</option>';
                form.querySelector('button').disabled = true;
                console.error(e);
            });
        }

        function actualizarTipo() {
            var a = articulo(select.value);
            bloqueReemplazo.hidden = !(a && a.categoria === 'Equipo');
            if (!bloqueReemplazo.hidden && chkReemplazo.checked) inputCant.value = 1;
            cont.querySelector('.inv-reemplazo-campos').hidden = !chkReemplazo.checked;
        }
        select.addEventListener('change', actualizarTipo);
        chkReemplazo.addEventListener('change', actualizarTipo);

        function cargarConsumos() {
            return api('consumos_incidencia', { metodo: 'GET', params: { incidencia_id: incidenciaId } }).then(function (j) {
                var consumos = j.consumos || [], servicios = j.servicios || [], reemplazos = j.reemplazos || [];
                // La sección de cotizaciones ofrece «Cotizar lo aplicado» cuando hay algo
                document.dispatchEvent(new CustomEvent('refacciones:cambio', { detail: { total: consumos.length + servicios.length } }));
                if (!consumos.length && !servicios.length) {
                    tbody.innerHTML = '<tr><td colspan="5" class="inv-vacio">Sin conceptos aplicados en esta orden de servicio.</td></tr>';
                    return;
                }
                var total = 0;
                var filas = consumos.map(function (c) {
                    // D-51: importe al precio con que se aplicó (el servidor lo calcula)
                    var importe = c.importe != null ? (parseFloat(c.importe) || 0) : (parseFloat(c.cantidad) || 0) * (parseFloat(c.precio_venta) || 0);
                    total += importe;
                    var series = reemplazos.filter(function (r) { return String(r.articulo_id) === String(c.articulo_id); });
                    var tipo = series.length ? 'Equipo de reemplazo' : 'Refacción';
                    return '<tr>' +
                        '<td><span class="inv-tipo">' + tipo + '</span></td>' +
                        '<td>' + esc(c.sku) + ' · ' + esc(c.nombre) +
                        (series.length ? '<span class="inv-sub">En el padrón del cliente · serie ' + series.map(function (r) { return esc(r.numero_serie); }).join(', ') + '</span>' : '') + '</td>' +
                        '<td>' + num(c.cantidad) + ' ' + esc(c.unidad) + '</td>' +
                        '<td>' + dinero(importe) + '</td>' +
                        '<td><button type="button" class="btn-delete" data-devolver="' + c.articulo_id + '" title="Devolver al almacén">' +
                        '<i class="fas fa-undo"></i> Devolver</button></td>' +
                        '</tr>';
                }).concat(servicios.map(function (s) {
                    var importe = (parseFloat(s.cantidad) || 0) * (parseFloat(s.precio_unitario) || 0);
                    total += importe;
                    return '<tr>' +
                        '<td><span class="inv-tipo">Servicio</span></td>' +
                        '<td>' + esc(s.descripcion) + '</td>' +
                        '<td>' + num(s.cantidad) + ' ' + esc(s.unidad || '') + '</td>' +
                        '<td>' + dinero(importe) + '</td>' +
                        '<td><button type="button" class="btn-delete" data-quitar-servicio="' + s.id + '" title="Quitar de la orden">' +
                        '<i class="fas fa-times"></i> Quitar</button></td>' +
                        '</tr>';
                }));
                tbody.innerHTML = filas.join('') +
                    '<tr class="inv-total"><td colspan="3"><strong>Total a precio de venta</strong></td><td><strong>' + dinero(total) + '</strong></td><td></td></tr>';
            }).catch(function (e) {
                tbody.innerHTML = '<tr><td colspan="5">' + esc(e.message) + '</td></tr>';
            });
        }

        // D-45: un artículo que controla serie pide el N/S de cada pieza
        function pedirSeries(a, cantidad) {
            var n = parseFloat(cantidad) || 0;
            if (n !== Math.round(n) || n < 1) { avisar(a.nombre + ' se registra por pieza: la cantidad debe ser entera.', 'error'); return Promise.resolve(null); }
            var caja = document.createElement('div');
            caja.className = 'alta-campos';
            for (var i = 1; i <= n; i++) {
                caja.insertAdjacentHTML('beforeend', '<label class="alta-campo"><span>N/S pieza ' + i + '</span><input name="serie" maxlength="60" autocomplete="off" required></label>');
            }
            return AppDialogo.mostrar({
                tipo: 'info', titulo: 'Números de serie: ' + a.nombre, contenido: caja,
                mensaje: 'Este artículo controla número de serie. Captura el de cada pieza instalada.',
                botones: [{ texto: 'Cancelar', valor: null, estilo: 'secundario' }, { texto: 'Registrar', valor: 'ok', estilo: 'primario' }]
            }).then(function (v) {
                if (v !== 'ok') return null;
                var series = Array.prototype.map.call(caja.querySelectorAll('input'), function (x) { return x.value.trim(); });
                if (series.some(function (s) { return !s; })) { avisar('Captura el número de serie de cada pieza.', 'error'); return pedirSeries(a, cantidad); }
                return series;
            });
        }

        form.addEventListener('submit', function (e) {
            e.preventDefault();
            var a = articulo(select.value);
            if (!a) return;
            if (Number(a.controla_serie) === 1 && !esServicio(a) && !(a.categoria === 'Equipo' && chkReemplazo.checked) && !form._series) {
                pedirSeries(a, inputCant.value).then(function (series) {
                    if (!series) return;
                    form._series = series;
                    form.requestSubmit ? form.requestSubmit() : form.dispatchEvent(new Event('submit', { cancelable: true }));
                });
                return;
            }
            var boton = form.querySelector('button');
            var datos = { incidencia_id: parseInt(incidenciaId, 10), articulo_id: parseInt(select.value, 10), cantidad: inputCant.value };
            var accion = 'consumir_incidencia', mensaje = 'Refacción registrada y descontada del inventario.';
            if (esServicio(a)) {
                accion = 'aplicar_servicio';
                mensaje = 'Servicio aplicado en la orden de servicio.';
            } else if (a.categoria === 'Equipo' && chkReemplazo.checked) {
                var serie = cont.querySelector('#inv-serie').value.trim();
                if (!serie) { avisar('Escribe el número de serie del equipo de reemplazo.', 'error'); cont.querySelector('#inv-serie').focus(); return; }
                datos.reemplazo = { numero_serie: serie, garantia: cont.querySelector('#inv-garantia').value };
                mensaje = 'Equipo de reemplazo registrado: se descontó del inventario y quedó en el padrón del cliente.';
            }
            if (form._series) { datos.series = form._series; form._series = null; }
            boton.disabled = true;
            api(accion, datos).then(function () {
                avisar(mensaje, 'success');
                inputCant.value = 1;
                cont.querySelector('#inv-serie').value = '';
                // U-24: tras aplicar, el formulario vuelve limpio (sin el borde rojo de un intento anterior)
                form.querySelectorAll('.f-tocado, [aria-invalid]').forEach(function (c) {
                    c.classList.remove('f-tocado'); c.removeAttribute('aria-invalid');
                    if (c.fError) { c.fError.remove(); c.fError = null; }
                });
                return Promise.all([cargarConsumos(), cargarOpciones()]);
            }).catch(function (err) {
                avisar(err.message, 'error');
            }).then(function () { boton.disabled = false; });
        });

        tbody.addEventListener('click', function (e) {
            var quitar = e.target.closest('[data-quitar-servicio]');
            if (quitar) {
                AppAviso.confirmar('El servicio se quita de esta orden de servicio. Queda en el historial.', { titulo: '¿Quitar el servicio?', boton: 'Quitar' }).then(function (ok) {
                    if (!ok) return;
                    quitar.disabled = true;
                    return api('quitar_servicio', { incidencia_id: parseInt(incidenciaId, 10), id: parseInt(quitar.getAttribute('data-quitar-servicio'), 10) })
                        .then(function () { avisar('Servicio quitado.', 'success'); return cargarConsumos(); })
                        .catch(function (err) { quitar.disabled = false; avisar(err.message, 'error'); });
                });
                return;
            }
            var btn = e.target.closest('[data-devolver]');
            if (!btn) return;
            AppAviso.confirmar('Todo lo registrado de este artículo en la orden de servicio regresa al inventario.', { titulo: '¿Devolver al almacén?', boton: 'Devolver' }).then(function (ok) {
                if (!ok) return;
                btn.disabled = true;
                return api('devolver_incidencia', {
                    incidencia_id: parseInt(incidenciaId, 10),
                    articulo_id: parseInt(btn.getAttribute('data-devolver'), 10)
                }).then(function () {
                    avisar('Artículo devuelto al inventario', 'success');
                    return Promise.all([cargarConsumos(), cargarOpciones()]);
                }, function (err) {
                    btn.disabled = false;
                    avisar(err.message, 'error');
                });
            });
        });

        // ------------------------------------------------------------ O-7: alta sin salir de la orden
        function campo(etiqueta, html) { return '<label class="alta-campo"><span>' + etiqueta + '</span>' + html + '</label>'; }

        function altaArticulo() {
            var nodo = document.createElement('div');
            nodo.className = 'inv-alta-modal';
            nodo.innerHTML =
                campo('Nombre', '<input name="nombre" maxlength="150" required>') +
                campo('SKU (clave)', '<input name="sku" maxlength="50" required placeholder="Ej. REF-0101">') +
                campo('Tipo', '<select name="categoria"><option>Refacción</option><option>Consumible</option><option>Equipo</option><option>Servicio</option><option>Mano de obra</option></select>') +
                campo('Unidad', '<input name="unidad" maxlength="20" value="pza">') +
                campo('Costo', '<input name="costo_unitario" type="number" min="0" step="0.01" value="0">') +
                campo('Precio de venta', '<input name="precio_venta" type="number" min="0" step="0.01" value="0">') +
                campo('Existencia inicial', '<input name="existencia_inicial" type="number" min="0" step="0.01" value="0">');
            var valor = function (n) { return nodo.querySelector('[name="' + n + '"]').value.trim(); };
            nodo.querySelector('[name="nombre"]').addEventListener('input', function () {
                var sku = nodo.querySelector('[name="sku"]');
                if (!sku.dataset.tocado) sku.value = this.value.toUpperCase().replace(/[^A-Z0-9]+/g, '-').replace(/^-|-$/g, '').slice(0, 20);
            });
            nodo.querySelector('[name="sku"]').addEventListener('input', function () { this.dataset.tocado = '1'; });
            AppDialogo.mostrar({
                tipo: 'info', titulo: 'Nuevo artículo del catálogo', contenido: nodo,
                botones: [{ texto: 'Cancelar', valor: null, estilo: 'secundario' }, { texto: 'Dar de alta', valor: 'si', estilo: 'primario' }]
            }).then(function (v) {
                if (v !== 'si') return;
                if (!valor('nombre') || !valor('sku')) { avisar('Escribe el nombre y la clave (SKU) del artículo.', 'error'); return; }
                return api('guardar', {
                    nombre: valor('nombre'), sku: valor('sku'), categoria: valor('categoria'), unidad: valor('unidad') || 'pza',
                    costo_unitario: valor('costo_unitario'), precio_venta: valor('precio_venta'), existencia_inicial: valor('existencia_inicial')
                }).then(function (j) {
                    avisar('Artículo dado de alta. Ya puedes aplicarlo en la orden.', 'success');
                    return cargarOpciones(j.id);
                }).catch(function (err) { avisar(err.message, 'error'); });
            });
        }

        function solicitarAlta() {
            var nodo = document.createElement('div');
            nodo.innerHTML = campo('¿Qué hay que dar de alta?', '<input name="nombre" maxlength="150" placeholder="Ej. Válvula de expansión R410A">') +
                campo('Detalle (opcional)', '<input name="notas" maxlength="200" placeholder="Cantidad, marca, modelo…">');
            AppDialogo.mostrar({
                tipo: 'info', titulo: 'Solicitar alta en el inventario', contenido: nodo,
                nota: 'La solicitud queda en las notas de esta orden de servicio para que la atienda quien administra el inventario.',
                botones: [{ texto: 'Cancelar', valor: null, estilo: 'secundario' }, { texto: 'Solicitar', valor: 'si', estilo: 'primario' }]
            }).then(function (v) {
                if (v !== 'si') return;
                return api('solicitar_articulo', {
                    incidencia_id: parseInt(incidenciaId, 10),
                    nombre: nodo.querySelector('[name="nombre"]').value, notas: nodo.querySelector('[name="notas"]').value
                }).then(function (j) {
                    avisar('Solicitud anotada en las notas de la orden de servicio.', 'success');
                    document.dispatchEvent(new CustomEvent('orden:notas-externas', { detail: { notas: j.notas, version: j.version } }));
                }).catch(function (err) { avisar(err.message, 'error'); });
            });
        }

        pAlta.addEventListener('click', function (e) {
            var b = e.target.closest('[data-alta]');
            if (!b) return;
            if (b.getAttribute('data-alta') === 'articulo') altaArticulo(); else solicitarAlta();
        });
        function pintarAlta() {
            pAlta.innerHTML = puedeInventario
                ? '¿No está en la lista? <button type="button" class="fin-link" data-alta="articulo"><i class="fas fa-plus"></i> Nuevo artículo</button>'
                : '¿No está en la lista? <button type="button" class="fin-link" data-alta="solicitud"><i class="fas fa-paper-plane"></i> Solicitar alta</button>';
        }
        if (window.appOnAuthReady) {
            window.appOnAuthReady(function (auth) {
                puedeInventario = (auth.modulos || []).indexOf('inventario') !== -1;
                pintarAlta();
            });
        } else pintarAlta();

        cargarOpciones();
        cargarConsumos();
    });
})();
