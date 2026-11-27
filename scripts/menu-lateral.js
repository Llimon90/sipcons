// Navegación común a todas las páginas: menú lateral agrupado por áreas,
// buscador global (Ctrl + K) y barra inferior en el celular.
//
// Cada página trae solo el esqueleto (<aside class="sidebar"> con su <ul>
// vacío); el menú se arma aquí, así es igual en todas y agregar un módulo es
// cambiar MENU. Debe cargarse en el <head>, antes de sidebar-state.js: su
// DOMContentLoaded corre primero y sidebar-state.js encuentra el menú armado.
// auth-guard.js oculta después lo que el rol no tiene permitido.
(function () {
    'use strict';

    var enPublic = /\/public\//.test(window.location.pathname);
    var raiz = enPublic ? '../' : '';
    var paginaActual = (window.location.pathname.split('/').pop() || 'index.html').toLowerCase();

    // pagina: archivo dentro de public/ (o index.html); tambien: páginas internas del módulo
    var MENU = [
        { items: [{ pagina: 'index.html', texto: 'Inicio', icono: 'fa-home' }] },
        { grupo: 'Operación', items: [
            { pagina: 'incidencias.html', texto: 'Órdenes de servicio', icono: 'fa-tasks', tambien: ['detalle.html'], contador: 'incidencias' },
            { pagina: 'reportes.html', texto: 'Buscar órdenes de servicio', icono: 'fa-search' },
            { pagina: 'perfil.html', texto: 'Mi perfil', icono: 'fa-id-badge', tambien: ['dashboard-tecnico.html'] }  // D-46; incluye "Mis métricas"
        ] },
        { grupo: 'Comercial', items: [
            { pagina: 'clientes.html', texto: 'Clientes', icono: 'fa-address-book', tambien: ['perfil-cliente.html'] },
            { pagina: 'cotizaciones.html', texto: 'Cotizaciones', icono: 'fa-file-invoice-dollar', tambien: ['cotizacion.html'] },
            { pagina: 'ventas.html', texto: 'Ventas', icono: 'fa-shop', tambien: ['admin-ventas.html', 'consulta_ventas.html', 'detalles-venta.html'] },
            { pagina: 'inventario.html', texto: 'Inventario', icono: 'fa-boxes' },
            { pagina: 'finanzas.html', texto: 'Finanzas', icono: 'fa-wallet', tambien: ['estado-cuenta.html'] }
        ] },
        { grupo: 'Análisis', items: [
            { pagina: 'informes.html', texto: 'Estadísticas', icono: 'fa-chart-bar' }
        ] },
        { grupo: 'Administración', items: [
            { pagina: 'usuarios.html', texto: 'Usuarios', icono: 'fa-user-friends' },
            { pagina: 'historial.html', texto: 'Historial', icono: 'fa-history' },
            { pagina: 'importar.html', texto: 'Importar datos', icono: 'fa-file-import', soloAdministra: true },
            { pagina: 'mi-empresa.html', texto: 'Mi empresa', icono: 'fa-building', soloAdministra: true },
            { pagina: 'ajustes.html', texto: 'Ajustes', icono: 'fa-cog' }
        ] },
        { grupo: 'Ayuda', items: [
            { pagina: 'soporte.html', texto: 'Soporte técnico', icono: 'fa-headset' },
            { pagina: 'manual.html', texto: 'Manual de usuario', icono: 'fa-book-open' },
            { pagina: 'index.html?recorrido=1', texto: 'Recorrido guiado', icono: 'fa-route' }  // D-20
        ] }
    ];

    function href(pagina) {
        if (pagina.indexOf('index.html') === 0) return raiz + pagina;
        return (enPublic ? '' : 'public/') + pagina;
    }
    function esc(v) {
        return String(v == null ? '' : v).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }
    var esMac = /Mac|iPhone|iPad/.test(navigator.platform || '');

    // ------------------------------------------------------------ menú lateral
    function armarMenu() {
        var sidebar = document.querySelector('.sidebar');
        var lista = sidebar && sidebar.querySelector('ul');
        if (!lista) return;
        var html = '<li class="sidebar-buscar-item"><button type="button" class="sidebar-buscar" data-abrir-buscador title="Buscar (' + (esMac ? '⌘' : 'Ctrl') + ' + K)">' +
            '<i class="fas fa-magnifying-glass"></i><span class="sidebar-link-text">Buscar…</span><kbd class="sidebar-link-text">' + (esMac ? '⌘K' : 'Ctrl K') + '</kbd></button></li>';
        MENU.forEach(function (bloque) {
            if (bloque.grupo) html += '<li class="sidebar-grupo" data-grupo><span>' + esc(bloque.grupo) + '</span></li>';
            bloque.items.forEach(function (it) {
                var activo = it.pagina === paginaActual || (it.tambien || []).indexOf(paginaActual) !== -1;
                html += '<li' + (it.soloAdministra ? ' data-solo-administra hidden' : '') + '><a href="' + href(it.pagina) + '" title="' + esc(it.texto) + '"' +
                    (activo ? ' class="active" aria-current="page"' : '') + '>' +
                    '<i class="fas ' + it.icono + '"></i><span class="sidebar-link-text">' + esc(it.texto) + '</span>' +
                    (it.contador ? '<span class="sidebar-contador" data-contador="' + it.contador + '" hidden></span>' : '') + '</a></li>';
            });
        });
        lista.innerHTML = html;
        lista.classList.add('menu-lateral');
    }

    // Oculta los títulos de grupo que se quedaron sin módulos visibles
    function ajustarGrupos() {
        document.querySelectorAll('.sidebar [data-grupo]').forEach(function (titulo) {
            var visible = false;
            var li = titulo.nextElementSibling;
            while (li && !li.hasAttribute('data-grupo')) {
                if (!li.hidden && li.style.display !== 'none') { visible = true; break; }
                li = li.nextElementSibling;
            }
            titulo.hidden = !visible;
        });
    }

    function alListo(auth) {
        var empresa = auth.empresa || {};
        document.querySelectorAll('[data-solo-administra]').forEach(function (li) { li.hidden = !empresa.administra; });
        // auth-guard oculta lo no permitido en el mismo callback; se espera un ciclo
        setTimeout(ajustarGrupos, 0);
        var contador = document.querySelector('[data-contador="incidencias"]');
        if (!contador || (auth.modulos || []).indexOf('incidencias') === -1) return;
        fetch(raiz + 'backend/inicio.php?accion=contadores', { credentials: 'same-origin', headers: { Accept: 'application/json' } })
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(function (j) {
                if (!j || j.incidencias == null) return;
                contador.textContent = j.incidencias > 99 ? '99+' : j.incidencias;
                contador.hidden = !j.incidencias;
                contador.title = j.incidencias + ' órdenes de servicio activas';
            }).catch(function () {});
    }

    // ------------------------------------------------------------ buscador global
    var capa = null, temporizador = null, ultimaBusqueda = '';

    function modulosVisibles() {
        var out = [];
        document.querySelectorAll('.sidebar .menu-lateral a[href]').forEach(function (a) {
            var li = a.closest('li');
            if (li && (li.hidden || li.style.display === 'none')) return;
            out.push({ texto: a.textContent.trim(), href: a.getAttribute('href'), icono: (a.querySelector('i') || {}).className || 'fas fa-circle' });
        });
        return out;
    }

    function abrirBuscador(texto) {
        if (!capa) crearBuscador();
        capa.hidden = false;
        document.body.classList.add('buscador-abierto');
        var input = capa.querySelector('input');
        input.value = texto || '';
        input.focus();
        buscar(input.value);
    }
    function cerrarBuscador() {
        if (!capa || capa.hidden) return;
        capa.hidden = true;
        document.body.classList.remove('buscador-abierto');
    }

    function crearBuscador() {
        capa = document.createElement('div');
        capa.className = 'buscador-global';
        capa.hidden = true;
        capa.innerHTML = '<div class="buscador-fondo" data-cerrar></div>' +
            '<div class="buscador-caja" role="dialog" aria-modal="true" aria-label="Buscar en el sistema">' +
            '<div class="buscador-campo"><i class="fas fa-magnifying-glass"></i>' +
            '<input type="search" placeholder="Folio, cliente, sucursal, número de serie o módulo…" autocomplete="off" aria-label="Buscar">' +
            '<kbd>Esc</kbd></div><div class="buscador-resultados" role="listbox"></div>' +
            '<div class="buscador-pie"><span><kbd>↑</kbd><kbd>↓</kbd> moverse</span><span><kbd>Enter</kbd> abrir</span></div></div>';
        document.body.appendChild(capa);
        var input = capa.querySelector('input');
        input.addEventListener('input', function () {
            clearTimeout(temporizador);
            var v = input.value;
            temporizador = setTimeout(function () { buscar(v); }, 220);
        });
        input.addEventListener('keydown', function (e) {
            var items = Array.prototype.slice.call(capa.querySelectorAll('.buscador-item'));
            var actual = items.indexOf(capa.querySelector('.buscador-item.activo'));
            if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
                e.preventDefault();
                if (!items.length) return;
                var sig = e.key === 'ArrowDown' ? Math.min(items.length - 1, actual + 1) : Math.max(0, actual - 1);
                items.forEach(function (it, i) { it.classList.toggle('activo', i === sig); });
                items[sig].scrollIntoView({ block: 'nearest' });
            } else if (e.key === 'Enter') {
                e.preventDefault();
                var elegido = items[actual] || items[0];
                if (elegido) window.location.href = elegido.getAttribute('href');
            }
        });
        capa.addEventListener('click', function (e) { if (e.target.closest('[data-cerrar]')) cerrarBuscador(); });
    }

    function pintarResultados(grupos) {
        var html = '';
        grupos.forEach(function (g) {
            if (!g.items.length) return;
            html += '<div class="buscador-grupo">' + esc(g.titulo) + '</div>' + g.items.map(function (it) {
                return '<a class="buscador-item" href="' + esc(it.href) + '"><i class="' + esc(it.icono) + '"></i><span><b>' + esc(it.titulo) + '</b>' +
                    (it.sub ? '<small>' + esc(it.sub) + '</small>' : '') + '</span>' + (it.etiqueta ? '<em>' + esc(it.etiqueta) + '</em>' : '') + '</a>';
            }).join('');
        });
        var cont = capa.querySelector('.buscador-resultados');
        cont.innerHTML = html || '<p class="buscador-vacio">Sin resultados. Prueba con el folio, el cliente o el número de serie.</p>';
        var primero = cont.querySelector('.buscador-item');
        if (primero) primero.classList.add('activo');
    }

    function buscar(texto) {
        var q = (texto || '').trim();
        ultimaBusqueda = q;
        var norm = function (s) { return s.toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, ''); };
        var modulos = modulosVisibles().filter(function (m) { return !q || norm(m.texto).indexOf(norm(q)) !== -1; })
            .map(function (m) { return { href: m.href, icono: m.icono, titulo: m.texto, sub: 'Ir al módulo' }; });
        var base = [{ titulo: q ? 'Módulos' : 'Ir a', items: modulos }];
        if (q.length < 2) { pintarResultados(base); return; }
        pintarResultados(base.concat([{ titulo: 'Buscando…', items: [] }]));
        fetch(raiz + 'backend/inicio.php?accion=buscar&q=' + encodeURIComponent(q), { credentials: 'same-origin', headers: { Accept: 'application/json' } })
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(function (j) {
                if (!j || q !== ultimaBusqueda) return;
                var pub = enPublic ? '' : 'public/';
                pintarResultados([
                    { titulo: 'Órdenes de servicio', items: j.incidencias.map(function (i) {
                        return { href: pub + 'detalle.html?id=' + i.id, icono: 'fas fa-tasks', titulo: (i.numero_incidente || '#' + i.id) + ' · ' + (i.cliente || ''),
                                 sub: [i.sucursal, i.falla].filter(Boolean).join(' · '), etiqueta: i.estatus };
                    }) },
                    { titulo: 'Cotizaciones', items: (j.cotizaciones || []).map(function (c) {
                        return { href: pub + 'cotizacion.html?id=' + c.id, icono: 'fas fa-file-invoice-dollar', titulo: c.folio + ' · ' + (c.cliente_nombre || ''),
                                 sub: [c.sucursal, '$' + Number(c.total).toLocaleString('es-MX', { minimumFractionDigits: 2 })].filter(Boolean).join(' · '), etiqueta: c.estatus };
                    }) },
                    { titulo: 'Clientes', items: j.clientes.map(function (c) {
                        return { href: pub + 'perfil-cliente.html?id=' + c.id, icono: 'fas fa-address-book', titulo: c.nombre, sub: c.rfc };
                    }) },
                    { titulo: 'Equipos', items: j.equipos.map(function (e) {
                        return { href: e.cliente_id ? pub + 'perfil-cliente.html?id=' + e.cliente_id : pub + 'clientes.html', icono: 'fas fa-balance-scale',
                                 titulo: [e.equipo, e.marca, e.modelo].filter(Boolean).join(' '), sub: 'Serie ' + (e.numero_serie || '—') + ' · ' + (e.cliente || '') };
                    }) }
                ].concat(base[0].items.length ? base : []));
            }).catch(function () {});
    }

    // ------------------------------------------------------------ barra inferior (celular)
    function armarBarraInferior() {
        if (!document.querySelector('.sidebar') || document.querySelector('.nav-inferior')) return;
        var nav = document.createElement('nav');
        nav.className = 'nav-inferior';
        nav.setAttribute('aria-label', 'Navegación rápida');
        var activo = function (paginas) { return paginas.indexOf(paginaActual) !== -1 ? ' class="activo" aria-current="page"' : ''; };
        nav.innerHTML =
            '<a href="' + href('index.html') + '"' + activo(['index.html']) + '><i class="fas fa-home"></i><span>Inicio</span></a>' +
            // U-12: «Órdenes» lleva a la lista; «Nueva», al formulario de alta
            '<a href="' + href('incidencias.html') + '#lista"' + activo(['incidencias.html', 'detalle.html']) + ' data-modulo-inferior><i class="fas fa-tasks" aria-hidden="true"></i><span>Órdenes</span></a>' +
            '<a href="' + href('incidencias.html') + '" class="nav-inferior-nueva" data-modulo-inferior aria-label="Nueva orden de servicio"><i class="fas fa-plus"></i><span>Nueva</span></a>' +
            '<button type="button" data-abrir-buscador><i class="fas fa-magnifying-glass"></i><span>Buscar</span></button>' +
            '<button type="button" data-abrir-menu><i class="fas fa-bars"></i><span>Menú</span></button>';
        document.body.appendChild(nav);
        document.body.classList.add('con-nav-inferior');
        nav.querySelector('[data-abrir-menu]').addEventListener('click', function () {
            var boton = document.querySelector('.barra-movil .barra-movil-btn[aria-controls]');
            if (boton) boton.click();
        });
    }

    // ------------------------------------------------------------ arranque
    document.addEventListener('click', function (e) {
        if (e.target.closest('[data-abrir-buscador]')) { e.preventDefault(); abrirBuscador(); }
    });
    document.addEventListener('keydown', function (e) {
        if ((e.ctrlKey || e.metaKey) && (e.key === 'k' || e.key === 'K')) { e.preventDefault(); abrirBuscador(); }
        else if (e.key === 'Escape') cerrarBuscador();
    });

    window.appMenu = { abrirBuscador: abrirBuscador, ajustarGrupos: ajustarGrupos };

    document.addEventListener('DOMContentLoaded', function () {
        armarMenu();
        armarBarraInferior();
        if (window.appOnAuthReady) {
            window.appOnAuthReady(function (auth) {
                alListo(auth);
                if ((auth.modulos || []).indexOf('incidencias') === -1) {
                    document.querySelectorAll('[data-modulo-inferior]').forEach(function (a) { a.hidden = true; });
                }
            });
        }
    });
})();
