/**
 * scripts/ventas.js - Lumina-ERP
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

    // Elementos de la Cláusula de Servicio
    const checkServicio = document.getElementById('servicio');
    const contenedorFrecuencia = document.getElementById('contenedor-frecuencia');
    const inputFrecuencia = document.getElementById('frecuencia_servicio');

    let valorPrevioQty = 0;

    // --- EVENTO CLÁUSULA DE SERVICIO ---
    checkServicio.addEventListener('change', (e) => {
        if (e.target.checked) {
            contenedorFrecuencia.style.display = 'block';
            inputFrecuencia.required = true;
            if (!inputFrecuencia.value) inputFrecuencia.value = 6; // Valor sugerido
        } else {
            contenedorFrecuencia.style.display = 'none';
            inputFrecuencia.required = false;
            inputFrecuencia.value = ''; 
        }
    });

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
    const actualizarCamposSerie = () => {
        let cant = parseInt(qtyInput.value) || 0;
        if (cant < 0) { qtyInput.value = 0; cant = 0; }

        const actuales = seriesContainer.querySelectorAll('.serie-input');
        
        if (cant < actuales.length) {
            let riesgo = 0;
            for (let i = cant; i < actuales.length; i++) {
                if (actuales[i].value.trim() !== "") riesgo++;
            }
            if (riesgo > 0 && !confirm(`¿Estás seguro? Borrarás ${riesgo} serie(s) ya capturada(s).`)) {
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
            alert("⚠️ Completa todas las series y revisa la cantidad.");
            return;
        }

        if (!formVenta.checkValidity() || hayErrores) {
            formVenta.reportValidity();
            return;
        }

        const formData = new FormData();
        formData.append('cliente', clienteSelect.value);
        formData.append('sucursal', document.getElementById('sucursal').value);
        formData.append('equipo', document.getElementById('equipo').value);
        formData.append('marca', document.getElementById('marca').value);
        formData.append('modelo', document.getElementById('modelo').value);
        formData.append('garantia', document.getElementById('garantia').value);
        
        // Agregar los datos del servicio
        const servicioActivado = document.getElementById('servicio').checked;
        formData.append('servicio', servicioActivado ? 1 : 0);
        formData.append('frecuencia_servicio', servicioActivado ? (inputFrecuencia.value || 0) : 0);
        
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
                alert(`✅ Éxito. Folio: ${res.folio}`);
                location.reload();
            } else {
                alert('❌ Error: ' + res.mensaje);
            }
        } catch (e) {
            alert('❌ Error de conexión con el servidor.');
        } finally {
            btnRegistrar.disabled = false;
            btnRegistrar.innerHTML = '<i class="fas fa-save"></i> Registrar Venta';
        }
    });

    qtyInput.addEventListener('input', actualizarCamposSerie);
    
    // Disparar evento inicial para que cargue la primer caja de serie
    actualizarCamposSerie(); 
});