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
        var mapa = { CREATE: 'Creación', UPDATE: 'Edición', DELETE: 'Eliminación', ARCHIVO_ELIMINADO: 'Archivo eliminado' };
        return mapa[accion] || accion;
    }

    // "numero_incidente" -> "Numero incidente" (los nombres de columna no
    // tienen una traducción centralizada; esto alcanza para que se lean bien).
    function humanizarClave(clave) {
        var texto = String(clave).replace(/_/g, ' ');
        return texto.charAt(0).toUpperCase() + texto.slice(1);
    }

    function formatearValor(valor) {
        if (valor === null || valor === undefined || valor === '') return '—';
        if (typeof valor === 'object') return JSON.stringify(valor, null, 2);
        return String(valor);
    }

    // El id interno de la base de datos no aporta nada al leer el historial:
    // no se muestra en ninguno de los dos paneles.
    var CLAVES_OCULTAS = ['id'];

    // Identificadores que el propio sistema genera (SIP-0001, VT-00005) y que
    // nadie puede editar a mano: sí se muestran (para saber de qué registro se
    // trata), pero nunca se sombrean como "modificado".
    var CLAVES_SIN_RESALTAR = ['numero_incidente', 'folio'];

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

    // Construye un panel (Antes o Después) con una fila por campo. "cambiados"
    // es el conjunto de llaves que difieren entre antes y después: esas filas
    // se sombrean para que salten a la vista sin tener que comparar a mano.
    function panelCampos(datos, claves, cambiados, vacioTexto) {
        if (!datos) {
            return '<p style="color:#7f8c8d; font-style:italic;">' + escapeHtml(vacioTexto) + '</p>';
        }
        return '<div style="border:1px solid #e0e0e0; border-radius:4px; overflow:hidden;">' +
            claves.map(function (clave) {
                var tieneValor = Object.prototype.hasOwnProperty.call(datos, clave);
                if (!tieneValor) return '';
                var cambio = cambiados.indexOf(clave) !== -1;
                var estilo = 'padding:6px 10px; border-bottom:1px solid #eee;' +
                    (cambio ? ' background:#fff6da; border-left:3px solid #e6a817;' : ' border-left:3px solid transparent;');
                return '<div style="' + estilo + '">' +
                    '<div style="font-size:0.78rem; color:#7f8c8d; text-transform:uppercase; letter-spacing:.02em;">' + escapeHtml(humanizarClave(clave)) + (cambio ? ' · modificado' : '') + '</div>' +
                    '<pre style="margin:2px 0 0; white-space:pre-wrap; word-break:break-word; font-family:inherit; font-size:0.92rem;">' + escapeHtml(formatearValor(datos[clave])) + '</pre>' +
                    '</div>';
            }).join('') +
            '</div>';
    }

    function mostrarDetalle(registro) {
        var contenedor = document.getElementById('detalle-historial-contenido');
        var antes = registro.datos_anteriores || null;
        var despues = registro.datos_nuevos || null;

        // Únion de llaves de ambos lados, en un orden estable (primero las de
        // "después", que suele ser el objeto más completo; luego las que solo
        // estaban "antes", como un campo eliminado del registro).
        var claves = [];
        Object.keys(despues || {}).forEach(function (k) { if (claves.indexOf(k) === -1) claves.push(k); });
        Object.keys(antes || {}).forEach(function (k) { if (claves.indexOf(k) === -1) claves.push(k); });
        claves = claves.filter(function (k) { return CLAVES_OCULTAS.indexOf(k) === -1; });

        // Si es una creación o una eliminación completa, no hay "otro lado" con
        // el que comparar: no tiene sentido sombrear todo el panel como
        // "modificado", así que solo se resalta cuando existen los dos lados.
        var cambiados = (antes && despues) ? claves.filter(function (k) {
            return CLAVES_SIN_RESALTAR.indexOf(k) === -1 && JSON.stringify(antes[k]) !== JSON.stringify(despues[k]);
        }) : [];

        contenedor.innerHTML =
            '<p><strong>Usuario:</strong> ' + escapeHtml(registro.usuario_nombre || '—') + ' (' + escapeHtml(registro.usuario_rol || '—') + ')</p>' +
            '<p><strong>Acción:</strong> ' + escapeHtml(etiquetaAccion(registro.accion)) + ' sobre ' + escapeHtml(registro.tabla) + ' #' + escapeHtml(identificadorRegistro(registro)) + '</p>' +
            '<p><strong>Fecha:</strong> ' + escapeHtml(formatearFecha(registro.creado_en)) + '</p>' +
            '<p><strong>IP:</strong> ' + escapeHtml(registro.ip_address || '—') + '</p>' +
            '<div class="form-row">' +
            '<div><label>Antes</label>' + panelCampos(antes, claves, cambiados, '(registro nuevo, no existía antes)') + '</div>' +
            '<div><label>Después</label>' + panelCampos(despues, claves, cambiados, '(registro eliminado)') + '</div>' +
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
