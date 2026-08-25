(function () {
    var paginaActual = 1;

    function escapeHtml(valor) {
        if (valor === null || valor === undefined) return '';
        return String(valor)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    function etiquetaAccion(accion) {
        var mapa = { CREATE: 'Creación', UPDATE: 'Edición', DELETE: 'Eliminación' };
        return mapa[accion] || accion;
    }

    function formatearFecha(valor) {
        if (!valor) return '';
        var fecha = new Date(valor.replace(' ', 'T'));
        if (isNaN(fecha.getTime())) return valor;
        return fecha.toLocaleString('es-MX');
    }

    function construirQuery(pagina) {
        var params = new URLSearchParams();
        params.set('pagina', pagina);

        var tabla = document.getElementById('f-tabla').value.trim();
        var accion = document.getElementById('f-accion').value.trim();
        var registroId = document.getElementById('f-registro').value.trim();
        var desde = document.getElementById('f-desde').value.trim();
        var hasta = document.getElementById('f-hasta').value.trim();

        if (tabla) params.set('tabla', tabla);
        if (accion) params.set('accion', accion);
        if (registroId) params.set('registro_id', registroId);
        if (desde) params.set('desde', desde);
        if (hasta) params.set('hasta', hasta);

        return params.toString();
    }

    function identificadorRegistro(registro) {
        return registro.registro_folio || registro.registro_id;
    }

    function mostrarDetalle(registro) {
        var contenedor = document.getElementById('detalle-historial-contenido');
        var antes = registro.datos_anteriores ? JSON.stringify(registro.datos_anteriores, null, 2) : '(sin datos previos)';
        var despues = registro.datos_nuevos ? JSON.stringify(registro.datos_nuevos, null, 2) : '(sin datos nuevos)';

        contenedor.innerHTML =
            '<p><strong>Usuario:</strong> ' + escapeHtml(registro.usuario_nombre || '—') + ' (' + escapeHtml(registro.usuario_rol || '—') + ')</p>' +
            '<p><strong>Acción:</strong> ' + escapeHtml(etiquetaAccion(registro.accion)) + ' sobre ' + escapeHtml(registro.tabla) + ' #' + escapeHtml(identificadorRegistro(registro)) + '</p>' +
            '<p><strong>Fecha:</strong> ' + escapeHtml(formatearFecha(registro.creado_en)) + '</p>' +
            '<p><strong>IP:</strong> ' + escapeHtml(registro.ip_address || '—') + '</p>' +
            '<div class="form-row">' +
            '<div><label>Antes</label><pre style="background:#f4f4f4; padding:10px; border-radius:4px; overflow:auto; max-height:300px;">' + escapeHtml(antes) + '</pre></div>' +
            '<div><label>Después</label><pre style="background:#f4f4f4; padding:10px; border-radius:4px; overflow:auto; max-height:300px;">' + escapeHtml(despues) + '</pre></div>' +
            '</div>';

        document.getElementById('modal-detalle-historial').style.display = 'block';
    }

    function renderPaginacion(data) {
        var cont = document.getElementById('historial-paginacion');
        cont.innerHTML = '';
        if (data.total_paginas <= 1) return;

        var info = document.createElement('span');
        info.textContent = 'Página ' + data.pagina + ' de ' + data.total_paginas + ' (' + data.total + ' registros)';
        cont.appendChild(info);

        if (data.pagina > 1) {
            var btnPrev = document.createElement('button');
            btnPrev.type = 'button';
            btnPrev.textContent = 'Anterior';
            btnPrev.onclick = function () { cargarHistorial(data.pagina - 1); };
            cont.appendChild(btnPrev);
        }

        if (data.pagina < data.total_paginas) {
            var btnNext = document.createElement('button');
            btnNext.type = 'button';
            btnNext.textContent = 'Siguiente';
            btnNext.onclick = function () { cargarHistorial(data.pagina + 1); };
            cont.appendChild(btnNext);
        }
    }

    function cargarHistorial(pagina) {
        paginaActual = pagina || 1;
        var tbody = document.getElementById('historial-body');
        tbody.innerHTML = '<tr><td colspan="7">Cargando...</td></tr>';

        fetch('../backend/obtener_historial.php?' + construirQuery(paginaActual))
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data.success) {
                    tbody.innerHTML = '<tr><td colspan="7">' + escapeHtml(data.message || 'Error al cargar el historial') + '</td></tr>';
                    return;
                }

                if (!data.data.length) {
                    tbody.innerHTML = '<tr><td colspan="7">Sin movimientos registrados</td></tr>';
                    document.getElementById('historial-paginacion').innerHTML = '';
                    return;
                }

                tbody.innerHTML = data.data.map(function (registro) {
                    return '<tr>' +
                        '<td>' + escapeHtml(formatearFecha(registro.creado_en)) + '</td>' +
                        '<td>' + escapeHtml(registro.usuario_nombre || '—') + '</td>' +
                        '<td>' + escapeHtml(registro.usuario_rol || '—') + '</td>' +
                        '<td>' + escapeHtml(etiquetaAccion(registro.accion)) + '</td>' +
                        '<td>' + escapeHtml(registro.tabla) + '</td>' +
                        '<td>' + escapeHtml(identificadorRegistro(registro)) + '</td>' +
                        '<td><button type="button" class="ver-detalle" data-id="' + escapeHtml(registro.id) + '">Ver</button></td>' +
                        '</tr>';
                }).join('');

                var botones = tbody.querySelectorAll('.ver-detalle');
                for (var i = 0; i < botones.length; i++) {
                    botones[i].addEventListener('click', function (ev) {
                        var id = ev.currentTarget.getAttribute('data-id');
                        var registro = data.data.filter(function (r) { return String(r.id) === String(id); })[0];
                        if (registro) mostrarDetalle(registro);
                    });
                }

                renderPaginacion(data);
            })
            .catch(function () {
                tbody.innerHTML = '<tr><td colspan="7">Error de red al cargar el historial</td></tr>';
            });
    }

    document.addEventListener('DOMContentLoaded', function () {
        cargarHistorial(1);

        document.getElementById('filtros-historial').addEventListener('submit', function (ev) {
            ev.preventDefault();
            cargarHistorial(1);
        });
    });
})();
