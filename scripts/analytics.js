// Analíticas de uso del portal (cliente). Se carga desde auth-guard.js en
// todas las páginas. Registra páginas vistas, tiempo activo, clics, cambios de
// pestaña, filtros, búsquedas, envíos de formularios, errores y latencia de
// la API. NUNCA registra lo que se escribe en los campos: solo etiquetas,
// longitud de una búsqueda y la opción elegida en listas desplegables.
// La identidad la pone el servidor desde la sesión. Todo va en try/catch:
// esto jamás debe romper una página.
(function () {
    'use strict';
    try {
        if (window.__sipconsAnalytics) return;
        window.__sipconsAnalytics = true;

        var pagina = (location.pathname.split('/').pop() || 'index.html');
        if (/^analiticas\./i.test(pagina)) return; // el panel no se observa a sí mismo

        var enPublic = /\/public\//.test(location.pathname);
        var ENDPOINT = (enPublic ? '../' : '') + 'backend/analytics_registrar.php';
        var SESION_TTL = 30 * 60 * 1000;
        var LOTE_MAX = 40;
        var cola = [];
        var origFetch = window.fetch ? window.fetch.bind(window) : null;

        function rid() {
            try {
                var a = new Uint8Array(8);
                crypto.getRandomValues(a);
                return Array.prototype.map.call(a, function (b) { return ('0' + b.toString(16)).slice(-2); }).join('');
            } catch (e) {
                return (Math.random().toString(16) + '0000000000000000').slice(2, 18);
            }
        }
        var visitaId = rid();
        var sesionFallback = rid();

        function sesionId() {
            try {
                var ahora = Date.now();
                var o = JSON.parse(localStorage.getItem('sipcons_an_s') || 'null');
                if (!o || !o.id || ahora - o.t > SESION_TTL) o = { id: rid() };
                o.t = ahora;
                localStorage.setItem('sipcons_an_s', JSON.stringify(o));
                return o.id;
            } catch (e) {
                return sesionFallback;
            }
        }

        var paginaPrevia = '';
        try {
            if (document.referrer) {
                var r = new URL(document.referrer);
                if (r.origin === location.origin) paginaPrevia = r.pathname.split('/').pop() || 'index.html';
            }
            if (!paginaPrevia) paginaPrevia = sessionStorage.getItem('sipcons_an_prev') || '';
            sessionStorage.setItem('sipcons_an_prev', pagina);
        } catch (e) { /* sin storage */ }

        function texto(s, n) { return String(s == null ? '' : s).replace(/\s+/g, ' ').trim().slice(0, n); }

        function track(tipo, elemento, detalle, valor, extra) {
            if (cola.length >= 200) return;
            var ev = { t: tipo };
            if (elemento) ev.e = texto(elemento, 200);
            if (detalle) ev.d = texto(detalle, 300);
            if (typeof valor === 'number' && isFinite(valor)) ev.v = Math.round(valor);
            if (typeof extra === 'number' && isFinite(extra)) ev.x = Math.round(extra);
            cola.push(ev);
            if (cola.length >= LOTE_MAX) flush(false);
        }
        window.sipconsTrack = track;

        function flush(alSalir) {
            if (!cola.length) return;
            var lote = cola.splice(0, 50);
            var cuerpo = JSON.stringify({
                s: sesionId(), p: visitaId, pg: pagina, ref: paginaPrevia,
                vp: window.innerWidth + 'x' + window.innerHeight, ev: lote
            });
            try {
                if (alSalir && navigator.sendBeacon) {
                    navigator.sendBeacon(ENDPOINT, new Blob([cuerpo], { type: 'application/json' }));
                } else if (origFetch) {
                    origFetch(ENDPOINT, {
                        method: 'POST', body: cuerpo, keepalive: true, credentials: 'same-origin',
                        headers: { 'Content-Type': 'application/json' }
                    }).catch(function () {});
                }
            } catch (e) { /* silencioso */ }
            if (cola.length) flush(alSalir);
        }
        window.sipconsFlush = function () { flush(false); };

        // Eventos que otras piezas (auth-guard) dejaron en cola antes de cargar este script
        if (window.__sipconsTrackQ && window.__sipconsTrackQ.length) {
            window.__sipconsTrackQ.forEach(function (q) { track(q[0], q[1], q[2], q[3], q[4]); });
            window.__sipconsTrackQ = [];
        }

        // ---------- página vista y carga
        var params = [];
        try { new URLSearchParams(location.search).forEach(function (_, k) { params.push(k); }); } catch (e) { /* nada */ }
        track('pageview', null, params.length ? 'params: ' + params.join(',') : null);

        function medirCarga() {
            setTimeout(function () {
                try {
                    var nav = performance.getEntriesByType && performance.getEntriesByType('navigation')[0];
                    var ms = nav ? (nav.loadEventEnd || nav.domComplete) : (performance.timing ? performance.timing.loadEventEnd - performance.timing.navigationStart : 0);
                    if (ms > 0 && ms < 120000) track('carga', null, null, ms);
                } catch (e) { /* nada */ }
            }, 0);
        }
        if (document.readyState === 'complete') medirCarga();
        else window.addEventListener('load', medirCarga);

        // ---------- tiempo activo y scroll
        var ultimaAccion = Date.now();
        var activoMs = 0;
        var activoEnviado = 0;
        var scrollMax = 0;
        function marcarAccion() { ultimaAccion = Date.now(); }
        ['mousemove', 'keydown', 'pointerdown', 'touchstart', 'wheel'].forEach(function (n) {
            document.addEventListener(n, marcarAccion, { passive: true, capture: true });
        });
        setInterval(function () {
            if (document.visibilityState === 'visible' && Date.now() - ultimaAccion < 30000) activoMs += 1000;
        }, 1000);

        var scrollTimer = null;
        document.addEventListener('scroll', function (e) {
            marcarAccion();
            if (scrollTimer) return;
            scrollTimer = setTimeout(function () {
                scrollTimer = null;
                try {
                    var t = e.target === document ? (document.scrollingElement || document.documentElement) : e.target;
                    if (!t || !t.scrollHeight || t.scrollHeight <= t.clientHeight * 1.2) return;
                    var pct = Math.min(100, Math.round(((t.scrollTop + t.clientHeight) / t.scrollHeight) * 100));
                    if (pct > scrollMax) scrollMax = pct;
                } catch (err) { /* nada */ }
            }, 250);
        }, { passive: true, capture: true });

        function enviarPermanencia() {
            var delta = activoMs - activoEnviado;
            if (delta >= 1000) {
                track('permanencia', null, null, delta, scrollMax);
                activoEnviado = activoMs;
            }
        }

        // ---------- clics
        var INTERACTIVO = 'a[href],button,input,select,textarea,summary,[role="button"],[role="tab"],[onclick],.tab,[data-tab],.option-box,.stat-card--clickable,.btn,tr[data-id]';
        var esc = (window.CSS && CSS.escape) ? CSS.escape : function (s) { return String(s).replace(/"/g, '\\"'); };

        function etiqueta(el) {
            var tag = el.tagName.toLowerCase();
            var manual = el.getAttribute('data-analytics');
            if (manual) return texto(manual, 80);
            var t = '';
            if (tag === 'input' || tag === 'select' || tag === 'textarea') {
                var lab = el.id ? document.querySelector('label[for="' + esc(el.id) + '"]') : null;
                t = (lab && lab.textContent) || el.getAttribute('aria-label') || el.getAttribute('placeholder') || el.name || el.id || tag;
                if (tag === 'input' && (el.type === 'button' || el.type === 'submit')) t = el.value || t;
            } else {
                var titulo = el.querySelector && el.querySelector('h1,h2,h3');
                t = el.getAttribute('aria-label') || el.title || (titulo && titulo.textContent) || el.innerText || el.textContent;
                if (!texto(t, 5)) t = el.getAttribute('href') || el.id || (typeof el.className === 'string' ? el.className : '') || tag;
            }
            return texto(t, 80) || tag;
        }

        function descriptor(el) {
            var d = el.tagName.toLowerCase();
            if (el.id) d += '#' + el.id;
            else if (typeof el.className === 'string' && el.className.trim()) d += '.' + el.className.trim().split(/\s+/)[0];
            var t = texto(el.innerText || '', 30);
            return t ? d + ' "' + t + '"' : d;
        }

        function rutaLimpia(href) {
            try {
                var u = new URL(href, location.href);
                if (u.origin !== location.origin) return 'externo: ' + u.hostname;
                return u.pathname.replace(/^.*\/(public\/|backend\/|auth\/)/, '$1').replace(/^\//, '');
            } catch (e) { return ''; }
        }

        var clicsRecientes = [];
        var ultimoRage = 0;
        var ultimoMuerto = { desc: '', t: 0 };
        document.addEventListener('click', function (ev) {
            try {
                marcarAccion();
                var objetivo = ev.target && ev.target.closest ? ev.target : null;
                if (!objetivo) return;
                var el = objetivo.closest(INTERACTIVO);
                var ahora = Date.now();

                if (el) {
                    var esTab = el.matches('.tab,[role="tab"],[data-tab]');
                    var detalle = el.tagName === 'A' ? rutaLimpia(el.getAttribute('href') || '') : '';
                    track(esTab ? 'tab' : 'click', etiqueta(el), detalle);
                } else {
                    var desc = descriptor(objetivo);
                    if (ultimoMuerto.desc === desc && ahora - ultimoMuerto.t < 2000) {
                        track('dead_click', desc);
                        ultimoMuerto = { desc: '', t: 0 };
                    } else {
                        ultimoMuerto = { desc: desc, t: ahora };
                    }
                }

                // clics furiosos: 3+ en 1.2 s dentro de 40 px
                clicsRecientes.push({ t: ahora, x: ev.clientX, y: ev.clientY });
                clicsRecientes = clicsRecientes.filter(function (c) { return ahora - c.t < 1200; });
                var cerca = clicsRecientes.filter(function (c) { return Math.abs(c.x - ev.clientX) < 40 && Math.abs(c.y - ev.clientY) < 40; });
                if (cerca.length >= 3 && ahora - ultimoRage > 2500) {
                    ultimoRage = ahora;
                    track('rage_click', el ? etiqueta(el) : descriptor(objetivo), null, cerca.length);
                }
            } catch (e) { /* silencioso */ }
        }, true);

        // ---------- selects, búsquedas, formularios
        document.addEventListener('change', function (ev) {
            try {
                var el = ev.target;
                if (!el || el.tagName !== 'SELECT') return;
                var opt = el.options && el.options[el.selectedIndex];
                track('cambio', etiqueta(el), opt ? texto(opt.text, 60) : '');
            } catch (e) { /* silencioso */ }
        }, true);

        var busquedaTimers = new WeakMap();
        document.addEventListener('input', function (ev) {
            try {
                var el = ev.target;
                if (!el || el.tagName !== 'INPUT') return;
                var pista = (el.type + ' ' + (el.id || '') + ' ' + (el.name || '') + ' ' + (el.placeholder || '')).toLowerCase();
                if (!/search|busc|filtr/.test(pista)) return;
                clearTimeout(busquedaTimers.get(el));
                busquedaTimers.set(el, setTimeout(function () {
                    track('busqueda', etiqueta(el), null, el.value.length);
                }, 1200));
            } catch (e) { /* silencioso */ }
        }, true);

        document.addEventListener('submit', function (ev) {
            try {
                var f = ev.target;
                track('submit', f.id || f.getAttribute('name') || 'form');
            } catch (e) { /* silencioso */ }
        }, true);

        var ultimaInvalida = {};
        document.addEventListener('invalid', function (ev) {
            try {
                var el = ev.target;
                var clave = etiqueta(el);
                var ahora = Date.now();
                if (ultimaInvalida[clave] && ahora - ultimaInvalida[clave] < 2000) return;
                ultimaInvalida[clave] = ahora;
                var motivos = [];
                for (var k in el.validity) { if (el.validity[k] === true && k !== 'valid') motivos.push(k); }
                track('validacion_fallida', clave, motivos.join(','));
            } catch (e) { /* silencioso */ }
        }, true);

        // ---------- errores
        var erroresEnviados = 0;
        function puedeReportarError() { return ++erroresEnviados <= 20; }
        window.addEventListener('error', function (ev) {
            try {
                if (!puedeReportarError()) return;
                if (ev.target && ev.target !== window && ev.target.tagName) {
                    var src = ev.target.src || ev.target.href || '';
                    track('error_js', 'Recurso no cargó: ' + ev.target.tagName.toLowerCase(), src.split('/').pop().split('?')[0]);
                } else if (ev.message) {
                    track('error_js', ev.message, (ev.filename || '').split('/').pop().split('?')[0] + ':' + (ev.lineno || 0));
                }
            } catch (e) { /* silencioso */ }
        }, true);
        window.addEventListener('unhandledrejection', function (ev) {
            try {
                if (!puedeReportarError()) return;
                var r = ev.reason;
                track('error_js', 'Promesa rechazada: ' + texto((r && r.message) || r, 150));
            } catch (e) { /* silencioso */ }
        });

        // ---------- latencia y errores de la API
        if (origFetch) {
            window.fetch = function (input, init) {
                var promesa = origFetch.apply(window, arguments);
                try {
                    var url = typeof input === 'string' ? input : (input && input.url) || '';
                    if (/(backend|auth)\//.test(url) && !/analytics_registrar|session_check|analytics_panel/.test(url)) {
                        var u = new URL(url, location.href);
                        var nombre = u.pathname.split('/').pop();
                        var accion = u.searchParams.get('action');
                        if (accion) nombre += '?action=' + accion;
                        var metodo = String((init && init.method) || (input && input.method) || 'GET').toUpperCase();
                        var t0 = performance.now();
                        promesa.then(function (r) {
                            track('api', metodo + ' ' + nombre, null, performance.now() - t0, r.status);
                        }, function () {
                            track('api', metodo + ' ' + nombre, null, performance.now() - t0, 0);
                        });
                    }
                } catch (e) { /* silencioso */ }
                return promesa;
            };
        }

        // ---------- envío periódico y al salir
        setInterval(function () {
            if (document.visibilityState === 'visible') enviarPermanencia();
            flush(false);
        }, 15000);
        document.addEventListener('visibilitychange', function () {
            if (document.visibilityState === 'hidden') { enviarPermanencia(); flush(true); }
        });
        window.addEventListener('pagehide', function () { enviarPermanencia(); flush(true); });
    } catch (e) {
        // Nunca romper la página por las analíticas.
    }
})();
