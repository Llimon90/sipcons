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

    // Páginas cuyo acceso queda reservado al rol Administrador.
    // El control real vive en el backend (requireRole); esto solo evita
    // que un usuario sin permiso vea una pantalla que de todos modos
    // fallará al llamar a la API.
    var PAGINAS_SOLO_ADMIN = ['usuarios.html', 'historial.html'];

    // Ocultar página inmediatamente para evitar flash de contenido no autorizado
    document.documentElement.style.visibility = 'hidden';

    fetch(authPath + 'session_check.php')
        .then(function (r) {
            if (r.status === 401) {
                window.location.replace(authPath + 'login.html');
                return null;
            }
            return r.json();
        })
        .then(function (data) {
            if (!data) return;

            var user = data.user || {};
            var displayName = user.nombre || user.usuario || 'Usuario';
            var esAdmin = user.rol === 'Administrador';

            // Si un usuario sin permiso llega directo a una página de admin
            // (URL directa, marcador, etc.), lo mandamos al inicio antes de
            // mostrar nada.
            var paginaActual = window.location.pathname.split('/').pop();
            if (!esAdmin && PAGINAS_SOLO_ADMIN.indexOf(paginaActual) !== -1) {
                window.location.replace(indexPath);
                return;
            }

            document.documentElement.style.visibility = '';

            var sidebar = document.querySelector('.sidebar');

            if (sidebar) {
                // Actualizar nombre en sidebar
                var nameEl = sidebar.querySelector('.user-name');
                if (nameEl) nameEl.textContent = displayName;

                // Agregar enlace a Historial (solo Administrador) si el sidebar no lo tiene ya
                if (esAdmin) {
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

            // Ocultar enlaces a secciones de solo-Administrador para el resto de roles
            if (!esAdmin) {
                var enlaces = document.querySelectorAll('a[href]');
                for (var i = 0; i < enlaces.length; i++) {
                    var href = enlaces[i].getAttribute('href') || '';
                    var esEnlaceAdmin = PAGINAS_SOLO_ADMIN.some(function (pagina) {
                        return href.indexOf(pagina) !== -1;
                    });
                    if (esEnlaceAdmin) {
                        var item = enlaces[i].closest('li') || enlaces[i];
                        item.style.display = 'none';
                    }
                }
            }
        })
        .catch(function () {
            // En caso de error de red, mostrar la página igualmente
            document.documentElement.style.visibility = '';
        });
})();
