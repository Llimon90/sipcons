(function () {
    function escapeHtml(valor) {
        if (valor === null || valor === undefined) return '';
        return String(valor)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    var inPublic = /\/public\//.test(window.location.pathname);
    var authPath = inPublic ? '../auth/' : 'auth/';
    var indexPath = inPublic ? '../index.html' : 'index.html';

    // ---------- Tema claro / oscuro ----------
    // El tema vive en la cuenta del usuario (usuarios.tema) y se recuerda en
    // cualquier dispositivo. localStorage solo sirve para pintar sin parpadeo
    // antes de que responda el servidor; la fuente de verdad es session_check.php.
    var TEMA_KEY = 'sipcons_tema';
    var temaActual = 'claro';

    function aplicarTema(tema, notificar) {
        tema = tema === 'oscuro' ? 'oscuro' : 'claro';
        var cambio = tema !== temaActual;
        temaActual = tema;
        document.documentElement.setAttribute('data-theme', tema === 'oscuro' ? 'dark' : 'light');
        document.documentElement.setAttribute('data-bs-theme', tema === 'oscuro' ? 'dark' : 'light'); // Bootstrap 5.3 (reportes.html)
        try { localStorage.setItem(TEMA_KEY, tema); } catch (e) { /* sin storage */ }
        if (notificar && cambio) {
            try { window.dispatchEvent(new CustomEvent('sipcons-tema', { detail: { tema: tema } })); } catch (e) { /* nada */ }
        }
    }

    try { aplicarTema(localStorage.getItem(TEMA_KEY), false); } catch (e) { aplicarTema('claro', false); }

    window.sipconsTema = {
        obtener: function () { return temaActual; },
        // Aplica el tema al instante y lo guarda en la cuenta del usuario.
        establecer: function (tema) {
            aplicarTema(tema, true);
            actualizarCache({ tema: temaActual });
            return fetch((inPublic ? '../' : '') + 'backend/guardar_tema.php', {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ tema: temaActual })
            }).then(function (r) { return r.json().catch(function () { return {}; }); })
              .then(function (j) { return { guardado: !!j.success, mensaje: j.message || '' }; })
              .catch(function () { return { guardado: false, mensaje: 'Sin conexión: el tema se aplicó solo en este dispositivo.' }; });
        }
    };

    // ---------- Caché de la sesión (sessionStorage, por pestaña) ----------
    // session_check.php se consultaba en CADA carga de página. Ahora su
    // respuesta se reutiliza durante AUTH_TTL_MS en la misma pestaña. La
    // seguridad no cambia: cada endpoint del backend sigue validando la sesión
    // en el servidor (requireAuth); esto solo decide qué menú/página pintar.
    // Se borra al cerrar sesión, al entrar al login y ante cualquier 401.
    var AUTH_CACHE_KEY = 'sipcons_auth';
    var AUTH_TTL_MS = 5 * 60 * 1000;             // se usa sin preguntar al servidor
    var AUTH_RESPALDO_MS = 12 * 60 * 60 * 1000;  // respaldo si el servidor no responde

    function leerCache() {
        try {
            var o = JSON.parse(sessionStorage.getItem(AUTH_CACHE_KEY) || 'null');
            if (o && o.t && o.data && o.data.authenticated === true) return o;
        } catch (e) { /* sin storage */ }
        return null;
    }
    function guardarCache(data) {
        try { sessionStorage.setItem(AUTH_CACHE_KEY, JSON.stringify({ t: Date.now(), data: data })); } catch (e) { /* sin storage */ }
    }
    function actualizarCache(cambios) {
        var o = leerCache();
        if (!o) return;
        for (var k in cambios) { if (Object.prototype.hasOwnProperty.call(cambios, k)) o.data[k] = cambios[k]; }
        try { sessionStorage.setItem(AUTH_CACHE_KEY, JSON.stringify(o)); } catch (e) { /* sin storage */ }
    }
    function borrarCache() {
        try {
            sessionStorage.removeItem(AUTH_CACHE_KEY);
            sessionStorage.removeItem('sipcons_tecnicos'); // caché de scripts/tecnicos.js
        } catch (e) { /* sin storage */ }
    }
    function irAlLogin() {
        borrarCache();
        window.location.replace(authPath + 'login.html');
    }
    window.SipconsAuth = { borrarCache: borrarCache, irAlLogin: irAlLogin };

    // Cualquier enlace de "Cerrar sesión" (inyectado o fijo en el HTML) limpia la caché
    document.addEventListener('click', function (e) {
        var a = e.target && e.target.closest ? e.target.closest('a[href*="logout.php"]') : null;
        if (a) borrarCache();
    }, true);

    // Analíticas de uso (solo registra; el panel es exclusivo del Programador).
    // Se cargan cuando session_check confirma que están habilitadas
    // (ANALYTICS_ENABLED en el .env); así un "apagado" no genera ni una petición.
    var analyticsCargado = false;
    function cargarAnalytics() {
        if (analyticsCargado) return;
        analyticsCargado = true;
        try {
            var trackerScript = document.createElement('script');
            trackerScript.src = (inPublic ? '../' : '') + 'scripts/analytics.js?v=3';
            trackerScript.async = true;
            document.head.appendChild(trackerScript);
        } catch (e) { /* las analíticas nunca deben romper la página */ }
    }

    // Aviso de "sin conexión a internet" y bloqueo de envíos sin conexión (todas las páginas)
    try {
        var conexionScript = document.createElement('script');
        conexionScript.src = (inPublic ? '../' : '') + 'scripts/conexion.js?v=2';
        conexionScript.async = true;
        document.head.appendChild(conexionScript);
    } catch (e) { /* nunca debe romper la página */ }

    function rastrear(tipo, elemento) {
        try {
            if (window.sipconsTrack) {
                window.sipconsTrack(tipo, elemento);
                if (window.sipconsFlush) window.sipconsFlush();
            } else {
                (window.__sipconsTrackQ = window.__sipconsTrackQ || []).push([tipo, elemento]);
            }
        } catch (e) { /* nada */ }
    }

    // Permite a los scripts de cada página reaccionar al usuario/rol de la
    // sesión sin repetir el fetch a session_check.php. Si ya está listo,
    // invoca el callback de inmediato; si no, lo encola.
    var authState = { ready: false, user: null, modulos: [] };
    var authReadyCallbacks = [];
    window.sipconsOnAuthReady = function (callback) {
        if (authState.ready) {
            callback(authState);
        } else {
            authReadyCallbacks.push(callback);
        }
    };
    function marcarAuthListo(user, modulos) {
        authState.ready = true;
        authState.user = user;
        authState.modulos = modulos;
        authReadyCallbacks.forEach(function (cb) { cb(authState); });
        authReadyCallbacks = [];
    }

    // Deshabilita, en un <select> de estatus de incidencia, las opciones de
    // cierre (con/sin factura) cuando el usuario en sesión no puede cerrar:
    // rol "Técnico" o usuario restringido en el .env del servidor (lo decide
    // session_check.php en "puede_cerrar"). Se deja la opción visible (por si
    // ya era el valor guardado) pero no elegible.
    window.sipconsAplicarRestriccionEstatus = function (selectEl) {
        if (!selectEl) return;
        window.sipconsOnAuthReady(function (auth) {
            var user = auth.user || {};
            var puedeCerrar = typeof user.puede_cerrar === 'boolean' ? user.puede_cerrar : user.rol !== 'Técnico';
            if (puedeCerrar) return;
            var restringidos = ['Cerrado con factura', 'Cerrado sin factura'];
            Array.prototype.forEach.call(selectEl.options, function (opt) {
                if (restringidos.indexOf(opt.value) !== -1) {
                    opt.disabled = true;
                }
            });
        });
    };

    // Qué módulo (de la tabla permisos_rol) protege cada página. Una página
    // que no aparece aquí (ajustes.html, index.html) es accesible para
    // cualquier usuario con sesión iniciada.
    var PAGINA_A_MODULO = {
        'incidencias.html': 'incidencias',
        'detalle.html': 'incidencias',
        'incidencias_general.html': 'incidencias',
        'reportes.html': 'reportes',
        'clientes.html': 'clientes',
        'perfil-cliente.html': 'clientes',
        'usuarios.html': 'usuarios',
        'ventas.html': 'ventas',
        'admin-ventas.html': 'ventas',
        'consulta_ventas.html': 'ventas',
        'detalle-venta.html': 'ventas',
        'detalles-venta.html': 'ventas',
        'soporte.html': 'soporte',
        'informes.html': 'informes',
        'historial.html': 'historial',
    };

    // Ocultar página inmediatamente para evitar flash de contenido no autorizado
    document.documentElement.style.visibility = 'hidden';

    // Ejecuta fn cuando exista el DOM (con caché la sesión está lista antes
    // de que el navegador termine de leer el HTML).
    function alDom(fn) {
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', fn, { once: true });
        } else {
            fn();
        }
    }

    // ---------- Aviso "el servidor no responde" (nunca expulsa al usuario) ----------
    var avisoServidor = null;
    function mostrarAvisoServidor(texto, conBoton) {
        alDom(function () {
            if (!avisoServidor) {
                avisoServidor = document.createElement('div');
                avisoServidor.setAttribute('role', 'alert');
                // visibility: visible para verse aunque la página siga oculta
                avisoServidor.style.cssText =
                    'visibility:visible;position:fixed;left:50%;top:24px;transform:translateX(-50%);z-index:2147483000;' +
                    'max-width:min(560px,calc(100% - 32px));box-sizing:border-box;padding:14px 18px;border-radius:10px;' +
                    'background:#b45309;color:#fff;font:15px/1.4 system-ui,-apple-system,Segoe UI,Roboto,sans-serif;' +
                    'box-shadow:0 8px 24px rgba(0,0,0,.25);display:flex;gap:12px;align-items:center;flex-wrap:wrap;';
                avisoServidor.innerHTML =
                    '<span class="aviso-servidor-texto" style="flex:1 1 240px"></span>' +
                    '<button type="button" style="visibility:visible;background:rgba(255,255,255,.2);color:#fff;border:1px solid rgba(255,255,255,.5);' +
                    'border-radius:6px;padding:6px 12px;cursor:pointer;font:inherit">Reintentar ahora</button>';
                avisoServidor.querySelector('button').addEventListener('click', function () {
                    clearTimeout(temporizadorReintento);
                    verificarSesion(intentoActual);
                });
                document.body.appendChild(avisoServidor);
            }
            avisoServidor.querySelector('.aviso-servidor-texto').textContent = texto;
            avisoServidor.querySelector('button').style.display = conBoton ? '' : 'none';
            avisoServidor.style.display = 'flex';
        });
    }
    function ocultarAvisoServidor() {
        if (avisoServidor) avisoServidor.style.display = 'none';
    }

    // ---------- Verificación de sesión ----------
    // SOLO un 401 (o una respuesta que diga authenticated:false) manda al
    // login. Un 502/503/504, una respuesta ilegible o un fallo de red son
    // problemas temporales del servidor: se reintenta con espera creciente y,
    // si la pestaña ya tenía la sesión verificada, se sigue trabajando con ella.
    var RETRASOS_MS = [2000, 5000, 10000, 20000, 30000];
    var MAX_INTENTOS_AUTO = RETRASOS_MS.length;
    var intentoActual = 0;
    var temporizadorReintento = null;
    var sesionAplicada = false;

    function verificarSesion(intento) {
        intentoActual = intento;
        var control = window.AbortController ? new AbortController() : null;
        var limite = setTimeout(function () { if (control) control.abort(); }, 20000);

        fetch(authPath + 'session_check.php', {
            cache: 'no-store',
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json' },
            signal: control ? control.signal : undefined
        })
            .then(function (r) {
                clearTimeout(limite);
                if (r.status === 401) return { authenticated: false };
                if (!r.ok) throw new Error('HTTP ' + r.status);
                return r.json().catch(function () { throw new Error('Respuesta ilegible (HTTP ' + r.status + ')'); });
            })
            .then(function (data) {
                if (!data || data.authenticated !== true) {
                    irAlLogin(); // sesión inválida confirmada por el servidor
                    return;
                }
                guardarCache(data);
                ocultarAvisoServidor();
                if (sesionAplicada) {
                    aplicarTema(data.tema, true); // la página ya está pintada (con el respaldo)
                } else {
                    alDom(function () { aplicarSesion(data); });
                }
            })
            .catch(function (err) {
                clearTimeout(limite);
                falloTemporal(intento, err);
            });
    }

    function falloTemporal(intento, err) {
        try { console.warn('session_check no respondió:', err && err.message ? err.message : err); } catch (e) { /* nada */ }

        // Respaldo: la última sesión verificada en esta pestaña (máx. 12 h)
        var respaldo = leerCache();
        if (!sesionAplicada && respaldo && Date.now() - respaldo.t < AUTH_RESPALDO_MS) {
            alDom(function () { aplicarSesion(respaldo.data); });
        }

        // Si ya se pinta con el respaldo, basta con 2 reintentos en segundo
        // plano: no cargar más a un servidor que está saturado.
        if (intento + 1 >= (sesionAplicada ? 2 : MAX_INTENTOS_AUTO)) {
            if (!sesionAplicada) {
                mostrarAvisoServidor('El servidor no está respondiendo. Tu sesión sigue abierta; vuelve a intentarlo en un momento.', true);
            } else {
                ocultarAvisoServidor(); // con respaldo se sigue trabajando; no insistir
            }
            return;
        }

        var espera = RETRASOS_MS[intento];
        if (!sesionAplicada) {
            mostrarAvisoServidor('El servidor tarda en responder. Reintentando en ' + Math.round(espera / 1000) + ' s… (tu sesión sigue abierta)', true);
        }
        temporizadorReintento = setTimeout(function () { verificarSesion(intento + 1); }, espera);
    }

    var cacheInicial = leerCache();
    if (cacheInicial && Date.now() - cacheInicial.t < AUTH_TTL_MS) {
        alDom(function () { aplicarSesion(cacheInicial.data); });
    } else {
        verificarSesion(0);
    }

    function aplicarSesion(data) {
        if (sesionAplicada) return;
        sesionAplicada = true;
        try {
            if (data.analytics !== false) cargarAnalytics();

            var user = data.user || {};
            if (typeof data.puede_cerrar === 'boolean') user.puede_cerrar = data.puede_cerrar;
            aplicarTema(data.tema, true); // el tema de la cuenta manda sobre el recordado en este navegador
            var displayName = user.nombre || user.usuario || 'Usuario';
            var modulosPermitidos = data.modulos || [];

            marcarAuthListo(user, modulosPermitidos);

            function puedeAcceder(pagina) {
                var modulo = PAGINA_A_MODULO[pagina];
                // Si la página no está mapeada a ningún módulo, es de acceso libre.
                if (!modulo) return true;
                return modulosPermitidos.indexOf(modulo) !== -1;
            }

            // Si el usuario llega directo a una página que su rol no tiene
            // habilitada (URL directa, marcador, etc.), lo mandamos al inicio
            // antes de mostrar nada. El control real vive en el backend
            // (requirePermiso); esto solo evita una pantalla que de todos
            // modos fallará al llamar a la API.
            var paginaActual = window.location.pathname.split('/').pop();
            if (!puedeAcceder(paginaActual)) {
                rastrear('acceso_denegado', paginaActual);
                setTimeout(function () { window.location.replace(indexPath); }, 300);
                return;
            }

            document.documentElement.style.visibility = '';

            var sidebar = document.querySelector('.sidebar');

            if (sidebar) {
                // Actualizar nombre en sidebar
                var nameEl = sidebar.querySelector('.user-name');
                if (nameEl) nameEl.textContent = displayName;

                // Agregar enlace a Historial si el rol tiene ese módulo y el
                // sidebar de esta página no lo trae ya
                if (puedeAcceder('historial.html')) {
                    var lista = sidebar.querySelector('ul');
                    if (lista && !lista.querySelector('a[href$="historial.html"]')) {
                        var histItem = document.createElement('li');
                        var histLink = document.createElement('a');
                        histLink.href = 'historial.html';
                        histLink.title = 'Historial de cambios';
                        histLink.innerHTML =
                            '<i class="fas fa-history"></i>' +
                            '<span class="sidebar-link-text">Historial</span>';
                        histItem.appendChild(histLink);

                        var enlaceUsuarios = lista.querySelector('a[href$="usuarios.html"]');
                        var liUsuarios = enlaceUsuarios ? enlaceUsuarios.closest('li') : null;
                        if (liUsuarios && liUsuarios.nextSibling) {
                            lista.insertBefore(histItem, liUsuarios.nextSibling);
                        } else {
                            lista.appendChild(histItem);
                        }
                    }
                }

                // Agregar botón de cerrar sesión al footer si no existe
                var footer = sidebar.querySelector('.sidebar-footer');
                if (footer && !footer.querySelector('.logout-btn')) {
                    var logoutLink = document.createElement('a');
                    logoutLink.href = authPath + 'logout.php';
                    logoutLink.className = 'logout-btn';
                    logoutLink.title = 'Cerrar sesión';
                    logoutLink.innerHTML =
                        '<i class="fas fa-sign-out-alt"></i>' +
                        '<span class="sidebar-link-text">Cerrar sesión</span>';
                    footer.appendChild(logoutLink);
                }
            } else {
                // Sin sidebar (index.html): inyectar barra de usuario arriba a la derecha
                var bar = document.createElement('div');
                bar.id = 'user-top-bar';
                bar.innerHTML =
                    '<span id="user-top-name"><i class="fas fa-user-circle"></i> ' +
                    escapeHtml(displayName) + '</span>' +
                    '<a href="' + authPath + 'logout.php" class="logout-top-btn" title="Cerrar sesión">' +
                    '<i class="fas fa-sign-out-alt"></i> Cerrar sesión</a>';
                document.body.insertBefore(bar, document.body.firstChild);
            }

            // Ocultar enlaces a secciones que el rol de este usuario no tiene habilitadas
            var enlaces = document.querySelectorAll('a[href]');
            for (var i = 0; i < enlaces.length; i++) {
                var href = enlaces[i].getAttribute('href') || '';
                var paginaEnlace = href.split('/').pop().split('?')[0];
                if (PAGINA_A_MODULO[paginaEnlace] && !puedeAcceder(paginaEnlace)) {
                    var item = enlaces[i].closest('li') || enlaces[i];
                    item.style.display = 'none';
                }
            }
        } catch (e) {
            // Un error al pintar el menú no debe dejar la página invisible
            try { console.error('auth-guard:', e); } catch (e2) { /* nada */ }
            document.documentElement.style.visibility = '';
        }
    }
})();
