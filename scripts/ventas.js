/**
 * scripts/ventas.js
 * Módulo de Ventas: Gestión de Series, Integridad, Multi-archivos y Frecuencia de Servicio.
 */

document.addEventListener('DOMContentLoaded', () => {
    // --- ELEMENTOS DEL DOM ---
    const formVenta = document.getElementById('form-venta');
    const qtyInput = document.getElementById('qty');
    const seriesContainer = document.getElementById('series-container');
    const btnRegistrar = document.getElementById('btn-registrar-venta');
    const clienteSelect = document.getElementById('cliente');
    const multiFiles = document.getElementById('facturas'); 
    
    // NUEVO: Elemento de la fecha de venta
    const inputFechaVenta = document.getElementById('fecha_venta');

    // Periodicidad de mantenimiento + check de póliza
    AppPeriodicidad.enlazar(
        document.getElementById('calibracion'),
        document.getElementById('servicio'),
        document.getElementById('aviso-periodicidad')
    );

    let valorPrevioQty = 0;
    let ventaDeCotizacion = null; // venta por registrar de una cotización aprobada (?cotizacion_venta=ID)

    // Artículo de inventario: rellena equipo/marca/modelo y descuenta existencia al vender
    AppInventario.selectorVenta(document.getElementById('articulo_id'), {
        equipo: document.getElementById('equipo'),
        marca: document.getElementById('marca'),
        modelo: document.getElementById('modelo')
    });

    // --- ESTABLECER FECHA ACTUAL POR DEFECTO ---
    if (inputFechaVenta) {
        const hoy = new Date();
        const yyyy = hoy.getFullYear();
        const mm = String(hoy.getMonth() + 1).padStart(2, '0');
        const dd = String(hoy.getDate()).padStart(2, '0');
        inputFechaVenta.value = `${yyyy}-${mm}-${dd}`;
    }

    // --- VALIDACIÓN DE SERIES (DUPLICADOS) ---
    const validarSeries = () => {
        const inputs = document.querySelectorAll('.serie-input');
        const valores = Array.from(inputs).map(i => i.value.trim().toUpperCase());
        let duplicados = new Set();
        let hayVacios = false;

        valores.forEach((val, idx) => {
            if (val !== "" && valores.indexOf(val) !== idx) duplicados.add(val);
            if (val === "") hayVacios = true;
        });

        let errorMsg = document.getElementById('error-series-msg');
        if (duplicados.size > 0) {
            if (!errorMsg) {
                errorMsg = document.createElement('div');
                errorMsg.id = 'error-series-msg';
                errorMsg.className = 'mensaje-error-input';
                seriesContainer.prepend(errorMsg);
            }
            errorMsg.innerHTML = `<i class="fas fa-exclamation-triangle"></i> <strong>Series Duplicadas:</strong> ${Array.from(duplicados).join(', ')}`;
            errorMsg.style.display = 'block';
        } else if (errorMsg) {
            errorMsg.style.display = 'none';
        }

        inputs.forEach(input => {
            const esDuo = input.value.trim() !== "" && duplicados.has(input.value.trim().toUpperCase());
            input.classList.toggle('input-error', esDuo);
        });

        const estadoError = duplicados.size > 0;
        btnRegistrar.disabled = estadoError;
        btnRegistrar.style.opacity = estadoError ? "0.5" : "1";

        return { hayErrores: estadoError, hayVacios };
    };

    // --- GENERACIÓN DINÁMICA CON GUARDIA ---
    const actualizarCamposSerie = async () => {
        let cant = parseInt(qtyInput.value) || 0;
        if (cant < 0) { qtyInput.value = 0; cant = 0; }

        const actuales = seriesContainer.querySelectorAll('.serie-input');
        
        if (cant < actuales.length) {
            let riesgo = 0;
            for (let i = cant; i < actuales.length; i++) {
                if (actuales[i].value.trim() !== "") riesgo++;
            }
            if (riesgo > 0 && !(await AppAviso.confirmar(`Borrarás ${riesgo} serie(s) ya capturada(s).`, { peligro: true, boton: 'Sí, quitar', titulo: '¿Reducir la cantidad?' }))) {
                qtyInput.value = valorPrevioQty;
                return;
            }
        }

        let grid = document.getElementById('grid-series');
        if (cant > 0 && !grid) {
            seriesContainer.innerHTML = `<h3 style="margin-top:20px;"><i class="fas fa-barcode"></i> Series</h3><div id="grid-series" class="series-grid"></div>`;
            grid = document.getElementById('grid-series');
        }

        if (cant > actuales.length) {
            for (let i = actuales.length + 1; i <= cant; i++) {
                const div = document.createElement('div');
                div.className = 'serie-item';
                div.innerHTML = `<label>Equipo ${i}:</label><input type="text" class="serie-input" required placeholder="S/N">`;
                div.querySelector('input').addEventListener('input', validarSeries);
                grid.appendChild(div);
            }
        } else {
            for (let i = actuales.length; i > cant; i--) grid.lastElementChild.remove();
        }

        if (cant === 0) seriesContainer.innerHTML = '';
        valorPrevioQty = qtyInput.value;
        validarSeries();
    };

    // --- REGISTRO FINAL ---
    btnRegistrar.addEventListener('click', async () => {
        const { hayErrores, hayVacios } = validarSeries();
        if (parseInt(qtyInput.value) <= 0 || hayVacios) {
            AppAviso.alerta("⚠️ Completa todas las series y revisa la cantidad.");
            return;
        }

        if (!formVenta.checkValidity() || hayErrores) {
            formVenta.reportValidity();
            return;
        }

        const formData = new FormData();
        if (ventaDeCotizacion) formData.append('cotizacion_venta_id', ventaDeCotizacion.id);
        
        // NUEVO: Agregar la fecha seleccionada
        if (inputFechaVenta) {
            formData.append('fecha_venta', inputFechaVenta.value);
        }
        
        formData.append('cliente', clienteSelect.value);
        formData.append('sucursal', document.getElementById('sucursal').value);
        formData.append('equipo', document.getElementById('equipo').value);
        formData.append('marca', document.getElementById('marca').value);
        formData.append('modelo', document.getElementById('modelo').value);
        formData.append('articulo_id', document.getElementById('articulo_id').value);
        formData.append('garantia', document.getElementById('garantia').value);
        formData.append('calibracion', document.getElementById('calibracion').value || 0);
        
        // Check de póliza: la periodicidad es el campo de arriba
        formData.append('servicio', document.getElementById('servicio').checked ? 1 : 0);
        
        formData.append('notas', document.getElementById('notas').value);

        // Múltiples archivos
        if (multiFiles.files.length > 0) {
            for (let i = 0; i < multiFiles.files.length; i++) {
                formData.append('facturas[]', multiFiles.files[i]);
            }
        }
        
        const seriesArr = Array.from(document.querySelectorAll('.serie-input')).map(i => i.value.trim().toUpperCase());
        formData.append('series', JSON.stringify(seriesArr));

        try {
            btnRegistrar.disabled = true;
            btnRegistrar.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Guardando...';

            const resp = await fetch('../backend/registro_ventas.php', { method: 'POST', body: formData });
            const res = await resp.json();

            if (res.exito) {
                AppAviso.alerta(`✅ Éxito. Folio: ${res.folio}`);
                // Venta de una cotización: se vuelve a la cotización para ver su avance
                if (ventaDeCotizacion) location.href = 'cotizacion.html?id=' + ventaDeCotizacion.cotizacion_id;
                else location.reload();
            } else {
                AppAviso.alerta('❌ Error: ' + res.mensaje);
            }
        } catch (e) {
            AppAviso.alerta('❌ Error de conexión con el servidor.');
        } finally {
            btnRegistrar.disabled = false;
            btnRegistrar.innerHTML = '<i class="fas fa-save"></i> Procesar y Registrar Venta';
        }
    });

    qtyInput.addEventListener('input', actualizarCamposSerie);
    
    // Disparar evento inicial para que cargue la primer caja de serie
    actualizarCamposSerie(); 

    // --- VENTAS DESDE COTIZACIONES ---
    // ?cotizacion_venta=ID prellena el formulario con el equipo aprobado; sin
    // parámetro, avisa cuántas ventas de cotizaciones faltan por registrar.
    const avisoCot = document.getElementById('aviso-cotizacion');
    const idVentaCot = new URLSearchParams(location.search).get('cotizacion_venta');
    const apiCot = (q) => fetch('../backend/cotizaciones.php?' + q, { credentials: 'same-origin', cache: 'no-store' })
        .then(r => r.json()).then(j => { if (!j.exito) throw new Error(j.mensaje); return j; });
    const escCot = (v) => String(v ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    if (idVentaCot) {
        apiCot('accion=venta_por_registrar&id=' + encodeURIComponent(idVentaCot)).then(({ venta }) => {
            ventaDeCotizacion = venta;
            clienteSelect.value = venta.cliente_nombre;
            document.getElementById('sucursal').value = venta.sucursal || '';
            document.getElementById('equipo').value = venta.descripcion;
            document.getElementById('marca').value = venta.marca || '';
            document.getElementById('modelo').value = venta.modelo || '';
            if (venta.articulo_id) AppInventario.seleccionar(document.getElementById('articulo_id'), venta.articulo_id);
            qtyInput.value = Math.max(1, Math.round(parseFloat(venta.cantidad) || 1));
            actualizarCamposSerie();
            const notas = document.getElementById('notas');
            if (!notas.value) notas.value = 'Venta de la cotización ' + venta.folio + '.';
            avisoCot.hidden = false;
            avisoCot.innerHTML = '<i class="fas fa-file-invoice-dollar"></i> Venta de la cotización <a href="cotizacion.html?id=' + venta.cotizacion_id + '"><strong>' +
                escCot(venta.folio) + '</strong></a>: ' + escCot(venta.cantidad * 1) + ' × ' + escCot(venta.descripcion) +
                '. Captura las series, la garantía y, si aplica, la póliza.';
        }).catch(e => {
            avisoCot.hidden = false;
            avisoCot.textContent = 'No se pudo cargar la venta de la cotización: ' + e.message;
        });
    } else {
        apiCot('accion=ventas_por_registrar').then(({ ventas }) => {
            if (!ventas.length) return;
            avisoCot.hidden = false;
            avisoCot.innerHTML = '<i class="fas fa-cart-arrow-down"></i> Tienes ' + ventas.length + (ventas.length === 1 ? ' venta' : ' ventas') +
                ' por registrar de cotizaciones aprobadas: ' + ventas.slice(0, 4).map(v =>
                    '<a href="ventas.html?cotizacion_venta=' + v.id + '">' + escCot(v.folio) + ' · ' + escCot(v.descripcion) + '</a>').join(', ') +
                (ventas.length > 4 ? '… <a href="cotizaciones.html">ver todas</a>' : '') + '.';
        }).catch(() => { /* sin acceso a cotizaciones */ });
    }
});