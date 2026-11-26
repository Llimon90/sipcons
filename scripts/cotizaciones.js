// public/cotizaciones.html - lista de cotizaciones con indicadores y filtros,
// ventas por registrar y pestaña "Servicios" (catálogo sin existencias).
(function () {
    var C = window.AppCotizaciones;
    var esc = C.esc;
    var config = null;

    function $(id) { return document.getElementById(id); }

    // Errores en diálogo (hay que leerlos); confirmaciones con el aviso estándar
    function avisar(texto, tipo) {
        if (tipo === 'error') AppDialogo.mostrar({ tipo: 'error', titulo: 'No se pudo completar', mensaje: texto });
        else AppAviso.mostrar(texto, 'ok');
    }

    // ---------- Indicadores ----------
    function cargarResumen() {
        return C.api('resumen').then(function (j) {
            var k = j.kpi;
            $('kpi-borradores').textContent = k.borradores;
            $('kpi-enviadas').textContent = k.enviadas;
            $('kpi-por-vencer').textContent = k.por_vencer;
            $('kpi-aprobadas').textContent = k.aprobadas_mes;
            $('kpi-aprobadas-monto').textContent = 'Aprobadas este mes · ' + C.dinero(k.monto_aprobado_mes);
        });
    }

    // ---------- Ventas por registrar ----------
    function cargarVentasPendientes() {
        return C.api('ventas_por_registrar').then(function (j) {
            var aviso = $('aviso-ventas');
            if (!j.ventas.length) { aviso.hidden = true; return; }
            aviso.hidden = false;
            aviso.innerHTML = '<div class="cot-aviso-titulo"><i class="fas fa-cart-arrow-down"></i> ' + j.ventas.length +
                (j.ventas.length === 1 ? ' venta por registrar' : ' ventas por registrar') + ' de cotizaciones aprobadas</div><ul>' +
                j.ventas.map(function (v) {
                    return '<li><span><strong>' + esc(v.folio) + '</strong> · ' + esc(v.cliente_nombre) + ' — ' + C.cantidad(v.cantidad) + ' × ' +
                        esc(v.descripcion) + (v.modelo ? ' (' + esc(v.modelo) + ')' : '') + '</span>' +
                        '<a class="btn-secundario" href="ventas.html?cotizacion_venta=' + v.id + '"><i class="fas fa-file-signature"></i> Registrar venta</a></li>';
                }).join('') + '</ul>';
        }).catch(function () { $('aviso-ventas').hidden = true; });
    }

    // ---------- Lista ----------
    function filtros() {
        return {
            buscar: $('f-buscar').value.trim(),
            cliente_id: $('f-cliente').value,
            estatus: $('f-estatus').value,
            desde: $('f-desde').value,
            hasta: $('f-hasta').value,
            por_vencer: $('f-por-vencer').checked ? 1 : ''
        };
    }

    function cargarLista() {
        return C.api('listar', filtros()).then(function (j) {
            var tbody = $('tabla-cotizaciones');
            if (!j.cotizaciones.length) {
                tbody.innerHTML = '<tr><td colspan="6" class="inv-vacio">No hay cotizaciones que coincidan con los filtros.</td></tr>';
                return;
            }
            var hoy = config ? config.hoy : '';
            tbody.innerHTML = j.cotizaciones.map(function (c) {
                var porVencer = c.estatus === 'enviada' && c.vigencia_hasta >= hoy &&
                    (new Date(c.vigencia_hasta) - new Date(hoy)) / 86400000 <= 3;
                return '<tr class="cot-fila" data-id="' + c.id + '">' +
                    '<td><a href="cotizacion.html?id=' + c.id + '"><strong>' + esc(c.folio) + '</strong></a>' +
                    (c.incidencia_id ? '<span class="inv-sub"><i class="fas fa-tools"></i> De una orden de servicio</span>' : '') + '</td>' +
                    '<td>' + esc(c.cliente_nombre) + (c.sucursal ? '<span class="inv-sub">' + esc(c.sucursal) + '</span>' : '') + '</td>' +
                    '<td>' + C.fecha(c.fecha) + '</td>' +
                    '<td' + (porVencer ? ' class="cot-por-vencer"' : '') + '>' + C.fecha(c.vigencia_hasta) + '</td>' +
                    '<td class="inv-num"><strong>' + C.dinero(c.total, c.moneda) + '</strong>' + (c.moneda === 'USD' ? ' <span class="inv-sub">USD</span>' : '') + '</td>' +
                    '<td>' + C.etiqueta(c) + '</td></tr>';
            }).join('');
        }).catch(function (e) {
            $('tabla-cotizaciones').innerHTML = '<tr><td colspan="6" class="inv-vacio">' + esc(e.message) + '</td></tr>';
        });
    }

    $('tabla-cotizaciones').addEventListener('click', function (e) {
        var fila = e.target.closest('tr[data-id]');
        if (fila && !e.target.closest('a')) location.href = 'cotizacion.html?id=' + fila.getAttribute('data-id');
    });

    document.querySelectorAll('[data-filtro-estatus]').forEach(function (b) {
        b.addEventListener('click', function () {
            $('f-estatus').value = $('f-estatus').value === b.getAttribute('data-filtro-estatus') ? '' : b.getAttribute('data-filtro-estatus');
            $('f-por-vencer').checked = false;
            cargarLista();
        });
    });
    document.querySelector('[data-por-vencer]').addEventListener('click', function () {
        $('f-por-vencer').checked = !$('f-por-vencer').checked;
        $('f-estatus').value = '';
        cargarLista();
    });

    // ---------- Pestañas ----------
    function mostrarPestana(nombre) {
        document.querySelectorAll('.cot-pestanas button').forEach(function (b) {
            var activa = b.getAttribute('data-pestana') === nombre;
            b.classList.toggle('activa', activa);
            b.setAttribute('aria-selected', activa);
        });
        $('pestana-lista').hidden = nombre !== 'lista';
        $('pestana-servicios').hidden = nombre !== 'servicios';
        if (nombre === 'servicios') cargarServicios();
        try { history.replaceState(null, '', nombre === 'servicios' ? '#servicios' : location.pathname); } catch (e) { /* nada */ }
    }
    document.querySelectorAll('.cot-pestanas button').forEach(function (b) {
        b.addEventListener('click', function () { mostrarPestana(b.getAttribute('data-pestana')); });
    });

    // ---------- Servicios (inventario.php, artículos sin existencias) ----------
    var servicios = [];
    var puedeEditarCatalogo = false;

    function apiInventario(accion, params, cuerpo) {
        var url = '../backend/inventario.php?accion=' + accion;
        Object.keys(params || {}).forEach(function (k) { if (params[k] !== '') url += '&' + k + '=' + encodeURIComponent(params[k]); });
        var op = { credentials: 'same-origin', cache: 'no-store' };
        if (cuerpo) { op.method = 'POST'; op.headers = { 'Content-Type': 'application/json' }; op.body = JSON.stringify(cuerpo); }
        return fetch(url, op).then(function (r) {
            return r.json().catch(function () { return {}; }).then(function (j) {
                if (!r.ok || j.exito === false) throw new Error(j.mensaje || 'No se pudo completar la operación.');
                return j;
            });
        });
    }

    function cargarServicios() {
        var verMargen = config && config.permisos.ve_margen;
        return apiInventario('listar', { tipo: 'servicios', buscar: $('s-buscar').value.trim() }).then(function (j) {
            puedeEditarCatalogo = true;
            servicios = j.articulos;
            pintarServicios(verMargen);
        }).catch(function () {
            // Sin acceso a Inventario: se consulta desde el catálogo de cotizaciones (solo lectura)
            puedeEditarCatalogo = false;
            $('btn-nuevo-servicio').hidden = true;
            return C.api('catalogo', { tipo: 'servicios', buscar: $('s-buscar').value.trim() }).then(function (j) {
                servicios = j.articulos;
                pintarServicios(verMargen);
            });
        });
    }

    function pintarServicios(verMargen) {
        var tbody = $('tabla-servicios');
        if (!servicios.length) {
            tbody.innerHTML = '<tr><td colspan="7" class="inv-vacio">Aún no hay servicios. Agrega tus visitas, mantenimientos y mano de obra para cotizarlos con un clic.</td></tr>';
            return;
        }
        tbody.innerHTML = servicios.map(function (s) {
            return '<tr><td><strong>' + esc(s.sku) + '</strong></td><td>' + esc(s.nombre) + '</td><td>' + esc(s.categoria) + '</td><td>' + esc(s.unidad) + '</td>' +
                '<td class="inv-num">' + (verMargen && s.costo_unitario != null ? C.dinero(s.costo_unitario) : '—') + '</td>' +
                '<td class="inv-num">' + C.dinero(s.precio_venta) + '</td>' +
                '<td>' + (puedeEditarCatalogo ? '<button type="button" class="btn-edit" data-editar="' + s.id + '" title="Editar"><i class="fas fa-pen"></i></button>' : '') + '</td></tr>';
        }).join('');
    }

    function abrirServicio(s) {
        var f = $('form-servicio');
        f.reset();
        f.elements.id.value = s ? s.id : '';
        $('titulo-servicio').textContent = s ? 'Editar ' + s.sku : 'Nuevo servicio';
        if (s) ['sku', 'nombre', 'categoria', 'unidad', 'costo_unitario', 'precio_venta', 'descripcion'].forEach(function (c) {
            if (f.elements[c]) f.elements[c].value = s[c] == null ? '' : s[c];
        });
        f.querySelectorAll('.solo-margen').forEach(function (el) { el.hidden = !(config && config.permisos.ve_margen); });
        $('modal-servicio').style.display = 'block';
        f.elements.sku.focus();
    }

    $('btn-nuevo-servicio').addEventListener('click', function () { abrirServicio(null); });
    $('tabla-servicios').addEventListener('click', function (e) {
        var b = e.target.closest('[data-editar]');
        if (!b) return;
        abrirServicio(servicios.find(function (s) { return String(s.id) === b.getAttribute('data-editar'); }));
    });
    $('form-servicio').addEventListener('submit', function (e) {
        e.preventDefault();
        var datos = Object.fromEntries(new FormData(this).entries());
        var actual = servicios.find(function (s) { return String(s.id) === String(datos.id); });
        // El costo solo lo captura quien lo ve; si no, se conserva el que tenía
        if (!(config && config.permisos.ve_margen)) datos.costo_unitario = actual ? actual.costo_unitario : 0;
        if (actual) ['marca', 'modelo'].forEach(function (c) { datos[c] = actual[c]; });
        var boton = this.querySelector('[type="submit"]');
        boton.disabled = true;
        apiInventario('guardar', null, datos).then(function () {
            $('modal-servicio').style.display = 'none';
            cargarServicios();
        }).catch(function (err) { avisar(err.message, 'error'); })
          .then(function () { boton.disabled = false; });
    });
    document.addEventListener('click', function (e) {
        if (e.target.matches('[data-cerrar]')) { // un clic fuera del modal no lo cierra
            document.querySelectorAll('.inv-modal').forEach(function (m) { m.style.display = 'none'; });
        }
    });

    // ---------- Inicio ----------
    document.addEventListener('DOMContentLoaded', function () {
        Object.keys(C.ESTATUS).forEach(function (k) {
            $('f-estatus').insertAdjacentHTML('beforeend', '<option value="' + k + '">' + esc(C.ESTATUS[k].texto) + '</option>');
        });
        var espera = null;
        $('f-buscar').addEventListener('input', function () { clearTimeout(espera); espera = setTimeout(cargarLista, 250); });
        $('s-buscar').addEventListener('input', function () { clearTimeout(espera); espera = setTimeout(cargarServicios, 250); });
        ['f-cliente', 'f-estatus', 'f-desde', 'f-hasta', 'f-por-vencer'].forEach(function (id) { $(id).addEventListener('change', cargarLista); });

        var params = new URLSearchParams(location.search);
        if (params.get('estatus')) $('f-estatus').value = params.get('estatus');

        C.api('config').then(function (j) { config = j; }).catch(function () { config = null; }).then(function () {
            if (location.hash === '#servicios') mostrarPestana('servicios');
            cargarLista();
        });
        C.api('clientes').then(function (j) {
            $('f-cliente').insertAdjacentHTML('beforeend', j.clientes.map(function (c) {
                return '<option value="' + c.id + '">' + esc(c.nombre) + '</option>';
            }).join(''));
        }).catch(function () { /* el filtro queda vacío */ });
        cargarResumen().catch(function () { /* sin indicadores */ });
        cargarVentasPendientes();
    });
})();
