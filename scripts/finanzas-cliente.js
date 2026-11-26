// Finanzas en el perfil del cliente (public/perfil-cliente.html; D-48): saldo, vencido,
// crédito (días y límite), cuentas abiertas y notas de crédito. Solo con el permiso de
// Finanzas: la sección no se muestra sin él y el servidor lo vuelve a exigir.
(function () {
    var API = '../backend/finanzas.php';
    var clienteId = new URLSearchParams(location.search).get('id');
    var seccion = document.getElementById('seccion-finanzas');
    var cont = document.getElementById('finanzas-cliente');
    if (!clienteId || !seccion || !cont) return;
    var esc = function (v) {
        return String(v == null ? '' : v).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    };
    var dinero = function (n) { return (parseFloat(n) || 0).toLocaleString('es-MX', { style: 'currency', currency: 'MXN' }); };
    var fecha = function (f) { var p = String(f || '').slice(0, 10).split('-'); return p.length === 3 ? p[2] + '/' + p[1] + '/' + p[0] : ''; };
    var estado = null;

    function api(accion, params, cuerpo) {
        var op = { credentials: 'same-origin', cache: 'no-store' };
        if (cuerpo) { op.method = 'POST'; op.headers = { 'Content-Type': 'application/json' }; op.body = JSON.stringify(cuerpo); }
        return AppRed.fetch(API + '?accion=' + accion + (params ? '&' + new URLSearchParams(params) : ''), op).then(function (r) {
            return r.json().catch(function () { return {}; }).then(function (j) {
                if (!r.ok || j.exito === false) throw new Error(j.mensaje || 'Error ' + r.status);
                return j;
            });
        });
    }

    function pintar(e) {
        estado = e;
        var c = e.cliente;
        var limite = c.limite_credito == null ? 'Sin límite' : dinero(c.limite_credito);
        var html = (e.aviso_credito ? '<p class="etiqueta etiqueta-alerta" style="display:block;padding:8px 12px"><i class="fas fa-exclamation-triangle"></i> ' + esc(e.aviso_credito) + '</p>' : '') +
            '<div class="fin-cli-kpis" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:10px;margin:8px 0 14px">' +
            [['Saldo por cobrar', dinero(e.saldo)], ['Vencido', dinero(e.vencido)], ['Días de crédito', (c.dias_credito || 0) + ' días'], ['Límite de crédito', limite]].map(function (k) {
                return '<div style="border:1px solid var(--borde-suave,#e3e8ee);border-radius:10px;padding:10px 12px"><small class="texto-suave">' + k[0] + '</small><div style="font-size:1.15rem;font-weight:700">' + k[1] + '</div></div>';
            }).join('') + '</div>';
        html += '<h4 style="margin:6px 0">Cuentas abiertas</h4>' + (e.cuentas.length
            ? '<div style="overflow-x:auto"><table class="tabla-ventas" style="width:100%;text-align:left"><thead><tr><th>Folio</th><th>Concepto</th><th>Vence</th><th>Total</th><th>Saldo</th></tr></thead><tbody>' +
              e.cuentas.map(function (k) {
                  return '<tr><td><a href="finanzas.html?cuenta=' + encodeURIComponent(k.id) + '">' + esc(k.folio) + '</a></td><td>' + esc(k.concepto) + '</td>' +
                      '<td>' + fecha(k.vencimiento) + (k.dias_vencida > 0 ? ' <span style="color:#b3261e">(' + k.dias_vencida + ' d)</span>' : '') + '</td>' +
                      '<td>' + dinero(k.total) + '</td><td><b>' + dinero(k.saldo) + '</b></td></tr>';
              }).join('') + '</tbody></table></div>'
            : '<p class="texto-suave">Sin cuentas por cobrar abiertas.</p>');
        if (e.notas_credito && e.notas_credito.length) {
            html += '<h4 style="margin:14px 0 6px">Notas de crédito</h4><div style="overflow-x:auto"><table class="tabla-ventas" style="width:100%;text-align:left"><thead><tr><th>Fecha</th><th>Cuenta</th><th>Monto</th><th>Motivo</th><th>Folio o UUID</th></tr></thead><tbody>' +
                e.notas_credito.map(function (n) {
                    var cancelada = String(n.cancelado) === '1';
                    return '<tr' + (cancelada ? ' style="opacity:.55;text-decoration:line-through"' : '') + '><td>' + fecha(n.fecha) + '</td><td>' + esc(n.folio) + '</td><td>' + dinero(n.monto) + '</td>' +
                        '<td>' + esc(n.motivo || '') + '</td><td>' + esc(n.referencia || '') + '</td></tr>';
                }).join('') + '</tbody></table></div>';
        }
        cont.innerHTML = html;
    }

    function cargar() {
        return api('estado_cuenta', { cliente_id: clienteId }).then(function (j) { pintar(j.estado); })
            .catch(function (err) { cont.innerHTML = '<p class="texto-suave">' + esc(err.message) + '</p>'; });
    }

    function editarCredito() {
        var c = estado.cliente;
        var caja = document.createElement('div');
        caja.className = 'alta-campos';
        caja.innerHTML = '<label class="alta-campo"><span>Días de crédito</span><input name="dias_credito" type="number" min="0" max="365" value="' + esc(c.dias_credito || 0) + '"></label>' +
            '<label class="alta-campo"><span>Límite de crédito (vacío = sin límite)</span><input name="limite_credito" type="number" min="0" step="0.01" value="' + esc(c.limite_credito == null ? '' : c.limite_credito) + '"></label>' +
            '<p class="texto-suave" style="grid-column:1/-1">Si el saldo por cobrar rebasa el límite, el sistema avisa al crear cuentas y cotizaciones; no las bloquea.</p>';
        AppDialogo.mostrar({ tipo: 'info', titulo: 'Crédito de ' + c.nombre, contenido: caja,
            botones: [{ texto: 'Cancelar', valor: null, estilo: 'secundario' }, { texto: 'Guardar', valor: 'ok', estilo: 'primario' }]
        }).then(function (v) {
            if (v !== 'ok') return;
            return api('guardar_credito', null, { cliente_id: Number(clienteId), dias_credito: caja.querySelector('[name=dias_credito]').value, limite_credito: caja.querySelector('[name=limite_credito]').value })
                .then(function (j) { AppAviso.mostrar(j.mensaje, 'ok'); return cargar(); });
        }).catch(function (err) { AppAviso.mostrar(err.message, 'error'); });
    }

    // La sesión ya la consultó auth-guard.js: no se repite la petición
    var alSesion = function (s) {
        if (!(Array.isArray(s && s.modulos) && s.modulos.indexOf('finanzas') !== -1)) return;
        seccion.hidden = false;
        document.getElementById('btn-credito-cliente').addEventListener('click', editarCredito);
        document.getElementById('btn-estado-cuenta-cliente').href = 'estado-cuenta.html?cliente_id=' + encodeURIComponent(clienteId);
        // Después de lo principal del perfil, para no competir por conexiones
        setTimeout(cargar, 400);
    };
    if (window.appOnAuthReady) window.appOnAuthReady(alSesion);
})();
