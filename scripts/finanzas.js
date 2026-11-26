(function () {
    document.addEventListener('DOMContentLoaded', function () {
        var selectCliente = document.getElementById('cliente_id');
        var fechaInicio = document.getElementById('fecha_inicio');
        var fechaFin = document.getElementById('fecha_fin');
        var btnFiltrar = document.getElementById('btn-filtrar');
        var cuerpoDesglose = document.getElementById('cuerpo-desglose');

        function escapeHtml(v) {
            if (v === null || v === undefined) return '';
            return String(v).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
        }

        var hoy = new Date();
        fechaFin.value = hoy.toISOString().slice(0, 10);
        fechaInicio.value = new Date(hoy.getFullYear(), hoy.getMonth(), 1).toISOString().slice(0, 10);

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

        function cargarResumen() {
            var params = new URLSearchParams();
            if (selectCliente.value) params.set('cliente_id', selectCliente.value);
            params.set('fecha_inicio', fechaInicio.value);
            params.set('fecha_fin', fechaFin.value);

            fetch('../backend/finanzas-resumen.php?' + params.toString(), { credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    document.getElementById('t-ventas').textContent = data.ventas_totales;
                    document.getElementById('t-por-cobrar').textContent = '$' + data.cuentas_por_cobrar.por_cobrar.toFixed(2);
                    document.getElementById('t-vencido').textContent = '$' + data.cuentas_por_cobrar.vencido.toFixed(2);
                    document.getElementById('t-cobrado').textContent = '$' + data.cuentas_por_cobrar.cobrado_periodo.toFixed(2);
                    document.getElementById('t-conversion').textContent = data.cotizaciones.tasa_conversion_pct + '%';
                    document.getElementById('t-inventario').textContent = '$' + data.inventario.valor_total.toFixed(2);

                    var ventasPorLlave = {};
                    (data.desglose_cliente_sucursal.ventas || []).forEach(function (v) {
                        ventasPorLlave[v.cliente + '|' + (v.sucursal || '')] = v.ventas;
                    });
                    var cxcPorLlave = {};
                    (data.desglose_cliente_sucursal.cuentas_por_cobrar || []).forEach(function (c) {
                        cxcPorLlave[c.cliente + '|' + (c.sucursal || '')] = c.saldo_pendiente;
                    });
                    var llaves = Object.keys(Object.assign({}, ventasPorLlave, cxcPorLlave));
                    if (llaves.length === 0) {
                        cuerpoDesglose.innerHTML = '<tr><td colspan="4">Sin datos en este periodo.</td></tr>';
                        return;
                    }
                    cuerpoDesglose.innerHTML = llaves.map(function (llave) {
                        var partes = llave.split('|');
                        return '<tr>' +
                            '<td>' + escapeHtml(partes[0]) + '</td>' +
                            '<td>' + escapeHtml(partes[1] || '—') + '</td>' +
                            '<td>' + (ventasPorLlave[llave] || 0) + '</td>' +
                            '<td>$' + parseFloat(cxcPorLlave[llave] || 0).toFixed(2) + '</td>' +
                        '</tr>';
                    }).join('');
                })
                .catch(function () { cuerpoDesglose.innerHTML = '<tr><td colspan="4">No se pudo cargar el resumen.</td></tr>'; });
        }

        btnFiltrar.addEventListener('click', cargarResumen);
        cargarResumen();
    });
})();
