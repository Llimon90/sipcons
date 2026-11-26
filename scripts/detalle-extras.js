// Extras del detalle de incidencia: etiquetas, plantillas de respuesta y
// bitácora de tiempo. Independiente de detalle.js (no toca su formulario ni
// su estado) para no chocar con los cambios de SLA/portal que ya viven ahí.
(function () {
    function idIncidencia() {
        return new URLSearchParams(window.location.search).get('id');
    }

    function escapeHtml(valor) {
        return String(valor ?? '')
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    function mostrarMensaje(id, texto, tipo) {
        var el = document.getElementById(id);
        if (!el) return;
        el.textContent = texto;
        el.style.color = tipo === 'error' ? '#c0392b' : '#27ae60';
    }

    // ---------- Etiquetas ----------
    var etiquetasCatalogo = [];
    var etiquetasAsignadas = [];

    function renderEtiquetas() {
        var contenedor = document.getElementById('etiquetas-asignadas');
        if (!contenedor) return;
        contenedor.innerHTML = '';
        etiquetasAsignadas.forEach(function (et) {
            var chip = document.createElement('span');
            chip.className = 'etiqueta-chip';
            chip.style.display = 'inline-flex';
            chip.style.alignItems = 'center';
            chip.style.gap = '6px';
            chip.style.padding = '3px 10px';
            chip.style.margin = '2px';
            chip.style.borderRadius = '999px';
            chip.style.fontSize = '0.85em';
            chip.style.color = '#fff';
            chip.style.backgroundColor = et.color || '#888888';
            chip.textContent = et.nombre;

            var quitar = document.createElement('button');
            quitar.type = 'button';
            quitar.textContent = '×';
            quitar.title = 'Quitar etiqueta';
            quitar.style.background = 'none';
            quitar.style.border = 'none';
            quitar.style.color = '#fff';
            quitar.style.cursor = 'pointer';
            quitar.addEventListener('click', function () { quitarEtiqueta(et.id); });

            chip.appendChild(quitar);
            contenedor.appendChild(chip);
        });

        var selector = document.getElementById('selector-etiqueta');
        if (selector) {
            var asignadasIds = etiquetasAsignadas.map(function (e) { return e.id; });
            selector.innerHTML = '<option value="">Agregar etiqueta...</option>';
            etiquetasCatalogo.filter(function (e) { return !asignadasIds.includes(e.id); }).forEach(function (e) {
                var opt = document.createElement('option');
                opt.value = e.id;
                opt.textContent = e.nombre;
                selector.appendChild(opt);
            });
        }
    }

    function cargarEtiquetasIncidencia() {
        fetch('../backend/obtener-etiquetas-incidencia.php?incidencia_id=' + encodeURIComponent(idIncidencia()))
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data.success) return;
                etiquetasAsignadas = data.etiquetas || [];
                renderEtiquetas();
            })
            .catch(function () { /* la sección queda vacía si falla */ });
    }

    function cargarCatalogoEtiquetas() {
        fetch('../backend/buscar-etiquetas.php')
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data.success) return;
                etiquetasCatalogo = data.etiquetas || [];
                renderEtiquetas();
            })
            .catch(function () { /* sin catálogo disponible */ });
    }

    function asignarEtiqueta(etiquetaId) {
        fetch('../backend/asignar-etiqueta-incidencia.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ incidencia_id: idIncidencia(), etiqueta_id: etiquetaId })
        })
            .then(function (r) { return r.json(); })
            .then(function (data) { if (data.success) cargarEtiquetasIncidencia(); });
    }

    function quitarEtiqueta(etiquetaId) {
        fetch('../backend/quitar-etiqueta-incidencia.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ incidencia_id: idIncidencia(), etiqueta_id: etiquetaId })
        })
            .then(function (r) { return r.json(); })
            .then(function (data) { if (data.success) cargarEtiquetasIncidencia(); });
    }

    // ---------- Plantillas de respuesta ----------
    var plantillas = [];

    function cargarPlantillas() {
        fetch('../backend/buscar-plantillas-respuesta.php')
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data.success) return;
                plantillas = data.plantillas || [];
                var selector = document.getElementById('selector-plantilla');
                if (!selector) return;
                plantillas.forEach(function (p) {
                    var opt = document.createElement('option');
                    opt.value = p.id;
                    opt.textContent = p.titulo;
                    selector.appendChild(opt);
                });
            })
            .catch(function () { /* sin plantillas disponibles */ });
    }

    function insertarPlantilla(plantillaId) {
        var plantilla = plantillas.find(function (p) { return String(p.id) === String(plantillaId); });
        var notas = document.getElementById('notas');
        if (!plantilla || !notas) return;
        notas.value = notas.value ? (notas.value + '\n\n' + plantilla.contenido) : plantilla.contenido;
        notas.dispatchEvent(new Event('input', { bubbles: true }));
    }

    // ---------- Bitácora de tiempo ----------
    function formatearMinutos(minutos) {
        var h = Math.floor(minutos / 60), m = minutos % 60;
        return h > 0 ? (h + 'h ' + m + 'min') : (m + ' min');
    }

    function renderBitacora(registros, totalMinutos) {
        var contenedor = document.getElementById('bitacora-tiempo-lista');
        if (!contenedor) return;
        if (!registros.length) {
            contenedor.innerHTML = '<p>Sin tiempo registrado todavía.</p>';
            return;
        }
        var filas = registros.map(function (r) {
            return '<tr>' +
                '<td>' + escapeHtml(r.fecha) + '</td>' +
                '<td>' + escapeHtml(r.usuario_nombre || '') + '</td>' +
                '<td>' + formatearMinutos(r.minutos) + '</td>' +
                '<td>' + escapeHtml(r.descripcion || '') + '</td>' +
                '<td><button type="button" class="btn-quitar-tiempo" data-id="' + r.id + '">Eliminar</button></td>' +
                '</tr>';
        }).join('');
        contenedor.innerHTML =
            '<table><thead><tr><th>Fecha</th><th>Técnico</th><th>Tiempo</th><th>Descripción</th><th></th></tr></thead>' +
            '<tbody>' + filas + '</tbody></table>' +
            '<p><strong>Total: ' + formatearMinutos(totalMinutos) + '</strong></p>';

        contenedor.querySelectorAll('.btn-quitar-tiempo').forEach(function (btn) {
            btn.addEventListener('click', function () { eliminarTiempo(btn.dataset.id); });
        });
    }

    function cargarBitacora() {
        fetch('../backend/obtener-tiempo-incidencia.php?incidencia_id=' + encodeURIComponent(idIncidencia()))
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data.success) return;
                renderBitacora(data.registros || [], data.total_minutos || 0);
            })
            .catch(function () { /* la sección queda vacía si falla */ });
    }

    function registrarTiempo() {
        var minutos = parseInt(document.getElementById('bitacora-minutos').value, 10);
        var descripcion = document.getElementById('bitacora-descripcion').value;
        if (!minutos || minutos <= 0) {
            mostrarMensaje('mensaje-bitacora', 'Indica cuántos minutos invertiste.', 'error');
            return;
        }
        fetch('../backend/guardar-tiempo-incidencia.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ incidencia_id: idIncidencia(), minutos: minutos, descripcion: descripcion })
        })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data.success) {
                    mostrarMensaje('mensaje-bitacora', 'Tiempo registrado.', 'ok');
                    document.getElementById('bitacora-minutos').value = '';
                    document.getElementById('bitacora-descripcion').value = '';
                    cargarBitacora();
                } else {
                    mostrarMensaje('mensaje-bitacora', data.message || 'No se pudo registrar.', 'error');
                }
            })
            .catch(function () { mostrarMensaje('mensaje-bitacora', 'Error de red.', 'error'); });
    }

    function eliminarTiempo(id) {
        if (!confirm('¿Eliminar este registro de tiempo?')) return;
        fetch('../backend/eliminar-tiempo-incidencia.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ id: id })
        })
            .then(function (r) { return r.json(); })
            .then(function (data) { if (data.success) cargarBitacora(); });
    }

    // ---------- Escalamiento ----------
    // Lectura independiente de detalle.php (no comparte estado con
    // detalle.js) solo para mostrar el aviso si backend/alertas_incidentes.php
    // ya la marcó como escalada (migración 063).
    function mostrarAvisoEscalado() {
        fetch('../backend/detalle.php?id=' + encodeURIComponent(idIncidencia()))
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data || !Number(data.escalado)) return;
                var encabezado = document.querySelector('.detalle-folio');
                if (!encabezado || encabezado.querySelector('.badge-escalado')) return;
                var aviso = document.createElement('span');
                aviso.className = 'badge-escalado';
                aviso.style.marginLeft = '8px';
                aviso.style.padding = '2px 10px';
                aviso.style.borderRadius = '999px';
                aviso.style.backgroundColor = '#e67e22';
                aviso.style.color = '#fff';
                aviso.style.fontSize = '0.85em';
                aviso.title = 'Sin técnico asignado y cerca de vencer su tiempo de respuesta';
                aviso.textContent = 'Escalada';
                encabezado.appendChild(aviso);
            })
            .catch(function () { /* sin aviso si falla */ });
    }

    document.addEventListener('DOMContentLoaded', function () {
        if (!idIncidencia()) return; // la página ya muestra su propio error si falta el id

        // detalle.js carga el formulario de forma asíncrona; se espera un
        // momento antes de buscar '.detalle-folio' para no chocar con su propio render.
        setTimeout(mostrarAvisoEscalado, 600);
        cargarCatalogoEtiquetas();
        cargarEtiquetasIncidencia();
        cargarPlantillas();
        cargarBitacora();

        var selectorEtiqueta = document.getElementById('selector-etiqueta');
        if (selectorEtiqueta) {
            selectorEtiqueta.addEventListener('change', function () {
                if (this.value) { asignarEtiqueta(this.value); this.value = ''; }
            });
        }

        var selectorPlantilla = document.getElementById('selector-plantilla');
        if (selectorPlantilla) {
            selectorPlantilla.addEventListener('change', function () {
                if (this.value) { insertarPlantilla(this.value); this.value = ''; }
            });
        }

        var btnTiempo = document.getElementById('btn-agregar-tiempo');
        if (btnTiempo) btnTiempo.addEventListener('click', registrarTiempo);
    });
})();
