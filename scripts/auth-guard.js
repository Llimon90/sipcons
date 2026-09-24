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
    // cierre (con/sin factura) cuando el rol en sesión es "Técnico". Se deja
    // la opción visible (por si ya era el valor guardado) pero no elegible.
    window.sipconsAplicarRestriccionEstatus = function (selectEl) {
        if (!selectEl) return;
        window.sipconsOnAuthReady(function (auth) {
            var rol = auth.user && auth.user.rol;
            if (rol !== 'Técnico') return;
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

    fetch(authPath + 'session_check.php', { cache: 'no-store', credentials: 'same-origin' })
        .then(function (r) {
            if (!r.ok) {
                window.location.replace(authPath + 'login.html');
                return null;
            }
            return r.json();
        })
        .then(function (data) {
            if (!data) return;

            var user = data.user || {};
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
                window.location.replace(indexPath);
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
        })
        .catch(function () {
            // Falla cerrado: sin poder verificar la sesión no se muestra nada
            window.location.replace(authPath + 'login.html');
        });
})();
