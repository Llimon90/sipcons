(function () {
    document.addEventListener('DOMContentLoaded', function () {
        var selectCliente = document.getElementById('cliente_id');
        var cuerpoLineas = document.getElementById('cuerpo-lineas');
        var btnAgregarLinea = document.getElementById('btn-agregar-linea');
        var formCotizacion = document.getElementById('form-cotizacion');
        var mensajeCotizacion = document.getElementById('mensaje-cotizacion');
        var cuerpoCotizaciones = document.getElementById('cuerpo-cotizaciones');
        var campoBusqueda = document.getElementById('campo-busqueda');
        var filtroEstatus = document.getElementById('filtro-estatus');

        function escapeHtml(v) {
            if (v === null || v === undefined) return '';
            return String(v).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
        }

        document.getElementById('fecha').value = new Date().toISOString().slice(0, 10);

        fetch('../backend/obtener-clientes.php', { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (clientes) {
                if (!Array.isArray(clientes)) return;
                clientes.forEach(function (c) {
                    var opt = document.createElement('option');
                    opt.value = c.id; opt.textContent = c.nombre;
                    selectCliente.appendChild(opt);
                });
            });

        function filaLinea() {
            var tr = document.createElement('tr');
            tr.innerHTML =
                '<td><input type="text" class="linea-descripcion" required></td>' +
                '<td><input type="number" class="linea-cantidad" min="0.01" step="0.01" value="1" style="width:80px;"></td>' +
                '<td><input type="number" class="linea-precio" min="0" step="0.01" value="0" style="width:100px;"></td>' +
                '<td class="linea-importe">0.00</td>' +
                '<td><button type="button" class="btn-quitar-linea">×</button></td>';
            cuerpoLineas.appendChild(tr);
            recalcular();
        }

        function recalcular() {
            var subtotal = 0;
            cuerpoLineas.querySelectorAll('tr').forEach(function (tr) {
                var cant = parseFloat(tr.querySelector('.linea-cantidad').value) || 0;
                var precio = parseFloat(tr.querySelector('.linea-precio').value) || 0;
                var importe = cant * precio;
                tr.querySelector('.linea-importe').textContent = importe.toFixed(2);
                subtotal += importe;
            });
            var iva = subtotal * 0.16;
            document.getElementById('total-subtotal').textContent = subtotal.toFixed(2);
            document.getElementById('total-iva').textContent = iva.toFixed(2);
            document.getElementById('total-total').textContent = (subtotal + iva).toFixed(2);
        }

        cuerpoLineas.addEventListener('input', recalcular);
        cuerpoLineas.addEventListener('click', function (e) {
            if (e.target.classList.contains('btn-quitar-linea')) {
                e.target.closest('tr').remove();
                recalcular();
            }
        });
        btnAgregarLinea.addEventListener('click', filaLinea);
        filaLinea();

        formCotizacion.addEventListener('submit', function (e) {
            e.preventDefault();
            var detalles = [];
            cuerpoLineas.querySelectorAll('tr').forEach(function (tr) {
                detalles.push({
                    descripcion: tr.querySelector('.linea-descripcion').value.trim(),
                    cantidad: tr.querySelector('.linea-cantidad').value,
                    precio_unitario: tr.querySelector('.linea-precio').value,
                });
            });
            var datos = {
                cliente_id: selectCliente.value,
                sucursal: document.getElementById('sucursal').value.trim(),
                fecha: document.getElementById('fecha').value,
                fecha_vigencia: document.getElementById('fecha_vigencia').value,
                notas: document.getElementById('notas').value.trim(),
                detalles: detalles,
            };
            mensajeCotizacion.textContent = 'Guardando...';
            fetch('../backend/guardar-cotizacion.php', {
                method: 'POST', credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(datos)
            }).then(function (r) { return r.json(); }).then(function (j) {
                mensajeCotizacion.textContent = j.message || (j.success ? ('Cotización ' + j.folio + ' guardada.') : 'Error.');
                mensajeCotizacion.style.color = j.success ? '#27ae60' : '#e74c3c';
                if (j.success) {
                    formCotizacion.reset();
                    document.getElementById('fecha').value = new Date().toISOString().slice(0, 10);
                    cuerpoLineas.innerHTML = ''; filaLinea();
                    cargarCotizaciones();
                }
            }).catch(function () { mensajeCotizacion.textContent = 'Sin conexión con el servidor.'; mensajeCotizacion.style.color = '#e74c3c'; });
        });

        function cargarCotizaciones() {
            var params = new URLSearchParams();
            if (campoBusqueda.value.trim()) params.set('q', campoBusqueda.value.trim());
            if (filtroEstatus.value) params.set('estatus', filtroEstatus.value);
            fetch('../backend/buscar-cotizaciones.php?' + params.toString(), { credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (cotizaciones) {
                    if (!Array.isArray(cotizaciones) || cotizaciones.length === 0) {
                        cuerpoCotizaciones.innerHTML = '<tr><td colspan="6">Sin cotizaciones.</td></tr>';
                        return;
                    }
                    cuerpoCotizaciones.innerHTML = cotizaciones.map(function (c) {
                        var acciones = '';
                        if (c.estatus === 'Pendiente') {
                            acciones += '<button type="button" class="btn-aprobar" data-id="' + c.id + '">Aprobar</button> ' +
                                        '<button type="button" class="btn-rechazar" data-id="' + c.id + '">Rechazar</button> ' +
                                        '<button type="button" class="btn-eliminar-cot" data-id="' + c.id + '">Borrar</button> ';
                        }
                        if (c.estatus === 'Pendiente' || c.estatus === 'Aprobada') {
                            acciones += '<button type="button" class="btn-convertir" data-id="' + c.id + '">Convertir a venta</button>';
                        }
                        return '<tr>' +
                            '<td>' + escapeHtml(c.folio) + '</td>' +
                            '<td>' + escapeHtml(c.cliente) + '</td>' +
                            '<td>' + escapeHtml(c.fecha) + '</td>' +
                            '<td>$' + parseFloat(c.total).toFixed(2) + '</td>' +
                            '<td>' + escapeHtml(c.estatus) + '</td>' +
                            '<td>' + acciones + '</td>' +
                        '</tr>';
                    }).join('');
                })
                .catch(function () { cuerpoCotizaciones.innerHTML = '<tr><td colspan="6">No se pudo cargar la lista.</td></tr>'; });
        }

        cuerpoCotizaciones.addEventListener('click', function (e) {
            var id = e.target.dataset ? e.target.dataset.id : null;
            if (!id) return;

            if (e.target.classList.contains('btn-aprobar') || e.target.classList.contains('btn-rechazar')) {
                var estatus = e.target.classList.contains('btn-aprobar') ? 'Aprobada' : 'Rechazada';
                fetch('../backend/actualiza-cotizacion.php', {
                    method: 'POST', credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ id: id, estatus: estatus })
                }).then(function (r) { return r.json(); }).then(function (j) { if (j.success) cargarCotizaciones(); else alert(j.message); });
            }

            if (e.target.classList.contains('btn-eliminar-cot')) {
                if (!confirm('¿Borrar esta cotización?')) return;
                fetch('../backend/eliminar-cotizacion.php?id=' + encodeURIComponent(id), { method: 'DELETE', credentials: 'same-origin' })
                    .then(function (r) { return r.json(); }).then(function (j) { if (j.success) cargarCotizaciones(); else alert(j.message); });
            }

            if (e.target.classList.contains('btn-convertir')) {
                var aCredito = confirm('¿Es una venta a crédito? Aceptar = crédito (se genera cuenta por cobrar). Cancelar = contado.');
                fetch('../backend/convertir-cotizacion-venta.php', {
                    method: 'POST', credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ cotizacion_id: id, a_credito: aCredito })
                }).then(function (r) { return r.json(); }).then(function (j) {
                    if (j.success) { alert('Venta ' + j.folio_venta + ' creada.' + (j.cxc_id ? ' Se generó cuenta por cobrar.' : '')); cargarCotizaciones(); }
                    else alert(j.message || 'No se pudo convertir.');
                });
            }
        });

        campoBusqueda.addEventListener('input', function () { clearTimeout(window.__cotTimer); window.__cotTimer = setTimeout(cargarCotizaciones, 300); });
        filtroEstatus.addEventListener('change', cargarCotizaciones);

        cargarCotizaciones();
    });
})();
