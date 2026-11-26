(function () {
    document.addEventListener('DOMContentLoaded', function () {
        var selectCliente = document.getElementById('cliente_id');
        var formCxc = document.getElementById('form-cxc');
        var mensajeCxc = document.getElementById('mensaje-cxc');
        var cuerpoCxc = document.getElementById('cuerpo-cxc');
        var filtroEstatus = document.getElementById('filtro-estatus');
        var seccionPago = document.getElementById('seccion-pago');
        var formPago = document.getElementById('form-pago');
        var pagoCxcId = document.getElementById('pago-cxc-id');
        var cxcClientePago = document.getElementById('cxc-cliente-pago');
        var historialPagos = document.getElementById('historial-pagos');

        function escapeHtml(v) {
            if (v === null || v === undefined) return '';
            return String(v).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
        }

        document.getElementById('fecha_emision').value = new Date().toISOString().slice(0, 10);
        document.getElementById('pago-fecha').value = new Date().toISOString().slice(0, 10);

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

        formCxc.addEventListener('submit', function (e) {
            e.preventDefault();
            var datos = {
                cliente_id: selectCliente.value,
                sucursal: document.getElementById('sucursal').value.trim(),
                concepto: document.getElementById('concepto').value.trim(),
                monto_total: document.getElementById('monto_total').value,
                fecha_emision: document.getElementById('fecha_emision').value,
                dias_vencimiento: document.getElementById('dias_vencimiento').value,
            };
            mensajeCxc.textContent = 'Guardando...';
            fetch('../backend/guardar-cxc.php', {
                method: 'POST', credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(datos)
            }).then(function (r) { return r.json(); }).then(function (j) {
                mensajeCxc.textContent = j.message || (j.success ? 'Cuenta por cobrar creada.' : 'Error.');
                mensajeCxc.style.color = j.success ? '#27ae60' : '#e74c3c';
                if (j.success) { formCxc.reset(); document.getElementById('fecha_emision').value = new Date().toISOString().slice(0, 10); document.getElementById('dias_vencimiento').value = 30; cargarCxc(); }
            }).catch(function () { mensajeCxc.textContent = 'Sin conexión con el servidor.'; mensajeCxc.style.color = '#e74c3c'; });
        });

        function cargarCxc() {
            var params = new URLSearchParams();
            if (filtroEstatus.value) params.set('estatus', filtroEstatus.value);
            fetch('../backend/obtener-cuentas-por-cobrar.php?' + params.toString(), { credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (cuentas) {
                    if (!Array.isArray(cuentas) || cuentas.length === 0) {
                        cuerpoCxc.innerHTML = '<tr><td colspan="8">Sin cuentas por cobrar.</td></tr>';
                        return;
                    }
                    cuerpoCxc.innerHTML = cuentas.map(function (c) {
                        var vencida = c.estatus === 'Vencida';
                        var acciones = (c.estatus !== 'Pagada')
                            ? '<button type="button" class="btn-pagar" data-id="' + c.id + '" data-cliente="' + escapeHtml(c.cliente) + '">Registrar pago</button>'
                            : '';
                        return '<tr' + (vencida ? ' style="background:#fdecea;"' : '') + '>' +
                            '<td>' + escapeHtml(c.cliente) + '</td>' +
                            '<td>' + escapeHtml(c.sucursal) + '</td>' +
                            '<td>' + escapeHtml(c.concepto) + '</td>' +
                            '<td>$' + parseFloat(c.monto_total).toFixed(2) + '</td>' +
                            '<td>$' + parseFloat(c.saldo_pendiente).toFixed(2) + '</td>' +
                            '<td>' + escapeHtml(c.fecha_vencimiento) + '</td>' +
                            '<td>' + escapeHtml(c.estatus) + '</td>' +
                            '<td>' + acciones + '</td>' +
                        '</tr>';
                    }).join('');
                })
                .catch(function () { cuerpoCxc.innerHTML = '<tr><td colspan="8">No se pudo cargar la lista.</td></tr>'; });
        }

        cuerpoCxc.addEventListener('click', function (e) {
            var btn = e.target.closest('.btn-pagar');
            if (!btn) return;
            pagoCxcId.value = btn.dataset.id;
            cxcClientePago.textContent = btn.dataset.cliente;
            seccionPago.style.display = '';
            cargarPagos(btn.dataset.id);
            seccionPago.scrollIntoView({ behavior: 'smooth' });
        });

        function cargarPagos(cxcId) {
            fetch('../backend/detalle-cxc.php?id=' + encodeURIComponent(cxcId), { credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (j) {
                    if (!j.success) { historialPagos.innerHTML = ''; return; }
                    var pagos = j.cxc.pagos || [];
                    if (pagos.length === 0) { historialPagos.innerHTML = '<p>Sin pagos registrados.</p>'; return; }
                    historialPagos.innerHTML = '<table class="tabla-ventas" style="width:100%;"><thead><tr><th>Fecha</th><th>Monto</th><th>Método</th><th>Referencia</th><th>Usuario</th></tr></thead><tbody>' +
                        pagos.map(function (p) {
                            return '<tr><td>' + escapeHtml(p.fecha) + '</td><td>$' + parseFloat(p.monto).toFixed(2) + '</td><td>' + escapeHtml(p.metodo_pago) + '</td><td>' + escapeHtml(p.referencia) + '</td><td>' + escapeHtml(p.usuario_nombre) + '</td></tr>';
                        }).join('') + '</tbody></table>';
                });
        }

        formPago.addEventListener('submit', function (e) {
            e.preventDefault();
            var datos = {
                cxc_id: pagoCxcId.value,
                monto: document.getElementById('pago-monto').value,
                fecha: document.getElementById('pago-fecha').value,
                metodo_pago: document.getElementById('pago-metodo').value.trim(),
                referencia: document.getElementById('pago-referencia').value.trim(),
            };
            fetch('../backend/registrar-pago-cxc.php', {
                method: 'POST', credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(datos)
            }).then(function (r) { return r.json(); }).then(function (j) {
                if (j.success) { document.getElementById('pago-monto').value = ''; cargarPagos(datos.cxc_id); cargarCxc(); }
                else alert(j.message || 'No se pudo registrar el pago.');
            });
        });

        filtroEstatus.addEventListener('change', cargarCxc);
        cargarCxc();
    });
})();
