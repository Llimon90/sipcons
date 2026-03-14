/**
 * scripts/detalles-venta.js
 * Lógica Completa (Cantidades Dinámicas, Frecuencia de Servicio y PDF.js) - SIPCONS
 */

pdfjsLib.GlobalWorkerOptions.workerSrc = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/2.16.105/pdf.worker.min.js';

function getShortFileName(fileName, maxLength = 18) {
    return fileName.length > maxLength ? fileName.substring(0, maxLength) + '...' : fileName;
}

function showNotification(message, type = 'success') {
    const notification = document.createElement('div');
    notification.className = `notificacion ${type}`;
    notification.textContent = message;
    document.body.appendChild(notification);
    setTimeout(() => notification.remove(), 3000);
}

// ==========================================
// RENDERIZADO DE ARCHIVOS ESTÉTICO
// ==========================================
async function renderPdfThumbnail(archivo, canvas) {
    try {
        const pdf = await pdfjsLib.getDocument(archivo).promise;
        const page = await pdf.getPage(1);
        const viewport = page.getViewport({ scale: 1 });
        const context = canvas.getContext('2d');
        canvas.height = viewport.height;
        canvas.width = viewport.width;
        await page.render({ canvasContext: context, viewport: viewport }).promise;
    } catch (error) {
        console.error('Error al renderizar miniatura de PDF:', error);
        throw error;
    }
}

function createFilePreview(archivo, ext) {
    let previewElement;
    if (['jpg', 'jpeg', 'png', 'gif', 'webp'].includes(ext)) {
        previewElement = document.createElement('img');
        previewElement.src = archivo;
        previewElement.style.maxWidth = '100px';
        previewElement.style.maxHeight = '100px';
        previewElement.style.objectFit = 'contain';
        previewElement.style.cursor = 'pointer';
    } else if (ext === 'pdf') {
        previewElement = document.createElement('canvas');
        previewElement.style.maxWidth = '100px';
        previewElement.style.maxHeight = '100px';
        previewElement.style.cursor = 'pointer';
        previewElement.style.border = '1px solid #eee'; 
    } else {
        previewElement = document.createElement('i');
        previewElement.className = 'fas fa-file-alt';
        previewElement.style.fontSize = '60px';
        previewElement.style.color = '#95a5a6';
        previewElement.style.cursor = 'pointer';
    }
    return previewElement;
}

function createFileContainer(archivoObj) {
    const archivoUrl = archivoObj.ruta_archivo;
    const nombreOriginal = archivoObj.nombre_archivo;
    const idArchivo = archivoObj.id;
    const ext = archivoUrl.split('.').pop().toLowerCase();

    const archivoContainer = document.createElement('div');
    archivoContainer.className = 'archivo-container';
    archivoContainer.style.position = 'relative';
    archivoContainer.style.margin = '10px';
    archivoContainer.style.padding = '10px';
    archivoContainer.style.border = '1px solid #ddd';
    archivoContainer.style.borderRadius = '5px';
    archivoContainer.style.display = 'inline-block';
    archivoContainer.style.width = '130px'; 
    archivoContainer.style.height = '160px';
    archivoContainer.style.verticalAlign = 'top';
    archivoContainer.style.backgroundColor = '#fafafa';

    const link = document.createElement('a');
    link.href = archivoUrl;
    link.target = '_blank';
    link.style.textDecoration = 'none';
    link.style.color = '#333';
    link.style.display = 'flex';
    link.style.flexDirection = 'column';
    link.style.alignItems = 'center';
    link.style.height = '100%';

    const divCentrado = document.createElement('div');
    divCentrado.style.display = 'flex';
    divCentrado.style.justifyContent = 'center';
    divCentrado.style.alignItems = 'center';
    divCentrado.style.height = '100px';
    divCentrado.style.width = '100%';
    divCentrado.style.marginBottom = '8px';

    const previewElement = createFilePreview(archivoUrl, ext);
    if (previewElement) {
        divCentrado.appendChild(previewElement);
    }
    link.appendChild(divCentrado);

    const fileNameSpan = document.createElement('span');
    fileNameSpan.textContent = getShortFileName(nombreOriginal);
    fileNameSpan.title = nombreOriginal;
    fileNameSpan.style.display = 'block';
    fileNameSpan.style.textAlign = 'center';
    fileNameSpan.style.fontSize = '12px';
    fileNameSpan.style.lineHeight = '1.2';
    link.appendChild(fileNameSpan);

    // Botón rojo de eliminar blindado
    const deleteBtn = document.createElement('button');
    deleteBtn.className = 'eliminar-archivo';
    deleteBtn.innerHTML = '<i class="fas fa-times"></i>'; 
    deleteBtn.style.position = 'absolute';
    deleteBtn.style.top = '-8px';
    deleteBtn.style.right = '-8px';
    deleteBtn.style.background = '#ff4d4f';
    deleteBtn.style.color = 'white';
    deleteBtn.style.border = 'none';
    deleteBtn.style.borderRadius = '50%';
    deleteBtn.style.width = '26px';
    deleteBtn.style.height = '26px';
    deleteBtn.style.minWidth = '26px';
    deleteBtn.style.minHeight = '26px';
    deleteBtn.style.maxWidth = '26px';
    deleteBtn.style.maxHeight = '26px';
    deleteBtn.style.padding = '0';
    deleteBtn.style.margin = '0';
    deleteBtn.style.boxSizing = 'border-box';
    deleteBtn.style.display = 'flex';
    deleteBtn.style.alignItems = 'center';
    deleteBtn.style.justifyContent = 'center';
    deleteBtn.style.cursor = 'pointer';
    deleteBtn.style.boxShadow = '0 2px 4px rgba(0,0,0,0.2)';
    
    deleteBtn.onclick = (e) => {
        e.preventDefault();
        e.stopPropagation();
        borrarArchivoVenta(idArchivo, archivoUrl, archivoContainer);
    };

    archivoContainer.appendChild(link);
    archivoContainer.appendChild(deleteBtn);

    return { container: archivoContainer, preview: previewElement, ext: ext, url: archivoUrl };
}

async function cargarArchivosAdjuntosVentas(archivosArray) {
    const contenedorArchivos = document.getElementById("contenedor-archivos");
    contenedorArchivos.innerHTML = "";

    if (archivosArray && archivosArray.length > 0) {
        for (const archivoObj of archivosArray) {
            const { container, preview, ext, url } = createFileContainer(archivoObj);
            if (ext === 'pdf' && preview) {
                try {
                    await renderPdfThumbnail(url, preview);
                } catch (error) {
                    const errorSpan = document.createElement('span');
                    errorSpan.textContent = 'PDF (Error preview)';
                    preview.replaceWith(errorSpan);
                }
            }
            contenedorArchivos.appendChild(container);
        }
    } else {
        contenedorArchivos.innerHTML = "<p style='color:#95a5a6; padding: 20px;'>No hay archivos adjuntos para esta venta.</p>";
    }
}

async function borrarArchivoVenta(idArchivo, urlArchivo, containerElement) {
    if (!confirm('¿Estás seguro de que deseas eliminar este archivo permanentemente?')) return;
    containerElement.classList.add('eliminando');

    try {
        const resp = await fetch("../backend/eliminar_archivo.php", {
            method: "POST",
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ id: idArchivo, ruta: urlArchivo, modulo: 'ventas' })
        });

        const data = await resp.json();

        if (data.success || data.exito) {
            containerElement.remove();
            showNotification('Archivo eliminado correctamente', 'success');
        } else {
            throw new Error(data.error || data.mensaje || 'Error al eliminar');
        }
    } catch (error) {
        showNotification(error.message, 'error');
        containerElement.classList.remove('eliminando');
    }
}

// ==========================================
// FLUJO PRINCIPAL
// ==========================================
document.addEventListener('DOMContentLoaded', () => {
    const urlParams = new URLSearchParams(window.location.search);
    const ventaId = urlParams.get('id');

    if (!ventaId) {
        alert("Error: ID no encontrado.");
        window.location.href = 'ventas.html';
        return;
    }
    document.getElementById('venta_id').value = ventaId;

    const qtyInput = document.getElementById('qty');
    const seriesContainer = document.getElementById('container-series-edit');
    const btnGuardar = document.getElementById('btn-guardar-cambios');
    let valorPrevioQty = 0;

    // Elementos de la Frecuencia de Servicio
    const checkServicio = document.getElementById('servicio');
    const contenedorFrecuencia = document.getElementById('contenedor-frecuencia');
    const inputFrecuencia = document.getElementById('frecuencia_servicio');

    // Evento para mostrar/ocultar frecuencia de servicio
    checkServicio.addEventListener('change', (e) => {
        if (e.target.checked) {
            contenedorFrecuencia.style.display = 'block';
            inputFrecuencia.required = true;
            if (!inputFrecuencia.value) inputFrecuencia.value = 6; // Por defecto 6 meses
        } else {
            contenedorFrecuencia.style.display = 'none';
            inputFrecuencia.required = false;
            inputFrecuencia.value = ''; 
        }
    });

    // Validación Anti-Duplicados
    const validarSeries = () => {
        const inputs = document.querySelectorAll('.serie-edit-input');
        const valores = Array.from(inputs).map(i => i.value.trim().toUpperCase());
        let duplicados = new Set();
        let hayVacios = false;

        valores.forEach((val, idx) => {
            if (val !== "" && valores.indexOf(val) !== idx) duplicados.add(val);
            if (val === "") hayVacios = true;
        });

        inputs.forEach(input => {
            const esDuo = input.value.trim() !== "" && duplicados.has(input.value.trim().toUpperCase());
            input.style.border = esDuo ? '2px solid #e74c3c' : '1px solid #ccc';
            input.style.backgroundColor = esDuo ? '#fdf0ed' : '#fff';
        });

        const estadoError = duplicados.size > 0;
        btnGuardar.disabled = estadoError;
        return { hayErrores: estadoError, hayVacios };
    };

    // Agregar/Quitar inputs según la cantidad
    const actualizarCamposSerie = () => {
        let cant = parseInt(qtyInput.value) || 0;
        if (cant < 0) { qtyInput.value = 0; cant = 0; }

        const actuales = seriesContainer.querySelectorAll('.serie-item-wrapper');
        
        if (cant < actuales.length) {
            let riesgo = 0;
            for (let i = cant; i < actuales.length; i++) {
                const input = actuales[i].querySelector('input');
                if (input.value.trim() !== "" || (input.getAttribute('data-id') && input.getAttribute('data-id') !== 'nuevo')) {
                    riesgo++;
                }
            }
            if (riesgo > 0 && !confirm(`¡Atención! Borrarás ${riesgo} serie(s) ya capturadas o guardadas. ¿Estás seguro?`)) {
                qtyInput.value = valorPrevioQty;
                return;
            }
        }

        if (cant > actuales.length) {
            for (let i = actuales.length + 1; i <= cant; i++) {
                const div = document.createElement('div');
                div.className = 'serie-item-wrapper';
                div.style = "background: white; padding: 10px; border: 1px solid #ddd; border-radius: 4px;";
                div.innerHTML = `
                    <label style="font-size: 0.8rem; color: #7f8c8d; font-weight: bold;">SERIE EQUIPO ${i}</label>
                    <input type="text" class="serie-edit-input" data-id="nuevo" value="" required style="width: 100%; padding: 5px; margin-top: 5px; border: 1px solid #ccc; border-radius: 3px;">
                `;
                div.querySelector('input').addEventListener('input', validarSeries);
                seriesContainer.appendChild(div);
            }
        } else {
            for (let i = actuales.length; i > cant; i--) seriesContainer.lastElementChild.remove();
        }

        valorPrevioQty = qtyInput.value;
        validarSeries();
    };

    qtyInput.addEventListener('input', actualizarCamposSerie);

    // Carga de Datos
    const cargarDatos = async () => {
        try {
            const resp = await fetch(`../backend/obtener_venta_full.php?id=${ventaId}`);
            const data = await resp.json();

            if (data.exito) {
                document.getElementById('txt-folio').textContent = data.venta.folio;
                document.getElementById('cliente').value = data.venta.cliente;
                document.getElementById('sucursal').value = data.venta.sucursal;

                if(data.series.length > 0) {
                    const d = data.series[0];
                    document.getElementById('equipo').value = d.equipo || '';
                    document.getElementById('marca').value = d.marca || '';
                    document.getElementById('modelo').value = d.modelo || '';
                    document.getElementById('garantia').value = d.garantia || 0;
                    document.getElementById('calibracion').value = d.calibracion || 0;
                    document.getElementById('notas').value = d.notas || '';
                    
                    // Configurar checkbox y frecuencia
                    document.getElementById('servicio').checked = (d.servicio == 1);
                    if (d.servicio == 1) {
                        contenedorFrecuencia.style.display = 'block';
                        inputFrecuencia.required = true;
                        inputFrecuencia.value = d.frecuencia_servicio || 6;
                    }
                }

                qtyInput.value = data.series.length;
                valorPrevioQty = data.series.length;

                seriesContainer.innerHTML = data.series.map((s, index) => `
                    <div class="serie-item-wrapper" style="background: white; padding: 10px; border: 1px solid #ddd; border-radius: 4px;">
                        <label style="font-size: 0.8rem; color: #7f8c8d; font-weight: bold;">SERIE EQUIPO ${index + 1}</label>
                        <input type="text" class="serie-edit-input" data-id="${s.id}" value="${s.numero_serie}" required style="width: 100%; padding: 5px; margin-top: 5px; border: 1px solid #ccc; border-radius: 3px;">
                    </div>
                `).join('');

                document.querySelectorAll('.serie-edit-input').forEach(i => i.addEventListener('input', validarSeries));

                cargarArchivosAdjuntosVentas(data.archivos);
            }
        } catch (e) {
            showNotification("Error al cargar los datos", "error");
        }
    };
    cargarDatos();

    // Guardar los cambios (FormData)
    btnGuardar.addEventListener('click', async () => {
        const form = document.getElementById('form-editar-venta');
        const { hayErrores, hayVacios } = validarSeries();
        
        if (hayVacios) {
            showNotification("Asegúrate de llenar todos los números de serie.", "error");
            return;
        }

        if (!form.checkValidity() || hayErrores) {
            form.reportValidity();
            return;
        }

        const formData = new FormData(form);
        formData.set('servicio', document.getElementById('servicio').checked ? 1 : 0);

        const inputsSeries = document.querySelectorAll('.serie-edit-input');
        const seriesData = Array.from(inputsSeries).map(input => ({
            id_detalle: input.getAttribute('data-id'),
            serie: input.value.trim().toUpperCase()
        }));
        formData.append('series_json', JSON.stringify(seriesData));

        try {
            btnGuardar.disabled = true;
            btnGuardar.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Guardando...';

            const resp = await fetch('../backend/actualizar-venta.php', { method: 'POST', body: formData });
            const res = await resp.json();
            if (res.exito) {
                showNotification("Venta actualizada correctamente", "success");
                setTimeout(() => location.reload(), 1500); 
            } else {
                throw new Error(res.mensaje);
            }
        } catch (e) {
            showNotification(e.message || "Error al conectar con el servidor", "error");
        } finally {
            btnGuardar.disabled = false;
            btnGuardar.innerHTML = '<i class="fas fa-save"></i> Guardar Cambios de la Venta';
        }
    });

    document.getElementById('btn-eliminar-venta').addEventListener('click', async () => {
        if(confirm("¡ATENCIÓN! Esto borrará permanentemente la venta, sus series y todos los archivos. ¿Proceder?")) {
            try {
                const resp = await fetch('../backend/eliminar_venta.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ id: ventaId })
                });
                const res = await resp.json();
                if(res.exito) {
                    alert("Venta eliminada del sistema.");
                    window.location.href = 'ventas.html';
                } else {
                    showNotification(res.mensaje, "error");
                }
            } catch(e) {
                showNotification("Error de conexión al eliminar", "error");
            }
        }
    });
});