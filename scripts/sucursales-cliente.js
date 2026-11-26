// Sucursales del cliente en su perfil (public/perfil-cliente.html; D-44): nombre,
// dirección, contacto y teléfono, con sus equipos del padrón y sus órdenes activas.
// Edita quien tiene el módulo Clientes; los demás solo consultan.
(function () {
    var API = '../backend/sucursales.php';
    var clienteId = new URLSearchParams(location.search).get('id');
    var cont = document.getElementById('sucursales-cliente');
    if (!clienteId || !cont) return;
    var esc = function (v) {
        return String(v == null ? '' : v).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    };
    var lista = [];
    var puedeEditar = false;

    function api(accion, datos) {
        var op = { credentials: 'same-origin', cache: 'no-store' };
        var url = API + '?accion=' + accion;
        if (datos) { op.method = 'POST'; op.headers = { 'Content-Type': 'application/json' }; op.body = JSON.stringify(datos); }
        else url += '&todas=1&cliente_id=' + encodeURIComponent(clienteId);
        return AppRed.fetch(url, op).then(function (r) {
            return r.json().catch(function () { return {}; }).then(function (j) {
                if (!r.ok || !j.exito) throw new Error(j.mensaje || 'Error ' + r.status);
                return j;
            });
        });
    }

    function pintar() {
        if (!lista.length) {
            cont.innerHTML = '<p class="texto-suave">Sin sucursales. Se agregan aquí o al capturar una sucursal nueva en una orden, venta o cotización.</p>';
            return;
        }
        cont.innerHTML = '<div style="overflow-x:auto"><table class="tabla-ventas" style="width:100%;text-align:left"><thead><tr>' +
            '<th>Sucursal</th><th>Dirección</th><th>Contacto</th><th>Equipos</th><th>Órdenes activas</th><th></th></tr></thead><tbody>' +
            lista.map(function (s) {
                return '<tr' + (s.activo ? '' : ' style="opacity:.55"') + '><td><b>' + esc(s.nombre) + '</b>' + (s.activo ? '' : ' <small>(inactiva)</small>') + '</td>' +
                    '<td>' + esc(s.direccion || '') + '</td><td>' + esc([s.contacto, s.telefono].filter(Boolean).join(' · ')) + '</td>' +
                    '<td>' + s.equipos + '</td><td>' + s.ordenes_activas + ' <small class="texto-suave">de ' + s.ordenes + '</small></td>' +
                    '<td>' + (puedeEditar ? '<button type="button" class="btn-link-mini" data-editar="' + s.id + '"><i class="fas fa-edit"></i> Editar</button>' : '') + '</td></tr>';
            }).join('') + '</tbody></table></div>';
    }

    function cargar() {
        return api('listar').then(function (j) { lista = j.sucursales || []; pintar(); })
            .catch(function (e) { cont.innerHTML = '<p class="texto-suave">' + esc(e.message) + '</p>'; });
    }

    function editar(s) {
        s = s || { nombre: '', direccion: '', contacto: '', telefono: '', activo: 1 };
        var caja = document.createElement('div');
        caja.className = 'alta-campos';
        caja.innerHTML = [['nombre', 'Nombre de la sucursal'], ['direccion', 'Dirección'], ['contacto', 'Contacto'], ['telefono', 'Teléfono']].map(function (c) {
            return '<label class="alta-campo"><span>' + c[1] + '</span><input name="' + c[0] + '" value="' + esc(s[c[0]] || '') + '" maxlength="' + (c[0] === 'direccion' ? 255 : 150) + '"></label>';
        }).join('') +
            (s.id ? '<label class="alta-campo"><span>Estado</span><select name="activo"><option value="1"' + (Number(s.activo) ? ' selected' : '') + '>Activa</option>' +
                '<option value="0"' + (Number(s.activo) ? '' : ' selected') + '>Inactiva (ya no se sugiere)</option></select></label>' +
                '<p class="texto-suave" style="grid-column:1/-1">Si cambias el nombre, se actualiza en el padrón y en las órdenes activas; las cerradas, las ventas y las cotizaciones conservan el de entonces.</p>' : '');
        return AppDialogo.mostrar({
            tipo: 'info', titulo: s.id ? 'Editar sucursal' : 'Nueva sucursal', contenido: caja, ancho: true,
            botones: [{ texto: 'Cancelar', valor: null, estilo: 'secundario' }, { texto: 'Guardar', valor: 'ok', estilo: 'primario' }]
        }).then(function (v) {
            if (v !== 'ok') return;
            var d = { id: s.id || 0, cliente_id: Number(clienteId) };
            caja.querySelectorAll('input,select').forEach(function (i) { d[i.name] = i.value; });
            return api('guardar', d).then(function (j) { AppAviso.mostrar(j.mensaje, 'ok'); return cargar(); })
                .catch(function (e) { AppAviso.mostrar(e.message, 'error'); return editar(Object.assign({}, s, d)); });
        });
    }

    cont.addEventListener('click', function (e) {
        var b = e.target.closest('[data-editar]');
        if (b) editar(lista.find(function (s) { return String(s.id) === b.getAttribute('data-editar'); }));
    });
    var botonNueva = document.getElementById('btn-nueva-sucursal');
    if (botonNueva) botonNueva.addEventListener('click', function () { editar(); });

    // Edita quien tiene el módulo Clientes (el servidor lo vuelve a exigir)
    // La sesión ya la consultó auth-guard.js: no se repite la petición
    var alSesion = function (s) {
        puedeEditar = Array.isArray(s && s.modulos) && s.modulos.indexOf('clientes') !== -1;
        if (botonNueva) botonNueva.hidden = !puedeEditar;
        cargar();
    };
    if (window.appOnAuthReady) window.appOnAuthReady(alSesion); else alSesion(null);

    // En el modal del padrón: tipo, marca y modelo conocidos (D-41)
    if (window.AppSugEquipo) AppSugEquipo.vincular({ equipo: document.getElementById('ext-equipo'), marca: document.getElementById('ext-marca'), modelo: document.getElementById('ext-modelo') });

    // En el modal del padrón, la sucursal sugiere las del cliente
    var extCliente = document.getElementById('ext-cliente'), extSucursal = document.getElementById('ext-sucursal');
    if (window.AppSucursales && extCliente && extSucursal) {
        var vinculado = false;
        extSucursal.addEventListener('focus', function () {
            if (vinculado || !extCliente.value) return;
            vinculado = true;
            AppSucursales.vincular(extCliente, extSucursal);
        });
    }
})();
