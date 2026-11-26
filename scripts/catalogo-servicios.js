(function () {
    document.addEventListener('DOMContentLoaded', function () {
        var formServicio = document.getElementById('form-servicio');
        var cuerpoServicios = document.getElementById('cuerpo-servicios');
        var cuerpoSolicitudes = document.getElementById('cuerpo-solicitudes');
        var mensajeServicio = document.getElementById('mensaje-servicio');
        var filtroEstatusSolicitud = document.getElementById('filtro-estatus-solicitud');

        function escapeHtml(v) {
            if (v === null || v === undefined) return '';
            return String(v).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
        }

        function cargarServicios() {
            fetch('../backend/buscar-servicios-catalogo.php', { credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (!data.success || !data.servicios.length) {
                        cuerpoServicios.innerHTML = '<tr><td colspan="5">Sin servicios registrados.</td></tr>';
                        return;
                    }
                    cuerpoServicios.innerHTML = data.servicios.map(function (s) {
                        return '<tr>' +
                            '<td>' + escapeHtml(s.nombre) + '</td>' +
                            '<td>' + escapeHtml(s.categoria || '-') + '</td>' +
                            '<td>' + escapeHtml(s.sla_horas_estimado || '-') + '</td>' +
                            '<td>' + (s.activo == 1 ? 'Sí' : 'No') + '</td>' +
                            '<td><button type="button" class="btn-toggle-servicio" data-id="' + s.id + '" data-activo="' + s.activo + '">' + (s.activo == 1 ? 'Desactivar' : 'Activar') + '</button></td>' +
                            '</tr>';
                    }).join('');
                });
        }

        function cargarSolicitudes() {
            var qs = filtroEstatusSolicitud.value ? '?estatus=' + encodeURIComponent(filtroEstatusSolicitud.value) : '';
            fetch('../backend/buscar-solicitudes-servicio.php' + qs, { credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (!data.success || !data.solicitudes.length) {
                        cuerpoSolicitudes.innerHTML = '<tr><td colspan="7">Sin solicitudes.</td></tr>';
                        return;
                    }
                    cuerpoSolicitudes.innerHTML = data.solicitudes.map(function (s) {
                        return '<tr>' +
                            '<td>' + escapeHtml(s.folio) + '</td>' +
                            '<td>' + escapeHtml(s.servicio_nombre) + '</td>' +
                            '<td>' + escapeHtml(s.cliente) + '</td>' +
                            '<td>' + escapeHtml(s.sucursal || '-') + '</td>' +
                            '<td>' +
                                '<select class="select-estatus-solicitud" data-id="' + s.id + '">' +
                                ['Pendiente', 'Aprobada', 'En proceso', 'Completada', 'Rechazada'].map(function (op) {
                                    return '<option value="' + op + '"' + (op === s.estatus ? ' selected' : '') + '>' + op + '</option>';
                                }).join('') +
                                '</select>' +
                            '</td>' +
                            '<td><input type="text" class="input-tecnico-solicitud" data-id="' + s.id + '" value="' + escapeHtml(s.tecnico_asignado || '') + '" placeholder="Asignar técnico" style="width:120px;"></td>' +
                            '<td><button type="button" class="btn-guardar-solicitud" data-id="' + s.id + '">Guardar</button></td>' +
                            '</tr>';
                    }).join('');
                });
        }

        formServicio.addEventListener('submit', function (e) {
            e.preventDefault();
            mensajeServicio.textContent = 'Guardando...';
            fetch('../backend/guardar-servicio-catalogo.php', {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    nombre: document.getElementById('nombre').value,
                    categoria: document.getElementById('categoria').value,
                    sla_horas_estimado: document.getElementById('sla_horas_estimado').value,
                    descripcion: document.getElementById('descripcion').value,
                }),
            })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    mensajeServicio.textContent = data.success ? 'Servicio guardado.' : (data.message || 'Error al guardar.');
                    if (data.success) {
                        formServicio.reset();
                        cargarServicios();
                    }
                });
        });

        cuerpoServicios.addEventListener('click', function (e) {
            if (!e.target.classList.contains('btn-toggle-servicio')) return;
            var id = e.target.dataset.id;
            var activo = e.target.dataset.activo === '1' ? 0 : 1;
            fetch('../backend/actualiza-servicio-catalogo.php', {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ id: id, nombre: e.target.closest('tr').children[0].textContent, activo: activo }),
            }).then(function () { cargarServicios(); });
        });

        cuerpoSolicitudes.addEventListener('click', function (e) {
            if (!e.target.classList.contains('btn-guardar-solicitud')) return;
            var id = e.target.dataset.id;
            var fila = e.target.closest('tr');
            var estatus = fila.querySelector('.select-estatus-solicitud').value;
            var tecnico = fila.querySelector('.input-tecnico-solicitud').value;
            fetch('../backend/actualiza-solicitud-servicio.php', {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ id: id, estatus: estatus, tecnico_asignado: tecnico }),
            })
                .then(function (r) { return r.json(); })
                .then(function (data) { if (data.success) cargarSolicitudes(); });
        });

        filtroEstatusSolicitud.addEventListener('change', cargarSolicitudes);

        cargarServicios();
        cargarSolicitudes();
    });
})();
