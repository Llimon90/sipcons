(function () {
    document.addEventListener('DOMContentLoaded', function () {
        var formProblema = document.getElementById('form-problema');
        var mensajeProblema = document.getElementById('mensaje-problema');
        var cuerpoProblemas = document.getElementById('cuerpo-problemas');
        var campoBusqueda = document.getElementById('campo-busqueda');
        var filtroEstatus = document.getElementById('filtro-estatus');
        var seccionDetalle = document.getElementById('seccion-detalle-problema');
        var detalleContenido = document.getElementById('detalle-problema-contenido');
        var cuerpoIncidenciasVinculadas = document.getElementById('cuerpo-incidencias-vinculadas');
        var folioAVincular = document.getElementById('folio-incidencia-a-vincular');
        var problemaActivoId = null;

        function escapeHtml(v) {
            if (v === null || v === undefined) return '';
            return String(v).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
        }

        document.getElementById('fecha_deteccion').value = new Date().toISOString().slice(0, 10);

        formProblema.addEventListener('submit', function (e) {
            e.preventDefault();
            var datos = {
                titulo: document.getElementById('titulo').value.trim(),
                descripcion: document.getElementById('descripcion').value.trim(),
                prioridad: document.getElementById('prioridad').value,
                fecha_deteccion: document.getElementById('fecha_deteccion').value,
            };
            mensajeProblema.textContent = 'Guardando...';
            fetch('../backend/guardar-problema.php', {
                method: 'POST', credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(datos)
            }).then(function (r) { return r.json(); }).then(function (j) {
                mensajeProblema.textContent = j.message || (j.success ? ('Problema ' + j.folio + ' guardado.') : 'Error.');
                mensajeProblema.style.color = j.success ? '#27ae60' : '#e74c3c';
                if (j.success) {
                    formProblema.reset();
                    document.getElementById('fecha_deteccion').value = new Date().toISOString().slice(0, 10);
                    cargarProblemas();
                }
            }).catch(function () { mensajeProblema.textContent = 'Sin conexión con el servidor.'; mensajeProblema.style.color = '#e74c3c'; });
        });

        function cargarProblemas() {
            var params = new URLSearchParams();
            if (campoBusqueda.value.trim()) params.set('q', campoBusqueda.value.trim());
            if (filtroEstatus.value) params.set('estatus', filtroEstatus.value);
            fetch('../backend/buscar-problemas.php?' + params.toString(), { credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (problemas) {
                    if (!Array.isArray(problemas) || problemas.length === 0) {
                        cuerpoProblemas.innerHTML = '<tr><td colspan="6">Sin problemas registrados.</td></tr>';
                        return;
                    }
                    cuerpoProblemas.innerHTML = problemas.map(function (p) {
                        return '<tr>' +
                            '<td>' + escapeHtml(p.folio) + '</td>' +
                            '<td>' + escapeHtml(p.titulo) + '</td>' +
                            '<td>' + escapeHtml(p.prioridad) + '</td>' +
                            '<td>' + escapeHtml(p.estatus) + '</td>' +
                            '<td>' + escapeHtml(p.incidencias_vinculadas) + '</td>' +
                            '<td><button type="button" class="btn-ver-problema" data-id="' + p.id + '">Ver / vincular</button></td>' +
                        '</tr>';
                    }).join('');
                })
                .catch(function () { cuerpoProblemas.innerHTML = '<tr><td colspan="6">No se pudo cargar la lista.</td></tr>'; });
        }

        function cargarDetalle(id) {
            fetch('../backend/obtener-problema.php?id=' + encodeURIComponent(id), { credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (j) {
                    if (!j.success) { alert(j.message || 'No se pudo cargar el problema.'); return; }
                    problemaActivoId = j.problema.id;
                    detalleContenido.innerHTML =
                        '<p><strong>' + escapeHtml(j.problema.folio) + '</strong> — ' + escapeHtml(j.problema.titulo) + '</p>' +
                        '<p>Causa raíz: ' + escapeHtml(j.problema.causa_raiz || 'Sin determinar todavía') + '</p>' +
                        '<div class="form-row">' +
                        '<div><label>Causa raíz:</label><input type="text" id="input-causa-raiz" value="' + escapeHtml(j.problema.causa_raiz || '') + '"></div>' +
                        '<div><label>Estatus:</label><select id="input-estatus-problema">' +
                        ['Abierto', 'En análisis', 'Resuelto', 'Cerrado'].map(function (s) {
                            return '<option value="' + s + '"' + (s === j.problema.estatus ? ' selected' : '') + '>' + s + '</option>';
                        }).join('') + '</select></div>' +
                        '<div style="align-self:flex-end;"><button type="button" id="btn-guardar-detalle-problema">Guardar cambios</button></div>' +
                        '</div>';
                    cuerpoIncidenciasVinculadas.innerHTML = (j.problema.incidencias || []).map(function (i) {
                        return '<tr>' +
                            '<td>' + escapeHtml(i.numero_incidente) + '</td>' +
                            '<td>' + escapeHtml(i.cliente) + '</td>' +
                            '<td>' + escapeHtml(i.categoria) + '</td>' +
                            '<td>' + escapeHtml(i.estatus) + '</td>' +
                            '<td><button type="button" class="btn-desvincular" data-incidencia-id="' + i.id + '">Quitar</button></td>' +
                        '</tr>';
                    }).join('') || '<tr><td colspan="5">Sin incidencias vinculadas todavía.</td></tr>';
                    seccionDetalle.style.display = 'block';
                    seccionDetalle.scrollIntoView({ behavior: 'smooth' });
                });
        }

        cuerpoProblemas.addEventListener('click', function (e) {
            if (e.target.classList.contains('btn-ver-problema')) {
                cargarDetalle(e.target.dataset.id);
            }
        });

        detalleContenido.addEventListener('click', function (e) {
            if (e.target.id === 'btn-guardar-detalle-problema') {
                fetch('../backend/actualiza-problema.php', {
                    method: 'POST', credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        id: problemaActivoId,
                        causa_raiz: document.getElementById('input-causa-raiz').value.trim(),
                        estatus: document.getElementById('input-estatus-problema').value,
                    })
                }).then(function (r) { return r.json(); }).then(function (j) {
                    if (j.success) { cargarDetalle(problemaActivoId); cargarProblemas(); }
                    else alert(j.message || 'No se pudo guardar.');
                });
            }
        });

        document.getElementById('btn-vincular-incidencia').addEventListener('click', function () {
            var folio = folioAVincular.value.trim();
            if (!folio || !problemaActivoId) return;
            fetch('../backend/vincular-incidencia-problema.php', {
                method: 'POST', credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ problema_id: problemaActivoId, numero_incidente: folio })
            }).then(function (r) { return r.json(); }).then(function (j) {
                if (j.success) { folioAVincular.value = ''; cargarDetalle(problemaActivoId); cargarProblemas(); }
                else alert(j.message || 'No se pudo vincular.');
            });
        });

        cuerpoIncidenciasVinculadas.addEventListener('click', function (e) {
            if (e.target.classList.contains('btn-desvincular')) {
                fetch('../backend/desvincular-incidencia-problema.php', {
                    method: 'POST', credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ problema_id: problemaActivoId, incidencia_id: e.target.dataset.incidenciaId })
                }).then(function (r) { return r.json(); }).then(function (j) {
                    if (j.success) { cargarDetalle(problemaActivoId); cargarProblemas(); }
                });
            }
        });

        document.getElementById('btn-cerrar-detalle-problema').addEventListener('click', function () {
            seccionDetalle.style.display = 'none';
            problemaActivoId = null;
        });

        campoBusqueda.addEventListener('input', function () { clearTimeout(window.__probTimer); window.__probTimer = setTimeout(cargarProblemas, 300); });
        filtroEstatus.addEventListener('change', cargarProblemas);

        cargarProblemas();
    });
})();
