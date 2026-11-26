(function () {
    document.addEventListener('DOMContentLoaded', function () {
        var form = document.getElementById('form-articulo');
        var cuerpoArticulos = document.getElementById('cuerpo-articulos');
        var mensaje = document.getElementById('mensaje-articulo');
        var campoBusqueda = document.getElementById('campo-busqueda');
        var btnNuevo = document.getElementById('btn-nuevo-articulo');

        function escapeHtml(v) {
            if (v === null || v === undefined) return '';
            return String(v).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
        }

        function limpiarFormulario() {
            form.reset();
            document.getElementById('articulo-id').value = '';
        }

        function cargarArticulos() {
            var qs = campoBusqueda.value ? '?q=' + encodeURIComponent(campoBusqueda.value) : '';
            fetch('../backend/buscar-articulos-kb.php' + qs, { credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (!data.success || !data.articulos.length) {
                        cuerpoArticulos.innerHTML = '<tr><td colspan="5">Sin artículos.</td></tr>';
                        return;
                    }
                    cuerpoArticulos.innerHTML = data.articulos.map(function (a) {
                        return '<tr>' +
                            '<td>' + escapeHtml(a.titulo) + '</td>' +
                            '<td>' + escapeHtml(a.categoria || '-') + '</td>' +
                            '<td>' + escapeHtml(a.visibilidad) + '</td>' +
                            '<td>' + escapeHtml(a.vistas) + '</td>' +
                            '<td>' +
                                '<button type="button" class="btn-editar-articulo" data-id="' + a.id + '">Editar</button> ' +
                                '<button type="button" class="btn-eliminar-articulo" data-id="' + a.id + '">Eliminar</button>' +
                            '</td>' +
                            '</tr>';
                    }).join('');
                });
        }

        form.addEventListener('submit', function (e) {
            e.preventDefault();
            var id = document.getElementById('articulo-id').value;
            var payload = {
                titulo: document.getElementById('titulo').value,
                categoria: document.getElementById('categoria').value,
                visibilidad: document.getElementById('visibilidad').value,
                contenido: document.getElementById('contenido').value,
            };
            var url = id ? '../backend/actualiza-articulo-kb.php' : '../backend/guardar-articulo-kb.php';
            if (id) payload.id = id;

            mensaje.textContent = 'Guardando...';
            fetch(url, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload),
            })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    mensaje.textContent = data.success ? 'Artículo guardado.' : (data.message || 'Error al guardar.');
                    if (data.success) {
                        limpiarFormulario();
                        cargarArticulos();
                    }
                });
        });

        cuerpoArticulos.addEventListener('click', function (e) {
            var id = e.target.dataset.id;
            if (!id) return;

            if (e.target.classList.contains('btn-editar-articulo')) {
                fetch('../backend/obtener-articulo-kb.php?id=' + id, { credentials: 'same-origin' })
                    .then(function (r) { return r.json(); })
                    .then(function (data) {
                        if (!data.success) return;
                        var a = data.articulo;
                        document.getElementById('articulo-id').value = a.id;
                        document.getElementById('titulo').value = a.titulo;
                        document.getElementById('categoria').value = a.categoria || '';
                        document.getElementById('visibilidad').value = a.visibilidad;
                        document.getElementById('contenido').value = a.contenido;
                        window.scrollTo(0, 0);
                    });
            }

            if (e.target.classList.contains('btn-eliminar-articulo')) {
                if (!confirm('¿Eliminar este artículo?')) return;
                fetch('../backend/eliminar-articulo-kb.php', {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ id: id }),
                })
                    .then(function (r) { return r.json(); })
                    .then(function (data) { if (data.success) cargarArticulos(); });
            }
        });

        btnNuevo.addEventListener('click', limpiarFormulario);
        campoBusqueda.addEventListener('input', cargarArticulos);

        cargarArticulos();
    });
})();
