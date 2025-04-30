// Configuración global de PDF.js
pdfjsLib.GlobalWorkerOptions.workerSrc = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/2.16.105/pdf.worker.min.js';

document.addEventListener("DOMContentLoaded", function () {
    const params = new URLSearchParams(window.location.search);
    const id = params.get("id");

    if (!id) {
        document.getElementById("detalle-incidencia").innerHTML = "<p>Error: ID no encontrado.</p>";
        return;
    }

    // Función para renderizar miniaturas de PDF
function renderPDFThumbnail(url, containerId) {
    const loadingTask = pdfjsLib.getDocument(url);

    loadingTask.promise.then(pdf => {
        return pdf.getPage(1);
    }).then(page => {
        const viewport = page.getViewport({ scale: 1.0 });
        const canvas = document.createElement('canvas');
        const context = canvas.getContext('2d');
        canvas.height = viewport.height;
        canvas.width = viewport.width;

        const renderContext = {
            canvasContext: context,
            viewport: viewport
        };

        return page.render(renderContext).promise.then(() => {
            const container = document.getElementById(containerId);
            if (container) {
                container.innerHTML = '';

                // Crear enlace que envuelve la miniatura
                const link = document.createElement('a');
                link.href = url;
                link.target = '_blank';
                link.style.textDecoration = 'none';
                link.style.color = 'inherit';
                link.style.display = 'block';
                link.style.height = '100%';

                // Ajustar canvas para miniatura
                canvas.style.maxWidth = '100%';
                canvas.style.height = '100px';
                canvas.style.objectFit = 'contain';
                link.appendChild(canvas);

                // Agregar metadatos
                addFileMetadata(link, url, 'PDF');

                // Agregar botón de eliminar
                const deleteBtn = document.createElement('button');
                deleteBtn.className = 'eliminar-archivo';
                deleteBtn.innerHTML = '×';
                deleteBtn.onclick = (e) => {
                    e.preventDefault();
                    e.stopPropagation();
                    eliminarArchivo(url, container);
                };

                container.appendChild(link);
                container.appendChild(deleteBtn);
            }
        });
    }).catch(error => {
        console.error('Error al renderizar PDF:', error);
        showFallbackThumbnail(containerId, url, 'PDF');
    });
}

// Función para crear miniatura de video
function createVideoThumbnail(url, containerId) {
    const container = document.getElementById(containerId);
    if (!container) return;

    const video = document.createElement('video');
    video.src = url;
    video.preload = 'metadata';

    video.onloadedmetadata = function () {
        video.currentTime = Math.min(1, video.duration / 4);
    };

    video.onseeked = function () {
        const canvas = document.createElement('canvas');
        canvas.width = video.videoWidth;
        canvas.height = video.videoHeight;
        const ctx = canvas.getContext('2d');
        ctx.drawImage(video, 0, 0, canvas.width, canvas.height);

        container.innerHTML = '';

        // Crear enlace que envuelve la miniatura
        const link = document.createElement('a');
        link.href = url;
        link.target = '_blank';
        link.style.textDecoration = 'none';
        link.style.color = 'inherit';
        link.style.display = 'block';
        link.style.height = '100%';

        const thumbnail = new Image();
        thumbnail.src = canvas.toDataURL();
        thumbnail.className = 'video-thumbnail';
        link.appendChild(thumbnail);

        // Agregar metadatos e icono de play
        addFileMetadata(link, url, 'Video');
        addPlayIcon(link);

        // Agregar botón de eliminar
        const deleteBtn = document.createElement('button');
        deleteBtn.className = 'eliminar-archivo';
        deleteBtn.innerHTML = '×';
        deleteBtn.onclick = (e) => {
            e.preventDefault();
            e.stopPropagation();
            eliminarArchivo(url, container);
        };

        container.appendChild(link);
        container.appendChild(deleteBtn);
    };

    video.onerror = function () {
        showFallbackThumbnail(containerId, url, 'Video');
    };
}

    // Cargar datos de la incidencia
    async function cargarDetalleIncidencia() {
        try {
            const response = await fetch(`../backend/detalle.php?id=${id}`);
            const data = await response.json();

            if (!response.ok || data.error) {
                throw new Error(data.error || 'Error al cargar los detalles');
            }

            // Mostrar formulario de edición
            document.getElementById("detalle-incidencia").innerHTML = `
                <form id="form-editar">
                    <p><strong># REPORTE INTERNO:</strong> ${data.numero_incidente}</p>
                    <div style="display: flex; gap: 20px; margin-bottom: 15px;">
                        <div style="flex: 1;">
                            <label># INCIDENCIA CLIENTE:</label> 
                            <input type="text" id="numero" value="${data.numero || ''}" style="width: 100%;">
                        </div>    
                        <div style="flex: 1;">
                            <label>CLIENTE:</label> 
                            <input type="text" id="cliente" value="${data.cliente || ''}" required style="width: 100%;">
                        </div>  
                    </div>
                    
                    <div style="display: flex; gap: 20px; margin-bottom: 15px;">
                        <div style="flex: 1;">
                            <label>CONTACTO:</label>
                            <input type="text" id="contacto" value="${data.contacto || ''}" required style="width: 100%;">
                        </div>
                        <div style="flex: 1;">
                            <label>SUCURSAL:</label>
                            <input type="text" id="sucursal" value="${data.sucursal || ''}" required style="width: 100%;">
                        </div>
                    </div>
                    
                    <div style="display: flex; gap: 20px; margin-bottom: 15px;">
                        <div style="flex: 1;">
                            <label>FECHA:</label>
                            <input type="date" id="fecha" value="${data.fecha || ''}" required style="width: 100%;">
                        </div>
                        <div style="flex: 1;">
                            <label>TÉCNICO:</label>
                            <input type="text" id="tecnico" value="${data.tecnico || ''}" required style="width: 100%;">
                        </div>
                    </div>
                    
                    <div style="margin-bottom: 15px;">
                        <label>ESTATUS:</label>
                        <select id="estatus" style="width: 100%;">
                            <option value="Abierta" ${data.estatus === "Abierta" ? 'selected' : ''}>Abierta</option>
                            <option value="Pendiente" ${data.estatus === "Pendiente" ? 'selected' : ''}>Pendiente</option>
                            <option value="En seguimiento" ${data.estatus === "En seguimiento" ? 'selected' : ''}>En seguimiento</option>
                            <option value="Cerrada" ${data.estatus === "Cerrada" ? 'selected' : ''}>Cerrada</option>
                            <option value="Facturada" ${data.estatus === "Facturada" ? 'selected' : ''}>Facturada</option>
                        </select>
                    </div>
                    
                    <div style="margin-bottom: 15px;">
                        <label>FALLA:</label>
                        <textarea id="falla" required style="width: 100%;">${data.falla || ''}</textarea>
                    </div>
                    
                    <div style="margin-bottom: 15px;">
                        <label>TRABAJO REALIZADO:</label>
                        <textarea id="accion" style="width: 100%;">${data.accion || ''}</textarea>
                    </div>
                    
                    <div style="margin-bottom: 15px;">
                        <label>AGREGAR NUEVOS ARCHIVOS:</label>
                        <input type="file" id="archivos" name="archivos[]" multiple 
                               accept=".pdf,.jpg,.jpeg,.png,.gif,.webp,.mp4,.webm,.ogg,.mov" 
                               style="width: 100%;">
                    </div>
                    
                    <button type="submit" style="padding: 10px 20px; background-color: #4CAF50; color: white; border: none; border-radius: 4px; cursor: pointer;">
                        Guardar cambios
                    </button>
                </form>
            `;

            // Cargar archivos adjuntos
            if (data.archivos) {
                cargarArchivosAdjuntos(data.archivos);
            }

            // Configurar evento del formulario
            document.getElementById("form-editar").addEventListener("submit", async function (e) {
                e.preventDefault();
                
                const formData = new FormData();
                formData.append("id", id);
                formData.append("numero", document.getElementById("numero").value);
                formData.append("cliente", document.getElementById("cliente").value);
                formData.append("contacto", document.getElementById("contacto").value);
                formData.append("sucursal", document.getElementById("sucursal").value);
                formData.append("fecha", document.getElementById("fecha").value);
                formData.append("tecnico", document.getElementById("tecnico").value);
                formData.append("estatus", document.getElementById("estatus").value);
                formData.append("falla", document.getElementById("falla").value);
                formData.append("accion", document.getElementById("accion").value);

                // Agregar archivos nuevos
                const archivosInput = document.getElementById("archivos").files;
                for (let i = 0; i < archivosInput.length; i++) {
                    formData.append("archivos[]", archivosInput[i]);
                }

                try {
                    const response = await fetch("../backend/actualiza.php", {
                        method: "POST",
                        body: formData
                    });
                    
                    const data = await response.json();

                    if (!response.ok || !data.success) {
                        throw new Error(data.error || 'Error al actualizar la incidencia');
                    }

                    showNotification('Incidencia actualizada correctamente');
                    
                    // Actualizar lista de archivos si hay nuevos
                    if (data.archivos) {
                        cargarArchivosAdjuntos(data.archivos);
                        document.getElementById("archivos").value = '';
                    }
                    
                } catch (error) {
                    console.error("Error al actualizar incidencia:", error);
                    showNotification(error.message, 'error');
                }
            });

        } catch (error) {
            console.error("Error al cargar detalles:", error);
            document.getElementById("detalle-incidencia").innerHTML = 
                `<p>Error al cargar los detalles: ${error.message}</p>`;
        }
    }

    // Iniciar la carga de datos
    cargarDetalleIncidencia();
});