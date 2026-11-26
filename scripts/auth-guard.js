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
    // Escape global para cualquier dato que se pinte con innerHTML
    window.escHTML = escapeHtml;

    var inPublic = /\/public\//.test(window.location.pathname);
    var authPath = inPublic ? '../auth/' : 'auth/';
    var indexPath = inPublic ? '../index.html' : 'index.html';
    // Marca de que este navegador ya inició sesión alguna vez (ver redirección sin sesión)
    var INGRESO_KEY = 'app_ya_entro';

    // ---------- Tema claro / oscuro ----------
    // El tema vive en la cuenta del usuario (usuarios.tema) y se recuerda en
    // cualquier dispositivo. localStorage solo sirve para pintar sin parpadeo
    // antes de que responda el servidor; la fuente de verdad es session_check.php.
    var TEMA_KEY = 'app_tema';
    var temaActual = 'claro';

    function aplicarTema(tema, notificar) {
        tema = tema === 'oscuro' ? 'oscuro' : 'claro';
        var cambio = tema !== temaActual;
        temaActual = tema;
        document.documentElement.setAttribute('data-theme', tema === 'oscuro' ? 'dark' : 'light');
        document.documentElement.setAttribute('data-bs-theme', tema === 'oscuro' ? 'dark' : 'light'); // Bootstrap 5.3 (reportes.html)
        try { localStorage.setItem(TEMA_KEY, tema); } catch (e) { /* sin storage */ }
        if (notificar && cambio) {
            try { window.dispatchEvent(new CustomEvent('app-tema', { detail: { tema: tema } })); } catch (e) { /* nada */ }
        }
    }

    try { aplicarTema(localStorage.getItem(TEMA_KEY), false); } catch (e) { aplicarTema('claro', false); }

    window.appTema = {
        obtener: function () { return temaActual; },
        // Aplica el tema al instante y lo guarda en la cuenta del usuario.
        establecer: function (tema) {
            aplicarTema(tema, true);
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

    // Analíticas de uso (solo registra; el panel es privado, con token)
    try {
        var trackerScript = document.createElement('script');
        trackerScript.src = (inPublic ? '../' : '') + 'scripts/analytics.js?v=20261029';
        trackerScript.async = true;
        document.head.appendChild(trackerScript);
    } catch (e) { /* las analíticas nunca deben romper la página */ }

    // Aviso de "sin conexión a internet" y bloqueo de envíos sin conexión (todas las páginas)
    try {
        var conexionScript = document.createElement('script');
        conexionScript.src = (inPublic ? '../' : '') + 'scripts/conexion.js?v=1';
        conexionScript.async = true;
        document.head.appendChild(conexionScript);
    } catch (e) { /* nunca debe romper la página */ }

    function rastrear(tipo, elemento) {
        try {
            if (window.appTrack) {
                window.appTrack(tipo, elemento);
                if (window.appFlush) window.appFlush();
            } else {
                (window.__appTrackQ = window.__appTrackQ || []).push([tipo, elemento]);
            }
        } catch (e) { /* nada */ }
    }

    // Permite a los scripts de cada página reaccionar al usuario/rol de la
    // sesión sin repetir el fetch a session_check.php. Si ya está listo,
    // invoca el callback de inmediato; si no, lo encola.
    var authState = { ready: false, user: null, modulos: [], empresa: null };
    var authReadyCallbacks = [];
    window.appOnAuthReady = function (callback) {
        if (authState.ready) {
            callback(authState);
        } else {
            authReadyCallbacks.push(callback);
        }
    };
    function marcarAuthListo(user, modulos, empresa) {
        try { localStorage.setItem(INGRESO_KEY, '1'); } catch (e) { /* sin storage */ }
        authState.ready = true;
        authState.user = user;
        authState.modulos = modulos;
        authState.empresa = empresa || null;
        authReadyCallbacks.forEach(function (cb) { cb(authState); });
        authReadyCallbacks = [];
    }

    // Deshabilita, en un <select> de estatus de incidencia, las opciones de
    // cierre (con/sin factura) cuando el usuario en sesión no puede cerrar:
    // rol "Técnico" o usuario sin ese permiso en Usuarios (lo decide
    // session_check.php en "puede_cerrar"). Se deja la opción visible (por si
    // ya era el valor guardado) pero no elegible.
    window.appAplicarRestriccionEstatus = function (selectEl) {
        if (!selectEl) return;
        window.appOnAuthReady(function (auth) {
            var user = auth.user || {};
            var puedeCerrar = typeof user.puede_cerrar === 'boolean' ? user.puede_cerrar : user.rol !== 'Técnico';
            if (puedeCerrar) return;
            // Cerrar o cancelar exige permiso de cierre (RP-12); reabrir también (D-3)
            var finales = ['Cerrado con factura', 'Cerrado sin factura', 'Cancelado'];
            var actual = selectEl.dataset.estatusGuardado || selectEl.value;
            var yaFinal = finales.indexOf(actual) !== -1;
            Array.prototype.forEach.call(selectEl.options, function (opt) {
                if (opt.value === actual) return;
                if (yaFinal || finales.indexOf(opt.value) !== -1) {
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
        'reportes.html': 'reportes',
        'clientes.html': 'clientes',
        'perfil-cliente.html': 'clientes',
        'cotizaciones.html': 'cotizaciones',
        // El técnico abre aquí los borradores que crea desde su incidencia
        'cotizacion.html': ['cotizaciones', 'incidencias'],
        'usuarios.html': 'usuarios',
        'ventas.html': 'ventas',
        'admin-ventas.html': 'ventas',
        'consulta_ventas.html': 'ventas',
        'detalles-venta.html': 'ventas',
        'inventario.html': 'inventario',
        'finanzas.html': 'finanzas',
        'estado-cuenta.html': 'finanzas',
        'soporte.html': 'soporte',
        'informes.html': 'informes',
        'historial.html': 'historial',
        'importar.html': ['clientes', 'inventario'],
    };

    // Ocultar página inmediatamente para evitar flash de contenido no autorizado
    document.documentElement.style.visibility = 'hidden';

    // Aviso fijo según la situación de la empresa (prueba, pago vencido, solo
    // lectura, pago en verificación), con el acceso a Contratar plan para el administrador
    function avisoEmpresa(empresa) {
        if (!empresa || !empresa.mensaje || document.getElementById('aviso-empresa')) return;
        var tipo = empresa.estado === 'prueba' || empresa.estado === 'activa' ? 'info' : 'alerta';
        var aviso = document.createElement('div');
        aviso.id = 'aviso-empresa';
        aviso.className = 'aviso-empresa aviso-empresa--' + tipo;
        aviso.setAttribute('role', 'status');
        var accion = '';
        if (empresa.puede_contratar) {
            accion = ' <a class="aviso-empresa-accion" href="' + authPath + 'contratar.html">' +
                (empresa.verificacion ? 'Ver mi solicitud' : empresa.estado === 'prueba' ? 'Contratar plan' : empresa.estado === 'activa' ? 'Renovar' : 'Pagar ahora') + '</a>';
        }
        aviso.innerHTML = '<i class="fas ' + (tipo === 'info' ? 'fa-hourglass-half' : 'fa-exclamation-triangle') + '"></i> ' + escapeHtml(empresa.mensaje) + accion;
        // Dentro del contenido (la barra lateral es fija y taparía un aviso a todo lo ancho)
        var destino = document.getElementById('mainContent') || document.body;
        destino.insertBefore(aviso, destino.firstChild);
    }

    // Superadministrador dentro de una empresa (panel > Entrar como soporte)
    function avisoSoporte(empresa) {
        if (document.getElementById('aviso-soporte')) return;
        var aviso = document.createElement('div');
        aviso.id = 'aviso-soporte';
        aviso.setAttribute('role', 'status');
        aviso.style.cssText = 'position:fixed;bottom:16px;left:50%;transform:translateX(-50%);z-index:99999;' +
            'display:flex;align-items:center;gap:12px;padding:10px 14px 10px 16px;border-radius:12px;' +
            'background:#1e1b4b;color:#e0e7ff;border:1px solid #6366f1;box-shadow:0 10px 30px rgba(0,0,0,.35);' +
            'font:500 14px/1.3 Inter,"Segoe UI",sans-serif;max-width:calc(100vw - 32px);';
        aviso.innerHTML = '<i class="fas fa-headset"></i><span>Modo soporte' +
            (empresa && empresa.nombre ? ' en <strong>' + escapeHtml(empresa.nombre) + '</strong>' : '') + '</span>' +
            '<a href="' + authPath + 'soporte_salir.php" style="background:#6366f1;color:#fff;padding:6px 12px;border-radius:8px;' +
            'text-decoration:none;font-weight:600;white-space:nowrap">Volver al panel</a>';
        document.body.appendChild(aviso);
    }

    fetch(authPath + 'session_check.php', { cache: 'no-store', credentials: 'same-origin' })
        .then(function (r) {
            if (!r.ok) {
                // 403 = empresa bloqueada: el login muestra el motivo
                return r.json().catch(function () { return {}; }).then(function (j) {
                    try { if (j && j.mensaje) sessionStorage.setItem('aviso_login', j.mensaje); } catch (e) { /* nada */ }
                    // Quien llega a la dirección principal sin haber entrado nunca desde
                    // este navegador es un visitante: ve el sitio de ventas, no el login
                    var yaEntro = false;
                    try { yaEntro = !!localStorage.getItem(INGRESO_KEY); } catch (e) { /* sin storage */ }
                    if (r.status === 401 && !inPublic && !yaEntro) {
                        window.location.replace('soluciones/');
                        return null;
                    }
                    window.location.replace(authPath + 'login.html');
                    return null;
                });
            }
            return r.json();
        })
        .then(function (data) {
            if (!data) return;
            if (data.plataforma) {
                // Cuenta de plataforma (superadministrador): no opera dentro de una empresa
                window.location.replace((inPublic ? '../' : '') + 'plataforma/index.html');
                return;
            }

            var user = data.user || {};
            if (typeof data.puede_cerrar === 'boolean') user.puede_cerrar = data.puede_cerrar;
            user.debe_cambiar_password = !!data.debe_cambiar_password;  // L-13
            aplicarTema(data.tema, true); // el tema de la cuenta manda sobre el recordado en este navegador
            var displayName = user.nombre || user.usuario || 'Usuario';
            var modulosPermitidos = data.modulos || [];

            marcarAuthListo(user, modulosPermitidos, data.empresa);
            if (data.soporte) avisoSoporte(data.empresa);
            else if (data.empresa) avisoEmpresa(data.empresa);

            function puedeAcceder(pagina) {
                var modulo = PAGINA_A_MODULO[pagina];
                // Si la página no está mapeada a ningún módulo, es de acceso libre.
                if (!modulo) return true;
                // Varios módulos: basta con tener uno
                return [].concat(modulo).some(function (m) { return modulosPermitidos.indexOf(m) !== -1; });
            }

            // Si el usuario llega directo a una página que su rol no tiene
            // habilitada (URL directa, marcador, etc.), lo mandamos al inicio
            // antes de mostrar nada. El control real vive en el backend
            // (requirePermiso); esto solo evita una pantalla que de todos
            // modos fallará al llamar a la API.
            var paginaActual = window.location.pathname.split('/').pop();
            // L-13: con la contraseña temporal del panel, primero se cambia en Ajustes
            if (data.debe_cambiar_password && paginaActual !== 'ajustes.html') {
                window.location.replace((inPublic ? '' : 'public/') + 'ajustes.html#seguridad');
                return;
            }
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
                if (nameEl) { nameEl.textContent = displayName; nameEl.title = displayName; }

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

                // Enlace a Inventario (módulo nuevo): se agrega después de Clientes
                // en las barras laterales que no lo traen escrito
                if (puedeAcceder('inventario.html')) {
                    var listaInv = sidebar.querySelector('ul');
                    if (listaInv && !listaInv.querySelector('a[href$="inventario.html"]')) {
                        var invItem = document.createElement('li');
                        var invLink = document.createElement('a');
                        invLink.href = inPublic ? 'inventario.html' : 'public/inventario.html';
                        invLink.title = 'Inventario';
                        invLink.innerHTML =
                            '<i class="fas fa-boxes"></i>' +
                            '<span class="sidebar-link-text">Inventario</span>';
                        invItem.appendChild(invLink);
                        var enlaceClientes = listaInv.querySelector('a[href$="clientes.html"]');
                        var liClientes = enlaceClientes ? enlaceClientes.closest('li') : null;
                        if (liClientes && liClientes.nextSibling) {
                            listaInv.insertBefore(invItem, liClientes.nextSibling);
                        } else {
                            listaInv.appendChild(invItem);
                        }
                    }
                }

                // Mi empresa (plan, uso y marca): solo quien administra la cuenta,
                // antes de Ajustes
                if (data.empresa && data.empresa.administra) {
                    var listaEmp = sidebar.querySelector('ul');
                    if (listaEmp && !listaEmp.querySelector('a[href$="mi-empresa.html"]')) {
                        var empItem = document.createElement('li');
                        var empLink = document.createElement('a');
                        empLink.href = inPublic ? 'mi-empresa.html' : 'public/mi-empresa.html';
                        empLink.title = 'Mi empresa';
                        if (/mi-empresa\.html$/.test(window.location.pathname)) empLink.className = 'active';
                        empLink.innerHTML =
                            '<i class="fas fa-building"></i>' +
                            '<span class="sidebar-link-text">Mi empresa</span>';
                        empItem.appendChild(empLink);
                        var enlaceAjustes = listaEmp.querySelector('a[href$="ajustes.html"]');
                        var liAjustes = enlaceAjustes ? enlaceAjustes.closest('li') : null;
                        if (liAjustes) listaEmp.insertBefore(empItem, liAjustes);
                        else listaEmp.appendChild(empItem);
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
                // Inicio: tarjeta de Mi empresa para quien administra la cuenta
                var opcionEmpresa = document.getElementById('opcion-mi-empresa');
                if (opcionEmpresa && data.empresa && data.empresa.administra) opcionEmpresa.hidden = false;

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
            // Títulos de grupo del menú por áreas que se quedaron vacíos
            if (window.appMenu) window.appMenu.ajustarGrupos();
        })
        .catch(function () {
            // Falla cerrado: sin poder verificar la sesión no se muestra nada
            window.location.replace(authPath + 'login.html');
        });
})();
