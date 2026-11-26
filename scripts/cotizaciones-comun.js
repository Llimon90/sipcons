// Utilidades compartidas del módulo de cotizaciones: lista, detalle, sección
// en la incidencia, perfil del cliente y "ventas por registrar" en Ventas.
window.AppCotizaciones = (function () {
    var API = '../backend/cotizaciones.php';

    var ESTATUS = {
        borrador: { texto: 'Borrador', clase: 'etiqueta-neutral' },
        enviada: { texto: 'Enviada', clase: 'etiqueta-info' },
        aprobada: { texto: 'Aprobada', clase: 'etiqueta-ok' },
        rechazada: { texto: 'Rechazada', clase: 'etiqueta-error' },
        vencida: { texto: 'Vencida', clase: 'etiqueta-alerta' },
        convertida: { texto: 'Convertida en venta', clase: 'etiqueta-morado' }
    };

    function esc(v) {
        return String(v == null ? '' : v).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    function dinero(n, moneda) {
        var v = (parseFloat(n) || 0).toLocaleString('es-MX', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        return (moneda === 'USD' ? 'US$' : '$') + v;
    }

    function cantidad(n) {
        n = parseFloat(n) || 0;
        return n % 1 === 0 ? String(n) : n.toFixed(2).replace(/0$/, '');
    }

    function fecha(f) {
        if (!f) return '';
        var p = String(f).slice(0, 10).split('-');
        return p.length === 3 ? p[2] + '/' + p[1] + '/' + p[0] : f;
    }

    function etiqueta(c) {
        var e = ESTATUS[c.estatus] || { texto: c.estatus, clase: 'etiqueta-neutral' };
        var parcial = c.estatus !== 'borrador' && Number(c.aprobacion_parcial) === 1 ? ' parcial' : '';
        return '<span class="etiqueta ' + e.clase + '">' + esc(e.texto + parcial) + '</span>' +
            (c.reemplazada_por ? ' <span class="etiqueta etiqueta-neutral" title="Hay una versión más reciente">Sustituida</span>' : '');
    }

    /** GET si no hay cuerpo; POST JSON si lo hay. Rechaza con Error(mensaje) y .datos. */
    function api(accion, params, cuerpo) {
        var url = API + '?accion=' + encodeURIComponent(accion);
        Object.keys(params || {}).forEach(function (k) {
            if (params[k] !== '' && params[k] != null) url += '&' + encodeURIComponent(k) + '=' + encodeURIComponent(params[k]);
        });
        var opciones = { credentials: 'same-origin', cache: 'no-store', headers: { Accept: 'application/json' } };
        if (cuerpo !== undefined) {
            opciones.method = 'POST';
            opciones.headers['Content-Type'] = 'application/json';
            opciones.body = JSON.stringify(cuerpo);
        }
        // Bug 2026-11-25: una lectura que recibe 503 (servidor ocupado) se reintenta; un envío no
        return (window.AppRed ? window.AppRed.fetch(url, opciones) : fetch(url, opciones)).then(function (r) {
            return r.json().catch(function () { return {}; }).then(function (j) {
                if (!r.ok || j.exito === false) {
                    var e = new Error(j.mensaje || 'No se pudo completar la operación (' + r.status + ').');
                    e.datos = j;
                    e.status = r.status;
                    throw e;
                }
                return j;
            });
        });
    }

    function urlPdf(id) { return API + '?accion=pdf&id=' + encodeURIComponent(id); }

    /** Tabla compacta de cotizaciones (incidencia, perfil del cliente). */
    function tablaCompacta(lista, base) {
        base = base || '';
        if (!lista.length) return '<p class="texto-suave">Sin cotizaciones.</p>';
        return '<table class="tabla-ventas cot-tabla-compacta" style="width:100%;"><thead><tr><th>Folio</th><th>Fecha</th><th>Total</th><th>Estatus</th></tr></thead><tbody>' +
            lista.map(function (c) {
                return '<tr class="cot-fila" data-href="' + base + 'cotizacion.html?id=' + c.id + '">' +
                    '<td><a href="' + base + 'cotizacion.html?id=' + c.id + '"><strong>' + esc(c.folio) + '</strong></a></td>' +
                    '<td>' + fecha(c.fecha) + '</td><td>' + dinero(c.total, c.moneda) + '</td><td>' + etiqueta(c) + '</td></tr>';
            }).join('') + '</tbody></table>';
    }

    return { API: API, ESTATUS: ESTATUS, esc: esc, dinero: dinero, cantidad: cantidad, fecha: fecha, etiqueta: etiqueta, api: api, urlPdf: urlPdf, tablaCompacta: tablaCompacta };
})();
