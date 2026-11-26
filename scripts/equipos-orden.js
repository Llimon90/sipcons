// Sección "Equipos atendidos" del detalle de la orden (public/detalle.html; D-41).
// Una orden atiende varios equipos. Si la serie no está en el padrón del cliente, un modal
// pide equipo, marca y modelo y la da de alta (también el Técnico, solo desde la orden).
// Independiente de detalle.js: usa el ?id= de la URL y #equipos-orden.
(function () {
    var API = '../backend/equipos_orden.php';
    var id = new URLSearchParams(location.search).get('id');
    var cont = document.getElementById('equipos-orden');
    if (!id || !cont) return;

    function esc(v) {
        return String(v == null ? '' : v).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }
    function api(accion, datos) {
        var op = { credentials: 'same-origin', cache: 'no-store' };
        var url = API + '?accion=' + accion;
        if (datos) { op.method = 'POST'; op.headers = { 'Content-Type': 'application/json' }; op.body = JSON.stringify(datos); }
        else url += '&incidencia_id=' + encodeURIComponent(id);
        return AppRed.fetch(url, op).then(function (r) {
            return r.json().catch(function () { return {}; }).then(function (j) {
                if (!r.ok || !j.exito) { var e = new Error(j.mensaje || 'Error ' + r.status); e.motivo = j.motivo; e.http = r.status; throw e; }
                return j;
            });
        });
    }
    function aviso(texto, tipo) { if (window.AppAviso) AppAviso.mostrar(texto, tipo || 'ok'); }

    function pintar(equipos) {
        var filas = equipos.length ? '<table class="tabla-ventas inv-consumo-tabla"><thead><tr><th>Serie</th><th>Equipo</th><th>Sucursal</th><th></th></tr></thead><tbody>' +
            equipos.map(function (e) {
                var desc = [e.equipo, e.marca, e.modelo].filter(Boolean).join(' · ');
                return '<tr><td><b>' + esc(e.numero_serie) + '</b></td><td>' + (desc ? esc(desc)
                        : '<button type="button" class="btn-link-mini" data-alta="' + esc(e.numero_serie) + '"><i class="fas fa-plus-circle"></i> Dar de alta en el padrón</button>') + '</td>' +
                    '<td>' + esc(e.sucursal || '') + '</td>' +
                    '<td><button type="button" class="btn-link-mini" data-quitar="' + e.id + '" title="Quitar de la orden (sigue en el padrón)"><i class="fas fa-times"></i> Quitar</button></td></tr>';
            }).join('') + '</tbody></table>' : '<p class="texto-suave">Sin equipos registrados en esta orden.</p>';
        cont.innerHTML = '<form class="inv-consumo-form" id="eqo-form" novalidate>' +
            '<div class="inv-consumo-campo inv-consumo-articulo"><label for="eqo-serie">Número de serie</label>' +
            '<input id="eqo-serie" list="eqo-series" autocomplete="off" maxlength="100" placeholder="Del padrón del cliente o una serie nueva"><datalist id="eqo-series"></datalist></div>' +
            '<button type="submit" class="btn-primary"><i class="fas fa-plus"></i> Agregar equipo</button></form>' + filas;
        cargarSeries();
    }

    function cargarSeries() {
        var cliente = document.getElementById('cliente');
        var lista = document.getElementById('eqo-series');
        if (!cliente || !lista || !cliente.value.trim()) return;
        fetch('../backend/obtener_equipos_cliente.php?cliente=' + encodeURIComponent(cliente.value.trim()), { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (equipos) {
                if (!Array.isArray(equipos)) return;
                lista.innerHTML = equipos.filter(function (e) { return e.numero_serie; }).map(function (e) {
                    return '<option value="' + esc(e.numero_serie) + '" label="' + esc([e.equipo, e.marca, e.modelo].filter(Boolean).join(' ')) + '"></option>';
                }).join('');
            }).catch(function () {});
    }

    // Modal: datos del equipo nuevo para el padrón
    function pedirDatosEquipo(serie) {
        var caja = document.createElement('div');
        caja.className = 'alta-campos';
        caja.innerHTML = ['modelo|Modelo|Si es conocido, llena el tipo y la marca', 'marca|Marca|', 'equipo|Equipo (tipo)|Ej. Báscula, Minisplit'].map(function (c) {
            var p = c.split('|');
            return '<label class="alta-campo"><span>' + p[1] + '</span><input name="' + p[0] + '" maxlength="100" placeholder="' + esc(p[2]) + '" required></label>';
        }).join('') + '<p class="texto-suave">Se da de alta en el padrón del cliente, en la sucursal de esta orden.</p>';
        if (window.AppSugEquipo) AppSugEquipo.vincular({ equipo: caja.querySelector('[name=equipo]'), marca: caja.querySelector('[name=marca]'), modelo: caja.querySelector('[name=modelo]') });
        return AppDialogo.mostrar({
            tipo: 'info', titulo: 'Equipo nuevo: ' + serie,
            mensaje: 'Esta serie no está en el padrón del cliente. Captura sus datos para darla de alta.',
            contenido: caja,
            botones: [{ texto: 'Cancelar', valor: null, estilo: 'secundario' }, { texto: 'Dar de alta y agregar', valor: 'ok', estilo: 'primario' }]
        }).then(function (v) {
            if (v !== 'ok') return null;
            var d = {};
            caja.querySelectorAll('input').forEach(function (i) { d[i.name] = i.value.trim(); });
            if (!d.equipo || !d.marca || !d.modelo) { aviso('Captura equipo, marca y modelo.', 'error'); return pedirDatosEquipo(serie); }
            return d;
        });
    }

    function agregar(serie, extra) {
        return api('agregar', Object.assign({ incidencia_id: Number(id), numero_serie: serie }, extra || {})).then(function (j) {
            aviso(j.mensaje);
            pintar(j.equipos || []);
        }).catch(function (e) {
            if (e.motivo === 'equipo_nuevo') {
                return pedirDatosEquipo(serie).then(function (d) { if (d) return agregar(serie, d); });
            }
            aviso(e.message, 'error');
        });
    }

    cont.addEventListener('submit', function (e) {
        if (e.target.id !== 'eqo-form') return;
        e.preventDefault();
        var serie = document.getElementById('eqo-serie').value.trim();
        if (!serie) { aviso('Escribe el número de serie.', 'error'); return; }
        agregar(serie);
    });
    cont.addEventListener('click', function (e) {
        var alta = e.target.closest('[data-alta]');
        if (alta) { agregar(alta.getAttribute('data-alta')); return; }
        var b = e.target.closest('[data-quitar]');
        if (!b) return;
        api('quitar', { incidencia_id: Number(id), id: Number(b.getAttribute('data-quitar')) })
            .then(function (j) { aviso(j.mensaje); pintar(j.equipos || []); })
            .catch(function (err) { aviso(err.message, 'error'); });
    });

    function cargar() {
        api('listar').then(function (j) { pintar(j.equipos || []); }).catch(function (e) {
            if (e.http === 404) { document.getElementById('seccion-equipos').hidden = true; return; }
            cont.innerHTML = '<p class="texto-suave">' + esc(e.message) + '</p>';
        });
    }
    // Después de que detalle.js arma el formulario (para leer el cliente)
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', function () { setTimeout(cargar, 300); });
    else setTimeout(cargar, 300);
})();
