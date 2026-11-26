(function () {
    document.addEventListener('DOMContentLoaded', function () {
        var formCambio = document.getElementById('form-cambio');
        var mensajeCambio = document.getElementById('mensaje-cambio');
        var cuerpoCambios = document.getElementById('cuerpo-cambios');
        var campoBusqueda = document.getElementById('campo-busqueda');
        var filtroEstatus = document.getElementById('filtro-estatus');

        function escapeHtml(v) {
            if (v === null || v === undefined) return '';
            return String(v).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
        }

        formCambio.addEventListener('submit', function (e) {
            e.preventDefault();
            var datos = {
                titulo: document.getElementById('titulo').value.trim(),
                descripcion: document.getElementById('descripcion').value.trim(),
                tipo: document.getElementById('tipo').value,
                riesgo: document.getElementById('riesgo').value,
                fecha_planeada: document.getElementById('fecha_planeada').value,
                notas: document.getElementById('notas').value.trim(),
            };
            mensajeCambio.textContent = 'Guardando...';
            fetch('../backend/guardar-cambio.php', {
                method: 'POST', credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(datos)
            }).then(function (r) { return r.json(); }).then(function (j) {
                mensajeCambio.textContent = j.message || (j.success ? ('Cambio ' + j.folio + ' enviado a aprobación.') : 'Error.');
                mensajeCambio.style.color = j.success ? '#27ae60' : '#e74c3c';
                if (j.success) { formCambio.reset(); cargarCambios(); }
            }).catch(function () { mensajeCambio.textContent = 'Sin conexión con el servidor.'; mensajeCambio.style.color = '#e74c3c'; });
        });

        function cargarCambios() {
            var params = new URLSearchParams();
            if (campoBusqueda.value.trim()) params.set('q', campoBusqueda.value.trim());
            if (filtroEstatus.value) params.set('estatus', filtroEstatus.value);
            fetch('../backend/buscar-cambios.php?' + params.toString(), { credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (cambios) {
                    if (!Array.isArray(cambios) || cambios.length === 0) {
                        cuerpoCambios.innerHTML = '<tr><td colspan="6">Sin cambios registrados.</td></tr>';
                        return;
                    }
                    cuerpoCambios.innerHTML = cambios.map(function (c) {
                        var acciones = '';
                        if (c.estatus === 'Pendiente') {
                            acciones = '<button type="button" class="btn-aprobar-cambio" data-id="' + c.id + '">Aprobar</button> ' +
                                       '<button type="button" class="btn-rechazar-cambio" data-id="' + c.id + '">Rechazar</button>';
                        } else if (c.estatus === 'Aprobado') {
                            acciones = '<button type="button" class="btn-implementado" data-id="' + c.id + '">Marcar implementado</button>';
                        } else if (c.estatus === 'Implementado') {
                            acciones = '<button type="button" class="btn-cerrar-cambio" data-id="' + c.id + '">Cerrar</button>';
                        }
                        return '<tr>' +
                            '<td>' + escapeHtml(c.folio) + '</td>' +
                            '<td>' + escapeHtml(c.titulo) + '</td>' +
                            '<td>' + escapeHtml(c.tipo) + '</td>' +
                            '<td>' + escapeHtml(c.riesgo) + '</td>' +
                            '<td>' + escapeHtml(c.estatus) + (c.aprobador ? ' (por ' + escapeHtml(c.aprobador) + ')' : '') + '</td>' +
                            '<td>' + acciones + '</td>' +
                        '</tr>';
                    }).join('');
                })
                .catch(function () { cuerpoCambios.innerHTML = '<tr><td colspan="6">No se pudo cargar la lista.</td></tr>'; });
        }

        cuerpoCambios.addEventListener('click', function (e) {
            var id = e.target.dataset ? e.target.dataset.id : null;
            if (!id) return;

            if (e.target.classList.contains('btn-aprobar-cambio') || e.target.classList.contains('btn-rechazar-cambio')) {
                var decision = e.target.classList.contains('btn-aprobar-cambio') ? 'Aprobado' : 'Rechazado';
                var comentario = decision === 'Rechazado' ? (prompt('Motivo del rechazo (opcional):') || '') : '';
                fetch('../backend/aprobar-cambio.php', {
                    method: 'POST', credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ id: id, decision: decision, comentario: comentario })
                }).then(function (r) { return r.json(); }).then(function (j) {
                    if (j.success) cargarCambios(); else alert(j.message || 'No se pudo registrar la decisión.');
                });
            }

            if (e.target.classList.contains('btn-implementado') || e.target.classList.contains('btn-cerrar-cambio')) {
                var estatus = e.target.classList.contains('btn-implementado') ? 'Implementado' : 'Cerrado';
                fetch('../backend/actualiza-cambio.php', {
                    method: 'POST', credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ id: id, estatus: estatus })
                }).then(function (r) { return r.json(); }).then(function (j) {
                    if (j.success) cargarCambios(); else alert(j.message || 'No se pudo actualizar.');
                });
            }
        });

        campoBusqueda.addEventListener('input', function () { clearTimeout(window.__camTimer); window.__camTimer = setTimeout(cargarCambios, 300); });
        filtroEstatus.addEventListener('change', cargarCambios);

        cargarCambios();
    });
})();
