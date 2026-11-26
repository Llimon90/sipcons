// Estado de cuenta de un cliente (public/estado-cuenta.html?cliente_id=N):
// cuentas con saldo y cobros de los últimos 90 días, listo para imprimir o
// guardar como PDF y mandarlo al cliente.
(function () {
    'use strict';
    var FORMAS = { transferencia: 'Transferencia', efectivo: 'Efectivo', tarjeta: 'Tarjeta', cheque: 'Cheque', otro: 'Otro' };
    function esc(v) {
        return String(v == null ? '' : v).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }
    function dinero(v) { return (parseFloat(v) || 0).toLocaleString('es-MX', { style: 'currency', currency: 'MXN' }); }
    function fecha(v) { var p = String(v || '').substr(0, 10).split('-'); return p.length === 3 ? p[2] + '/' + p[1] + '/' + p[0] : ''; }

    var id = new URLSearchParams(location.search).get('cliente_id');
    // D-54: enlace de solo lectura (vence en 7 días) para mandarlo por WhatsApp
    var btnCompartir = document.getElementById('ec-compartir');
    if (btnCompartir) btnCompartir.addEventListener('click', function () {
        var ventana = window.open('', '_blank');
        fetch('../backend/finanzas.php?accion=enlace_estado_cuenta&cliente_id=' + encodeURIComponent(id), { credentials: 'same-origin', cache: 'no-store' })
            .then(function (r) { return r.json().then(function (j) { if (!r.ok || !j.exito) throw new Error(j.mensaje || 'Error ' + r.status); return j; }); })
            .then(function (j) { if (ventana) { ventana.opener = null; ventana.location.href = j.whatsapp; } else location.href = j.whatsapp; })
            .catch(function (err) { if (ventana) ventana.close(); if (window.AppAviso) AppAviso.alerta(err.message); });
    });
    var cont = document.getElementById('ec-contenido');
    if (!id) { cont.innerHTML = '<p>Falta el cliente.</p>'; return; }

    fetch('../backend/finanzas.php?accion=estado_cuenta&cliente_id=' + encodeURIComponent(id), { credentials: 'same-origin', cache: 'no-store' })
        .then(function (r) { return r.json().then(function (j) { if (!r.ok || !j.exito) throw new Error(j.mensaje || 'Error ' + r.status); return j; }); })
        .then(function (j) {
            var e = j.estado, c = e.cliente, b = window.APP_BRAND || {};
            var empresa = j.empresa || b.empresa || '';
            document.title = 'Estado de cuenta · ' + c.nombre;
            cont.innerHTML =
                '<div class="ec-encabezado"><div>' + (b.logo_propio && b.logoUrl ? '<img src="' + esc(b.logoUrl) + '" alt="">' : '') +
                '<h1>' + esc(empresa) + '</h1></div><div><h1>Estado de cuenta</h1><p>Al ' + fecha(e.fecha) + '</p></div></div>' +
                '<div class="ec-cliente"><p><b>' + esc(c.nombre) + '</b></p>' + (c.rfc ? '<p>RFC: ' + esc(c.rfc) + '</p>' : '') +
                (c.direccion ? '<p>' + esc(c.direccion) + '</p>' : '') + '</div>' +
                '<div class="ec-totales"><div>Saldo total<b>' + dinero(e.saldo) + '</b></div>' +
                (e.vencido > 0 ? '<div>Vencido<b class="ec-vencida">' + dinero(e.vencido) + '</b></div>' : '') + '</div>' +
                '<h3>Cuentas pendientes</h3>' +
                (e.cuentas.length ? '<table class="ec-tabla"><thead><tr><th>Folio</th><th>Factura</th><th>Concepto</th><th>Fecha</th><th>Vence</th>' +
                    '<th class="num">Total</th><th class="num">Pagado</th><th class="num">Saldo</th></tr></thead><tbody>' +
                    e.cuentas.map(function (k) {
                        return '<tr><td>' + esc(k.folio) + '</td><td>' + esc(k.factura || '') + '</td><td>' + esc(k.concepto) + '</td><td>' + fecha(k.fecha) + '</td>' +
                            '<td' + (k.dias_vencida > 0 ? ' class="ec-vencida"' : '') + '>' + fecha(k.vencimiento) + (k.dias_vencida > 0 ? ' (' + k.dias_vencida + ' días)' : '') + '</td>' +
                            '<td class="num">' + dinero(k.total) + '</td><td class="num">' + dinero(k.cobrado) + '</td><td class="num"><b>' + dinero(k.saldo) + '</b></td></tr>';
                    }).join('') + '</tbody></table>'
                    : '<p>No hay saldos pendientes. ¡Gracias!</p>') +
                (e.cobros.length ? '<h3>Pagos recibidos (últimos 90 días)</h3><table class="ec-tabla"><thead><tr><th>Fecha</th><th>Cuenta</th><th>Factura</th><th>Forma</th><th>Referencia</th><th class="num">Monto</th></tr></thead><tbody>' +
                    e.cobros.map(function (p) {
                        return '<tr><td>' + fecha(p.fecha) + '</td><td>' + esc(p.folio) + '</td><td>' + esc(p.factura || '') + '</td><td>' + esc(FORMAS[p.forma] || p.forma) + '</td>' +
                            '<td>' + esc(p.referencia || '') + '</td><td class="num">' + dinero(p.monto) + '</td></tr>';
                    }).join('') + '</tbody></table>' : '') +
                '<p class="ec-pie">Si tienes alguna duda sobre este estado de cuenta, comunícate con ' + esc(empresa || 'nosotros') + '.</p>';
        })
        .catch(function (err) { cont.innerHTML = '<p>' + esc(err.message) + '</p>'; });
})();
