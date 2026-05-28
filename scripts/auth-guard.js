(function () {
    var inPublic = /\/public\//.test(window.location.pathname);
    var authPath = inPublic ? '../auth/' : 'auth/';

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

            document.documentElement.style.visibility = '';

            var user = data.user || {};
            var displayName = user.nombre || user.usuario || 'Usuario';

            var sidebar = document.querySelector('.sidebar');

            if (sidebar) {
                // Actualizar nombre en sidebar
                var nameEl = sidebar.querySelector('.user-name');
                if (nameEl) nameEl.textContent = displayName;

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
                    displayName + '</span>' +
                    '<a href="' + authPath + 'logout.php" class="logout-top-btn" title="Cerrar sesión">' +
                    '<i class="fas fa-sign-out-alt"></i> Cerrar sesión</a>';
                document.body.insertBefore(bar, document.body.firstChild);
            }
        })
        .catch(function () {
            // En caso de error de red, mostrar la página igualmente
            document.documentElement.style.visibility = '';
        });
})();
