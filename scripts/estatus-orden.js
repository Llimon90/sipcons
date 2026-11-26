/*
 * Estatus de la orden de servicio (docs/REGLAS_NEGOCIO.md 0.1, D-1 a D-5).
 * La lista y las reglas las hace cumplir el servidor (backend/lib/incidencias_helpers.php);
 * aquí solo se muestran los campos que cada estatus pide:
 *  - Pendiente o Cancelado: motivo (con sugerencias), obligatorio.
 *  - Salir de Cerrado o Cancelado (reabrir): motivo de reapertura, obligatorio.
 *
 *   AppEstatus.conectar(selectEstatus, { guardado: 'Cerrado sin factura' | null, motivo: '...', ancla: 'id-del-bloque' })
 *   AppEstatus.marcarGuardado(selectEstatus, estatus, motivo)   // después de guardar
 *   AppEstatus.motivoReapertura()                               // texto o ''
 */
(function () {
    var LISTA = ['Abierto', 'Asignado', 'En proceso', 'Pendiente', 'Completado',
                 'Cerrado sin factura', 'Cerrado con factura', 'Cancelado'];
    var FINALES = ['Cerrado sin factura', 'Cerrado con factura', 'Cancelado'];
    var SUGERENCIAS = {
        'Pendiente': ['Refacción', 'Aprobación de cotización', 'Cliente o acceso', 'Otro: '],
        'Cancelado': ['Duplicada', 'El cliente desistió', 'Se registró por error', 'Otro: ']
    };
    var AYUDA = {
        'Pendiente': '¿Qué se está esperando? Refacción, aprobación de cotización, cliente o acceso, u otro.',
        'Cancelado': '¿Por qué no se realizará? Queda en el historial.'
    };

    function crearCampos(select) {
        var caja = document.getElementById('campos-estatus');
        if (caja) return caja;
        caja = document.createElement('div');
        caja.id = 'campos-estatus';
        caja.className = 'campos-estatus';
        caja.innerHTML =
            '<div class="campo-motivo-estatus" hidden>' +
            '  <label for="motivo_estatus">Motivo <span class="campo-motivo-nombre"></span> *</label>' +
            '  <input type="text" id="motivo_estatus" name="motivo_estatus" maxlength="255" list="lista-motivos-estatus" autocomplete="off">' +
            '  <datalist id="lista-motivos-estatus"></datalist>' +
            '  <small class="campo-ayuda"></small>' +
            '</div>' +
            '<div class="campo-motivo-reapertura" hidden>' +
            '  <label for="motivo_reapertura">Motivo para reabrir la orden *</label>' +
            '  <input type="text" id="motivo_reapertura" name="motivo_reapertura" maxlength="500" autocomplete="off"' +
            '         placeholder="Ej. el equipo volvió a fallar, se cerró por error">' +
            '  <small class="campo-ayuda">Queda registrado en el historial de la orden.</small>' +
            '</div>';
        // Después del bloque indicado (o del contenedor del select)
        var ancla = (select.dataset.estatusAncla && document.getElementById(select.dataset.estatusAncla))
            || select.closest('.form-campo, .form-row, div') || select.parentNode;
        ancla.parentNode.insertBefore(caja, ancla.nextSibling);
        return caja;
    }

    function actualizar(select) {
        var caja = crearCampos(select);
        var est = select.value;
        var guardado = select.dataset.estatusGuardado || '';

        var bloqueMotivo = caja.querySelector('.campo-motivo-estatus');
        var inputMotivo = caja.querySelector('#motivo_estatus');
        var pide = Object.prototype.hasOwnProperty.call(SUGERENCIAS, est);
        bloqueMotivo.hidden = !pide;
        inputMotivo.required = pide;
        if (pide) {
            caja.querySelector('.campo-motivo-nombre').textContent = est === 'Pendiente' ? 'de pendiente' : 'de cancelación';
            caja.querySelector('#lista-motivos-estatus').innerHTML = SUGERENCIAS[est]
                .map(function (s) { return '<option value="' + s.replace(/"/g, '&quot;') + '">'; }).join('');
            caja.querySelector('.campo-motivo-estatus .campo-ayuda').textContent = AYUDA[est];
        }

        var reabre = FINALES.indexOf(guardado) !== -1 && FINALES.indexOf(est) === -1;
        var bloqueReabrir = caja.querySelector('.campo-motivo-reapertura');
        bloqueReabrir.hidden = !reabre;
        caja.querySelector('#motivo_reapertura').required = reabre;
    }

    function conectar(select, opciones) {
        if (!select) return;
        opciones = opciones || {};
        if (opciones.ancla) select.dataset.estatusAncla = opciones.ancla;
        // Un estatus heredado que ya no está en la lista se muestra para no perderlo de vista
        if (select.value && LISTA.indexOf(select.value) === -1) select.value = 'Abierto';
        select.dataset.estatusGuardado = opciones.guardado || '';
        var caja = crearCampos(select);
        if (opciones.motivo != null) caja.querySelector('#motivo_estatus').value = opciones.motivo;
        if (!select.dataset.estatusConectado) {
            select.addEventListener('change', function () { actualizar(select); });
            select.dataset.estatusConectado = '1';
        }
        actualizar(select);
    }

    function marcarGuardado(select, estatus, motivo) {
        if (!select) return;
        if (estatus) select.value = estatus;
        select.dataset.estatusGuardado = estatus || '';
        var caja = crearCampos(select);
        caja.querySelector('#motivo_estatus').value = motivo || '';
        caja.querySelector('#motivo_reapertura').value = '';
        actualizar(select);
    }

    function motivoReapertura() {
        var el = document.getElementById('motivo_reapertura');
        return el && !el.closest('[hidden]') ? el.value.trim() : '';
    }

    function motivoEstatus() {
        var el = document.getElementById('motivo_estatus');
        return el && !el.closest('[hidden]') ? el.value.trim() : '';
    }

    window.AppEstatus = {
        LISTA: LISTA, FINALES: FINALES,
        conectar: conectar, marcarGuardado: marcarGuardado,
        motivoReapertura: motivoReapertura, motivoEstatus: motivoEstatus,
        esFinal: function (e) { return FINALES.indexOf(e) !== -1; }
    };
})();
