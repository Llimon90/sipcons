// Panel de analíticas de uso (solo Programador). Todo texto que viene de la
// base (etiquetas de botones, mensajes de error, etc.) se pinta con
// textContent: nunca como HTML.
(function () {
    'use strict';

    var API = '../backend/analytics_panel.php';
    var cache = {};
    var charts = {};
    var tabActual = 'hallazgos';
    var cargando = false;

    // ------------------------------------------------------------ helpers DOM
    function el(tag, cls, txt) {
        var e = document.createElement(tag);
        if (cls) e.className = cls;
        if (txt !== undefined && txt !== null) e.textContent = txt;
        return e;
    }
    function add(padre) {
        for (var i = 1; i < arguments.length; i++) if (arguments[i]) padre.appendChild(arguments[i]);
        return padre;
    }
    function vacio(msg) { return el('div', 'empty', msg || 'Sin datos en este periodo.'); }

    function fmtNum(n) { return n === null || n === undefined ? '—' : Number(n).toLocaleString('es-MX'); }
    function fmtSeg(s) {
        if (s === null || s === undefined) return '—';
        s = Math.round(s);
        if (s < 60) return s + ' s';
        var m = Math.floor(s / 60), r = s % 60;
        if (m < 60) return m + ' min' + (r ? ' ' + r + ' s' : '');
        return Math.floor(m / 60) + ' h ' + (m % 60) + ' min';
    }
    function fmtMs(ms) {
        if (ms === null || ms === undefined) return '—';
        return ms >= 1000 ? (ms / 1000).toFixed(1) + ' s' : Math.round(ms) + ' ms';
    }
    function fmtPct(p) { return p === null || p === undefined ? '—' : p + '%'; }
    function fmtFecha(f) { return f ? String(f).replace('T', ' ').slice(0, 16) : '—'; }

    function kpi(label, valor, sub, tendencia) {
        var c = el('div', 'kpi');
        add(c, el('div', 'l', label), el('div', 'v', valor));
        var s = el('div', 's', sub || '');
        if (tendencia !== undefined && tendencia !== null) {
            var t = el('span', tendencia >= 0 ? 'up' : 'down', (tendencia >= 0 ? '▲ +' : '▼ ') + tendencia + '% ');
            s.insertBefore(t, s.firstChild);
        }
        c.appendChild(s);
        return c;
    }

    // Tabla genérica. cols: [{h, k|f, n(numérica), sort}], filas: array de objetos
    function tabla(cols, filas, opts) {
        opts = opts || {};
        var wrap = el('div', 'tablewrap');
        var t = el('table');
        var thead = el('thead');
        var tr = el('tr');
        var orden = { col: null, asc: false };
        cols.forEach(function (c, i) {
            var th = el('th', (c.n ? 'n ' : '') + (c.sort !== false ? 'sortable' : ''), c.h);
            if (c.sort !== false) th.addEventListener('click', function () {
                orden.asc = orden.col === i ? !orden.asc : false;
                orden.col = i;
                pintar();
            });
            tr.appendChild(th);
        });
        thead.appendChild(tr);
        t.appendChild(thead);
        var tbody = el('tbody');
        t.appendChild(tbody);
        wrap.appendChild(t);

        function valorOrden(c, f) {
            var v = c.k ? f[c.k] : (c.v ? c.v(f) : null);
            return v === null || v === undefined ? -Infinity : v;
        }
        function pintar() {
            var datos = filas.slice();
            if (orden.col !== null) {
                var c = cols[orden.col];
                datos.sort(function (a, b) {
                    var x = valorOrden(c, a), y = valorOrden(c, b);
                    if (typeof x === 'string' || typeof y === 'string') return orden.asc ? String(x).localeCompare(String(y)) : String(y).localeCompare(String(x));
                    return orden.asc ? x - y : y - x;
                });
            }
            tbody.innerHTML = '';
            var max = opts.limite || 200;
            datos.slice(0, max).forEach(function (f) {
                var r = el('tr', opts.onClick ? 'click' : '');
                if (opts.onClick) r.addEventListener('click', function () { opts.onClick(f); });
                cols.forEach(function (c) {
                    var td = el('td', c.n ? 'n' : '');
                    var v = c.f ? c.f(f) : f[c.k];
                    if (v instanceof Node) td.appendChild(v);
                    else td.textContent = v === null || v === undefined || v === '' ? '—' : (typeof v === 'number' ? fmtNum(v) : v);
                    r.appendChild(td);
                });
                tbody.appendChild(r);
            });
            if (!datos.length) {
                var rv = el('tr'); var tdv = el('td'); tdv.colSpan = cols.length; tdv.appendChild(vacio(opts.vacio)); rv.appendChild(tdv); tbody.appendChild(rv);
            }
        }
        pintar();
        return wrap;
    }

    function conBarra(valor, max, texto) {
        var s = el('span', '', texto !== undefined ? texto : fmtNum(valor));
        var b = el('span', 'bar');
        b.style.width = Math.max(2, Math.round((max > 0 ? valor / max : 0) * 80)) + 'px';
        s.appendChild(b);
        return s;
    }

    function caja(titulo, hint) {
        var b = el('div', 'box');
        b.appendChild(el('h3', '', titulo));
        if (hint) b.appendChild(el('div', 'hint', hint));
        return b;
    }

    function grafica(id, config, alto) {
        var wrap = el('div', 'chartbox');
        if (alto) wrap.style.height = alto + 'px';
        var cv = el('canvas');
        wrap.appendChild(cv);
        setTimeout(function () {
            if (charts[id]) charts[id].destroy();
            charts[id] = new Chart(cv, config);
        }, 0);
        return wrap;
    }

    // ------------------------------------------------------------ filtros/API
    function params(extra) {
        var p = new URLSearchParams();
        var rango = document.getElementById('fRango').value;
        p.set('rango', rango);
        if (rango === 'custom') {
            p.set('desde', document.getElementById('fDesde').value);
            p.set('hasta', document.getElementById('fHasta').value);
        }
        var rol = document.getElementById('fRol').value;
        var uid = document.getElementById('fUsuario').value;
        if (rol) p.set('rol', rol);
        if (uid) p.set('usuario_id', uid);
        if (document.getElementById('fProg').checked) p.set('incluir_programador', '1');
        Object.keys(extra || {}).forEach(function (k) { p.set(k, extra[k]); });
        return p;
    }

    function api(action, extra, sinCache) {
        var p = params(extra);
        p.set('action', action);
        var key = p.toString();
        if (!sinCache && cache[key]) return Promise.resolve(cache[key]);
        return fetch(API + '?' + key, { credentials: 'same-origin', cache: 'no-store' })
            .then(function (r) {
                if (r.status === 404) throw new Error('Sin acceso.');
                return r.json();
            })
            .then(function (j) {
                if (!j.success) throw new Error(j.error || 'Error desconocido');
                cache[key] = j.data;
                return j.data;
            });
    }

    function mostrarError(msg) {
        var g = document.getElementById('errorGlobal');
        g.innerHTML = '';
        if (msg) g.appendChild(el('div', 'err', msg));
    }

    // -------------------------------------------------------------- vistas
    var vistas = {};

    vistas.hallazgos = function (cont, d) {
        var lista = d.hallazgos || [];
        if (!lista.length) return cont.appendChild(vacio('Aún no hay hallazgos: se necesita más actividad registrada.'));
        var resumen = el('div', 'grid');
        ['alto', 'medio', 'bajo', 'info'].forEach(function (n) {
            var c = lista.filter(function (h) { return h.nivel === n; }).length;
            var nombres = { alto: 'Prioridad alta', medio: 'Prioridad media', bajo: 'Prioridad baja', info: 'Informativo' };
            resumen.appendChild(kpi(nombres[n], c));
        });
        cont.appendChild(resumen);
        lista.forEach(function (h) {
            var c = el('div', 'hall ' + h.nivel);
            var t = el('div');
            add(t, el('span', 't', h.titulo), el('span', 'a', h.area));
            var sug = el('div', 'sug');
            add(sug, el('b', '', 'Qué hacer: '), document.createTextNode(h.sugerencia));
            add(c, t, el('div', '', h.detalle), sug);
            cont.appendChild(c);
        });
    };

    vistas.resumen = function (cont, d) {
        var k = d.kpis, t = d.tendencias;
        var g = el('div', 'grid');
        add(g,
            kpi('Visitas a páginas', fmtNum(k.visitas), 'vs periodo anterior', t.visitas),
            kpi('Sesiones', fmtNum(k.sesiones), 'vs periodo anterior', t.sesiones),
            kpi('Usuarios activos', fmtNum(k.usuarios), 'vs periodo anterior', t.usuarios),
            kpi('Tiempo activo total', fmtNum(k.act_total_min) + ' min', 'vs periodo anterior', t.act),
            kpi('Tiempo activo por sesión', fmtSeg(k.act_prom_sesion_s), 'Duración total: ' + fmtSeg(k.duracion_prom_sesion_s)),
            kpi('Páginas por sesión', k.paginas_por_sesion === null ? '—' : k.paginas_por_sesion, 'Clics por sesión: ' + (k.clics_por_sesion === null ? '—' : k.clics_por_sesion)),
            kpi('Rebote', fmtPct(k.rebote_pct), '1 página, <10 s, sin clics'),
            kpi('Errores', fmtNum(k.errores), 'JS + llamadas a la API fallidas')
        );
        cont.appendChild(g);

        var b1 = caja('Actividad diaria', 'Visitas, sesiones y usuarios distintos por día.');
        b1.appendChild(grafica('serie', {
            type: 'line',
            data: {
                labels: d.serie.map(function (s) { return s.fecha.slice(5); }),
                datasets: [
                    { label: 'Visitas', data: d.serie.map(function (s) { return s.visitas; }), borderColor: '#2563eb', backgroundColor: 'rgba(37,99,235,.1)', fill: true, tension: .3 },
                    { label: 'Sesiones', data: d.serie.map(function (s) { return s.sesiones; }), borderColor: '#0f766e', tension: .3 },
                    { label: 'Usuarios', data: d.serie.map(function (s) { return s.usuarios; }), borderColor: '#d97706', tension: .3 }
                ]
            },
            options: { responsive: true, maintainAspectRatio: false, scales: { y: { beginAtZero: true, ticks: { precision: 0 } } } }
        }, 280));
        cont.appendChild(b1);

        // Mapa de calor día x hora
        var b2 = caja('¿Cuándo se usa el sistema?', 'Visitas por día de la semana y hora del día (hora local).');
        var max = 0;
        d.heatmap.forEach(function (f) { f.forEach(function (n) { if (n > max) max = n; }); });
        var tb = el('table', 'heat');
        var hr = el('tr'); hr.appendChild(el('th'));
        for (var h = 0; h < 24; h++) hr.appendChild(el('th', '', h));
        tb.appendChild(hr);
        var dias = ['Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb', 'Dom'];
        d.heatmap.forEach(function (fila, i) {
            var r = el('tr'); r.appendChild(el('th', '', dias[i]));
            fila.forEach(function (n, hh) {
                var td = el('td', 'c', n || '');
                var a = max > 0 ? n / max : 0;
                td.style.background = n ? 'rgba(37,99,235,' + (0.12 + a * 0.88).toFixed(2) + ')' : '#eef2f6';
                td.title = dias[i] + ' ' + hh + ':00 — ' + n + ' visitas';
                r.appendChild(td);
            });
            tb.appendChild(r);
        });
        var hw = el('div', 'tablewrap'); hw.appendChild(tb); b2.appendChild(hw);
        cont.appendChild(b2);

        var cols = el('div', 'cols');
        [['dispositivos', 'Dispositivos'], ['navegadores', 'Navegadores'], ['sistemas', 'Sistemas operativos'], ['roles', 'Sesiones por rol']].forEach(function (par) {
            var b = caja(par[1]);
            var datos = d[par[0]];
            if (!datos.length) b.appendChild(vacio());
            else b.appendChild(grafica(par[0], {
                type: 'doughnut',
                data: { labels: datos.map(function (x) { return x.nombre; }), datasets: [{ data: datos.map(function (x) { return x.sesiones; }), backgroundColor: ['#2563eb', '#0f766e', '#d97706', '#8e44ad', '#dc2626', '#64748b', '#0891b2'] }] },
                options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'bottom' } } }
            }, 230));
            cols.appendChild(b);
        });
        cont.appendChild(cols);
    };

    vistas.modulos = function (cont, d) {
        if (!d.paginas.length) return cont.appendChild(vacio());
        var maxVis = Math.max.apply(null, d.modulos.map(function (m) { return m.visitas; }));
        var b1 = caja('Módulos más visitados', 'Agrupan varias páginas. Clic en un encabezado para ordenar.');
        b1.appendChild(tabla([
            { h: 'Módulo', k: 'modulo' },
            { h: 'Visitas', n: true, k: 'visitas', f: function (m) { return conBarra(m.visitas, maxVis); } },
            { h: '% del total', n: true, k: 'pct_visitas', f: function (m) { return fmtPct(m.pct_visitas); } },
            { h: 'Usuarios', n: true, k: 'usuarios' },
            { h: 'Tiempo activo', n: true, k: 'tiempo_total_min', f: function (m) { return m.tiempo_total_min + ' min'; } },
            { h: 'Tiempo por visita', n: true, k: 'tiempo_por_visita_s', f: function (m) { return fmtSeg(m.tiempo_por_visita_s); } },
            { h: 'Clics', n: true, k: 'clics' },
            { h: 'Errores', n: true, k: 'errores' }
        ], d.modulos));
        cont.appendChild(b1);

        var b2 = caja('Detalle por página / sección', 'Salida = % de visitas donde la página fue la última de la sesión. Scroll = avance promedio de lectura.');
        b2.appendChild(tabla([
            { h: 'Página', k: 'nombre' },
            { h: 'Módulo', k: 'modulo' },
            { h: 'Visitas', n: true, k: 'visitas' },
            { h: 'Usuarios', n: true, k: 'usuarios' },
            { h: 'Tiempo mediano', n: true, k: 'tiempo_mediano_s', f: function (p) { return fmtSeg(p.tiempo_mediano_s); } },
            { h: 'Scroll', n: true, k: 'scroll_prom', f: function (p) { return p.scroll_prom === null ? '—' : p.scroll_prom + '%'; } },
            { h: 'Clics/visita', n: true, k: 'clics_por_visita' },
            { h: 'Entradas', n: true, k: 'entradas' },
            { h: 'Salidas', n: true, k: 'salidas' },
            { h: '% salida', n: true, k: 'tasa_salida_pct', f: function (p) { return fmtPct(p.tasa_salida_pct); } },
            { h: 'Carga p50', n: true, k: 'carga_p50_ms', f: function (p) { return fmtMs(p.carga_p50_ms); } },
            { h: 'Errores', n: true, k: 'errores' }
        ], d.paginas));
        cont.appendChild(b2);

        var b3 = caja('Secciones internas (pestañas)', 'Qué pestañas dentro de cada página se abren más.');
        b3.appendChild(d.tabs.length ? tabla([
            { h: 'Página', k: 'nombre_pagina' },
            { h: 'Pestaña', k: 'elemento' },
            { h: 'Clics', n: true, k: 'n' },
            { h: 'Usuarios', n: true, k: 'usuarios' }
        ], d.tabs) : vacio('Aún no hay clics en pestañas registrados.'));
        cont.appendChild(b3);
    };

    vistas.usuarios = function (cont, d) {
        var b = caja('Actividad por usuario', 'Clic en una fila para ver cómo usa el sistema (sesiones, recorridos, clics, errores).');
        b.appendChild(d.usuarios.length ? tabla([
            { h: 'Usuario', f: function (u) { return u.nombre || u.usuario || ('#' + u.usuario_id); }, v: function (u) { return u.nombre || u.usuario || ''; } },
            { h: 'Rol', k: 'rol' },
            { h: 'Sesiones', n: true, k: 'sesiones' },
            { h: 'Días activos', n: true, k: 'dias_activos' },
            { h: 'Visitas', n: true, k: 'visitas' },
            { h: 'Clics', n: true, k: 'clics' },
            { h: 'Tiempo activo', n: true, k: 'act_min', f: function (u) { return u.act_min + ' min'; } },
            { h: 'Módulo favorito', k: 'modulo_favorito' },
            { h: 'Errores', n: true, k: 'errores' },
            { h: 'Fricción', n: true, k: 'frustracion' },
            { h: 'Última vez', k: 'ultimo', f: function (u) { return fmtFecha(u.ultimo); } }
        ], d.usuarios, { onClick: function (u) { abrirUsuario(u.usuario_id, u.nombre || u.usuario); } }) : vacio());
        cont.appendChild(b);

        if (d.inactivos.length) {
            var b2 = caja('Sin actividad en el periodo', 'Usuarios dados de alta que no entraron al sistema.');
            b2.appendChild(tabla([
                { h: 'Usuario', k: 'nombre' }, { h: 'Cuenta', k: 'usuario' }, { h: 'Rol', k: 'rol' }
            ], d.inactivos));
            cont.appendChild(b2);
        }
    };

    vistas.flujos = function (cont, d) {
        var g = el('div', 'grid');
        add(g, kpi('Sesiones analizadas', fmtNum(d.sesiones)), kpi('Sesiones de una sola página', fmtNum(d.sesiones_una_pagina), fmtPct(d.sesiones > 0 ? Math.round(d.sesiones_una_pagina / d.sesiones * 1000) / 10 : null)));
        cont.appendChild(g);

        var b0 = caja('Embudos de tareas clave', 'De las sesiones que empiezan la tarea, cuántas llegan al final.');
        d.embudos.forEach(function (e) {
            b0.appendChild(el('div', '', e.nombre)).style.fontWeight = '700';
            var max = e.pasos[0] ? e.pasos[0].sesiones : 0;
            b0.appendChild(tabla([
                { h: 'Paso', k: 'etiqueta', sort: false },
                { h: 'Sesiones', n: true, k: 'sesiones', sort: false, f: function (p) { return conBarra(p.sesiones, max); } },
                { h: 'Usuarios', n: true, k: 'usuarios', sort: false },
                { h: '% del inicio', n: true, sort: false, f: function (p) { return fmtPct(p.pct_inicio); } },
                { h: '% del paso previo', n: true, sort: false, f: function (p) { return fmtPct(p.pct_previo); } }
            ], e.pasos));
        });
        cont.appendChild(b0);

        var cols = el('div', 'cols');
        var b1 = caja('Cómo empiezan las sesiones', 'Primera página de la sesión.');
        b1.appendChild(tabla([{ h: 'Página', k: 'nombre' }, { h: 'Sesiones', n: true, k: 'n' }, { h: '%', n: true, f: function (x) { return fmtPct(x.pct); } }], d.entradas));
        var b2 = caja('Dónde terminan', 'Última página de la sesión.');
        b2.appendChild(tabla([{ h: 'Página', k: 'nombre' }, { h: 'Sesiones', n: true, k: 'n' }, { h: '%', n: true, f: function (x) { return fmtPct(x.pct); } }], d.salidas));
        add(cols, b1, b2);
        cont.appendChild(cols);

        var b3 = caja('Recorridos más comunes (3 pasos)', 'Secuencias de páginas que más se repiten.');
        b3.appendChild(d.rutas.length ? tabla([
            { h: 'Recorrido', f: function (r) { return r.pasos.join('  →  '); }, sort: false },
            { h: 'Veces', n: true, k: 'n', sort: false }
        ], d.rutas) : vacio('Se necesitan sesiones con 3 o más páginas.'));
        cont.appendChild(b3);

        var b4 = caja('Transiciones entre páginas', 'De qué página a cuál se navega.');
        b4.appendChild(tabla([
            { h: 'Desde', k: 'nombre_desde' }, { h: 'Hacia', k: 'nombre_hasta' }, { h: 'Veces', n: true, k: 'n' }
        ], d.transiciones));
        cont.appendChild(b4);
    };

    vistas.interacciones = function (cont, d) {
        var b1 = caja('Elementos más usados', 'Botones, enlaces, pestañas, filtros y formularios. Lo que aparece poco puede sobrar o estar escondido.');
        b1.appendChild(tabla([
            { h: 'Página', k: 'nombre_pagina' },
            { h: 'Tipo', k: 'tipo' },
            { h: 'Elemento', k: 'elemento' },
            { h: 'Veces', n: true, k: 'n' },
            { h: 'Usuarios', n: true, k: 'usuarios' }
        ], d.top, { limite: 150, vacio: 'Aún no hay interacciones registradas.' }));
        cont.appendChild(b1);

        var b2 = caja('Filtros y listas: qué opciones se eligen', 'Opción elegida en listas desplegables (no se guarda texto libre).');
        b2.appendChild(tabla([
            { h: 'Página', k: 'nombre_pagina' },
            { h: 'Control', k: 'elemento' },
            { h: 'Opción', k: 'detalle' },
            { h: 'Veces', n: true, k: 'n' }
        ], d.filtros_usados, { limite: 100, vacio: 'Sin cambios en listas registrados.' }));
        cont.appendChild(b2);
    };

    vistas.friccion = function (cont, d) {
        var nombres = { rage_click: 'Clics furiosos', dead_click: 'Clic sin respuesta', validacion_fallida: 'Validación fallida' };
        var b1 = caja('Puntos de fricción', 'Clics repetidos sobre lo mismo, clics en algo que no responde y campos que fallan la validación.');
        b1.appendChild(tabla([
            { h: 'Página', k: 'nombre_pagina' },
            { h: 'Señal', f: function (x) { return nombres[x.tipo] || x.tipo; }, v: function (x) { return x.tipo; } },
            { h: 'Elemento / campo', k: 'elemento' },
            { h: 'Veces', n: true, k: 'n' },
            { h: 'Usuarios', n: true, k: 'usuarios' },
            { h: 'Última vez', k: 'ultimo', f: function (x) { return fmtFecha(x.ultimo); } }
        ], d.frustracion, { vacio: 'Sin señales de fricción. Buena señal.' }));
        cont.appendChild(b1);

        var b2 = caja('Errores de JavaScript', 'Agrupados por mensaje y página.');
        b2.appendChild(tabla([
            { h: 'Mensaje', k: 'mensaje' },
            { h: 'Página', k: 'nombre_pagina' },
            { h: 'Origen', k: 'origen' },
            { h: 'Veces', n: true, k: 'n' },
            { h: 'Usuarios', n: true, k: 'usuarios' },
            { h: 'Última vez', k: 'ultimo', f: function (x) { return fmtFecha(x.ultimo); } }
        ], d.errores_js, { vacio: 'Sin errores de JavaScript registrados.' }));
        cont.appendChild(b2);

        var b3 = caja('Salud de la API (endpoints)', 'Latencia (p50 / p95) y % de errores por endpoint. Ordenado por errores y lentitud.');
        b3.appendChild(tabla([
            { h: 'Endpoint', k: 'endpoint' },
            { h: 'Llamadas', n: true, k: 'llamadas' },
            { h: 'p50', n: true, k: 'p50_ms', f: function (x) { return fmtMs(x.p50_ms); } },
            { h: 'p95', n: true, k: 'p95_ms', f: function (x) { return fmtMs(x.p95_ms); } },
            { h: 'Máx.', n: true, k: 'max_ms', f: function (x) { return fmtMs(x.max_ms); } },
            { h: 'Errores', n: true, k: 'errores', f: function (x) { return x.errores ? x.errores + ' (' + x.error_pct + '%)' : '0'; } },
            { h: 'Estatus', f: function (x) { return x.estatus.map(function (s) { return (s.estatus || 'sin respuesta') + '×' + s.n; }).join(', '); }, sort: false }
        ], d.endpoints, { vacio: 'Aún no hay llamadas a la API registradas.' }));
        cont.appendChild(b3);

        var b4 = caja('Tiempo de carga por página', 'Desde que se pide la página hasta que termina de cargar.');
        b4.appendChild(tabla([
            { h: 'Página', k: 'nombre' },
            { h: 'Visitas', n: true, k: 'visitas' },
            { h: 'p50', n: true, k: 'p50_ms', f: function (x) { return fmtMs(x.p50_ms); } },
            { h: 'p95', n: true, k: 'p95_ms', f: function (x) { return fmtMs(x.p95_ms); } }
        ], d.cargas));
        cont.appendChild(b4);

        if (d.accesos_denegados.length) {
            var b5 = caja('Intentos de entrar a páginas sin permiso', 'Suele indicar enlaces visibles que no deberían estarlo, o usuarios que necesitan otro rol.');
            b5.appendChild(tabla([{ h: 'Página', k: 'pagina' }, { h: 'Intentos', n: true, k: 'n' }, { h: 'Usuarios', n: true, k: 'usuarios' }], d.accesos_denegados));
            cont.appendChild(b5);
        }
    };

    vistas.adopcion = function (cont, d) {
        var g = el('div', 'grid');
        add(g,
            kpi('Usuarios activos', fmtNum(d.usuarios_activos)),
            kpi('Activos hoy (DAU)', fmtNum(d.dau), 'Al cierre del periodo'),
            kpi('Activos 7 días (WAU)', fmtNum(d.wau)),
            kpi('Activos 30 días (MAU)', fmtNum(d.mau)),
            kpi('Frecuencia de uso', fmtPct(d.stickiness_pct), 'DAU promedio ' + (d.dau_promedio === null ? '—' : d.dau_promedio) + ' ÷ MAU')
        );
        cont.appendChild(g);

        var b1 = caja('Adopción por módulo', '% de los usuarios activos que usó cada módulo. Los de 0% no se usaron en el periodo.');
        b1.appendChild(tabla([
            { h: 'Módulo', k: 'modulo' },
            { h: 'Adopción', n: true, k: 'adopcion_pct', f: function (m) { return conBarra(m.adopcion_pct || 0, 100, fmtPct(m.adopcion_pct)); } },
            { h: 'Usuarios', n: true, k: 'usuarios' },
            { h: 'Visitas', n: true, k: 'visitas' }
        ], d.modulos));
        cont.appendChild(b1);

        var cols = el('div', 'cols');
        var b2 = caja('Usuarios nuevos vs recurrentes por semana');
        b2.appendChild(d.semanal.length ? grafica('semanal', {
            type: 'bar',
            data: {
                labels: d.semanal.map(function (s) { return s.semana; }),
                datasets: [
                    { label: 'Nuevos', data: d.semanal.map(function (s) { return s.nuevos; }), backgroundColor: '#d97706' },
                    { label: 'Recurrentes', data: d.semanal.map(function (s) { return s.recurrentes; }), backgroundColor: '#2563eb' }
                ]
            },
            options: { responsive: true, maintainAspectRatio: false, scales: { x: { stacked: true }, y: { stacked: true, beginAtZero: true, ticks: { precision: 0 } } } }
        }, 240) : vacio());
        var b3 = caja('Frecuencia: días activos por usuario');
        b3.appendChild(grafica('frecuencia', {
            type: 'bar',
            data: { labels: d.frecuencia.map(function (f) { return f.rango; }), datasets: [{ label: 'Usuarios', data: d.frecuencia.map(function (f) { return f.usuarios; }), backgroundColor: '#0f766e' }] },
            options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true, ticks: { precision: 0 } } } }
        }, 240));
        add(cols, b2, b3);
        cont.appendChild(cols);

        var b4 = caja('¿Qué módulos usa cada rol?', 'Visitas por rol y módulo.');
        var mods = d.modulos.map(function (m) { return m.modulo; });
        b4.appendChild(d.rol_modulo.length ? tabla(
            [{ h: 'Rol', k: 'rol' }].concat(mods.map(function (m) { return { h: m, n: true, sort: false, f: function (r) { return r.modulos[m] || 0; } }; })),
            d.rol_modulo.map(function (r) { return { rol: r.rol, modulos: r.modulos }; })
        ) : vacio());
        cont.appendChild(b4);
    };

    // ------------------------------------------------------ detalle de usuario
    function abrirOverlay(cont) {
        var ov = document.getElementById('overlay');
        var dr = document.getElementById('drawer');
        dr.innerHTML = '';
        dr.appendChild(cont);
        ov.classList.add('on');
        document.body.style.overflow = 'hidden';
    }
    function cerrarOverlay() {
        document.getElementById('overlay').classList.remove('on');
        document.body.style.overflow = '';
    }

    function cabecera(titulo, volver) {
        var hd = el('div', 'hd');
        var h = el('h2', '', titulo);
        var b = el('button', 'btn sec', volver ? '← Volver' : 'Cerrar');
        b.addEventListener('click', volver || cerrarOverlay);
        add(hd, h, b);
        return hd;
    }

    function abrirUsuario(uid, nombre) {
        var c = el('div');
        c.appendChild(cabecera(nombre || 'Usuario'));
        c.appendChild(el('div', 'empty', 'Cargando…'));
        abrirOverlay(c);
        api('usuario', { usuario_id: uid }).then(function (d) {
            c.innerHTML = '';
            c.appendChild(cabecera((d.usuario.nombre || d.usuario.usuario || nombre || 'Usuario') + (d.usuario.rol ? ' · ' + d.usuario.rol : '')));
            var k = d.kpis;
            var g = el('div', 'grid');
            add(g,
                kpi('Sesiones', fmtNum(k.sesiones), 'Días activos: ' + k.dias_activos),
                kpi('Visitas', fmtNum(k.visitas), 'Páginas por sesión: ' + (k.paginas_por_sesion === null ? '—' : k.paginas_por_sesion)),
                kpi('Clics', fmtNum(k.clics)),
                kpi('Tiempo activo', k.act_total_min + ' min', 'Por sesión: ' + fmtSeg(k.act_prom_sesion_s)),
                kpi('Primera vez', fmtFecha(d.usuario.primero).slice(0, 10), 'Última: ' + fmtFecha(d.usuario.ultimo))
            );
            c.appendChild(g);

            var cols = el('div', 'cols');
            var bm = caja('Módulos que usa');
            bm.appendChild(d.modulos.length ? grafica('u_mod', {
                type: 'bar',
                data: { labels: d.modulos.map(function (m) { return m.modulo; }), datasets: [{ label: 'Visitas', data: d.modulos.map(function (m) { return m.visitas; }), backgroundColor: '#2563eb' }] },
                options: { indexAxis: 'y', responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { x: { beginAtZero: true, ticks: { precision: 0 } } } }
            }, 240) : vacio());
            var bh = caja('A qué horas entra');
            bh.appendChild(grafica('u_hor', {
                type: 'bar',
                data: { labels: d.horas.map(function (_, i) { return i; }), datasets: [{ label: 'Visitas', data: d.horas, backgroundColor: '#0f766e' }] },
                options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true, ticks: { precision: 0 } } } }
            }, 240));
            add(cols, bm, bh);
            c.appendChild(cols);

            var bs = caja('Sesiones recientes', 'Clic en una sesión para ver todo lo que hizo, paso a paso.');
            bs.appendChild(tabla([
                { h: 'Inicio', k: 'inicio', f: function (s) { return fmtFecha(s.inicio); } },
                { h: 'Duración', n: true, k: 'duracion_s', f: function (s) { return fmtSeg(s.duracion_s); } },
                { h: 'Activo', n: true, k: 'act_s', f: function (s) { return fmtSeg(s.act_s); } },
                { h: 'Páginas', n: true, k: 'paginas' },
                { h: 'Clics', n: true, k: 'clics' },
                { h: 'Errores', n: true, k: 'errores' },
                { h: 'Recorrido', f: function (s) { var r = el('span', 'path', s.ruta.join(' → ')); return r; }, sort: false }
            ], d.sesiones, { onClick: function (s) { abrirSesion(s.sesion_id, function () { abrirUsuario(uid, nombre); }); }, vacio: 'Sin sesiones en el periodo.' }));
            c.appendChild(bs);

            var be = caja('Qué toca más', 'Elementos con los que interactúa (los 25 principales).');
            be.appendChild(tabla([
                { h: 'Página', k: 'nombre_pagina' }, { h: 'Tipo', k: 'tipo' }, { h: 'Elemento', k: 'elemento' }, { h: 'Veces', n: true, k: 'n' }
            ], d.elementos, { vacio: 'Sin interacciones registradas.' }));
            c.appendChild(be);

            if (d.frustracion.length || d.errores.length) {
                var bf = caja('Problemas que ha encontrado');
                if (d.frustracion.length) bf.appendChild(tabla([
                    { h: 'Página', k: 'nombre_pagina' }, { h: 'Señal', k: 'tipo' }, { h: 'Elemento', k: 'elemento' }, { h: 'Veces', n: true, k: 'n' }
                ], d.frustracion));
                if (d.errores.length) bf.appendChild(tabla([
                    { h: 'Cuándo', k: 'creado_en', f: function (x) { return fmtFecha(x.creado_en); } },
                    { h: 'Tipo', k: 'tipo' }, { h: 'Página', k: 'pagina' }, { h: 'Detalle', k: 'elemento' },
                    { h: 'Estatus', n: true, k: 'extra' }
                ], d.errores));
                c.appendChild(bf);
            }
        }).catch(function (e) {
            c.innerHTML = '';
            c.appendChild(cabecera('Error'));
            c.appendChild(el('div', 'err', e.message));
        });
    }

    function abrirSesion(sid, volver) {
        var c = el('div');
        c.appendChild(cabecera('Sesión', volver));
        c.appendChild(el('div', 'empty', 'Cargando…'));
        abrirOverlay(c);
        api('sesion', { sesion_id: sid }).then(function (d) {
            c.innerHTML = '';
            var u = d.usuario || {};
            c.appendChild(cabecera('Sesión de ' + (u.nombre || u.usuario || '—') + ' · ' + fmtFecha(d.inicio), volver));
            c.appendChild(el('div', 'hint', [d.dispositivo, d.navegador, d.sistema].filter(Boolean).join(' · ')));
            var tl = el('div', 'tl');
            var nombresTipo = {
                pageview: 'Abrió', carga: 'Carga completa', permanencia: 'Tiempo activo', click: 'Clic', tab: 'Pestaña', submit: 'Envió formulario',
                cambio: 'Cambió lista', busqueda: 'Buscó', error_js: 'ERROR JS', api: 'API', rage_click: 'Clics furiosos', dead_click: 'Clic sin respuesta',
                validacion_fallida: 'Validación fallida', acceso_denegado: 'Acceso denegado'
            };
            d.eventos.forEach(function (e) {
                var esError = e.tipo === 'error_js' || (e.tipo === 'api' && (e.extra === 0 || e.extra >= 400)) || e.tipo === 'rage_click' || e.tipo === 'dead_click' || e.tipo === 'validacion_fallida' || e.tipo === 'acceso_denegado';
                var clase = 'ev' + (esError ? ' error' : '') + (e.tipo === 'pageview' ? ' pageview' : '') + (e.tipo === 'permanencia' || e.tipo === 'carga' ? ' perm' : '');
                var fila = el('div', clase);
                var partes = [nombresTipo[e.tipo] || e.tipo];
                if (e.tipo === 'pageview') partes.push(e.nombre_pagina);
                else if (e.tipo === 'permanencia') partes.push(fmtSeg((e.valor || 0) / 1000) + (e.extra ? ' · scroll ' + e.extra + '%' : ''));
                else if (e.tipo === 'carga') partes.push(fmtMs(e.valor));
                else if (e.tipo === 'api') partes.push(e.elemento + ' → ' + (e.extra || 'sin respuesta') + ' · ' + fmtMs(e.valor));
                else {
                    if (e.elemento) partes.push('“' + e.elemento + '”');
                    if (e.detalle) partes.push('(' + e.detalle + ')');
                    partes.push('en ' + e.nombre_pagina);
                }
                add(fila, el('span', 't', '+' + fmtSeg(e.t)), document.createTextNode(partes.join(' · ')));
                tl.appendChild(fila);
            });
            c.appendChild(tl);
        }).catch(function (e) {
            c.innerHTML = '';
            c.appendChild(cabecera('Error', volver));
            c.appendChild(el('div', 'err', e.message));
        });
    }

    // -------------------------------------------------------------- carga
    function cargarTab(tab, sinCache) {
        tabActual = tab;
        var cont = document.getElementById('p-' + tab);
        cont.innerHTML = '';
        cont.appendChild(el('div', 'empty', 'Cargando…'));
        mostrarError('');
        var accion = tab === 'interacciones' ? 'elementos' : tab;
        return api(accion, {}, sinCache).then(function (d) {
            if (tabActual !== tab) return;
            cont.innerHTML = '';
            vistas[tab](cont, d);
        }).catch(function (e) {
            cont.innerHTML = '';
            mostrarError(e.message);
        });
    }

    function cambiarTab(tab) {
        document.querySelectorAll('.tab').forEach(function (t) { t.classList.toggle('on', t.dataset.tab === tab); });
        document.querySelectorAll('.panel').forEach(function (p) { p.classList.toggle('on', p.id === 'p-' + tab); });
        cargarTab(tab);
    }

    function poblarCatalogo() {
        return api('catalogo', {}, true).then(function (d) {
            var selRol = document.getElementById('fRol');
            d.roles.forEach(function (r) { var o = el('option', '', r); o.value = r; selRol.appendChild(o); });
            var selU = document.getElementById('fUsuario');
            d.usuarios.forEach(function (u) { var o = el('option', '', u.nombre || u.usuario); o.value = u.usuario_id; selU.appendChild(o); });
            document.getElementById('estado').textContent = d.total_eventos
                ? fmtNum(d.total_eventos) + ' eventos registrados desde ' + fmtFecha(d.primer_evento).slice(0, 10)
                : 'Aún no hay eventos registrados (¿ya aplicaste la migración 011?)';
        }).catch(function (e) {
            document.getElementById('estado').textContent = 'No disponible';
            mostrarError(e.message);
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        var hoy = new Date();
        var hace = new Date(); hace.setDate(hoy.getDate() - 29);
        document.getElementById('fHasta').value = hoy.toISOString().slice(0, 10);
        document.getElementById('fDesde').value = hace.toISOString().slice(0, 10);

        document.getElementById('fRango').addEventListener('change', function () {
            var custom = this.value === 'custom';
            document.getElementById('fDesdeW').style.display = custom ? '' : 'none';
            document.getElementById('fHastaW').style.display = custom ? '' : 'none';
            if (!custom) { cache = {}; cargarTab(tabActual); }
        });
        ['fRol', 'fUsuario', 'fProg'].forEach(function (id) {
            document.getElementById(id).addEventListener('change', function () { cache = {}; cargarTab(tabActual); });
        });
        document.getElementById('btnAplicar').addEventListener('click', function () { cache = {}; cargarTab(tabActual, true); });
        document.querySelectorAll('.tab').forEach(function (t) { t.addEventListener('click', function () { cambiarTab(t.dataset.tab); }); });
        document.getElementById('overlay').addEventListener('click', function (e) { if (e.target === this) cerrarOverlay(); });
        document.addEventListener('keydown', function (e) { if (e.key === 'Escape') cerrarOverlay(); });

        poblarCatalogo().then(function () { cargarTab('hallazgos'); });
    });
})();
