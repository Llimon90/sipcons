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

                    // Ajustar canvas para miniatura (estilo consistente con imágenes)
                    canvas.style.maxWidth = '100%';
                    canvas.style.maxHeight = '150px';
                    canvas.style.objectFit = 'contain';
                    canvas.style.display = 'block';
                    canvas.style.margin = '0 auto';
                    container.appendChild(canvas);

                    // Agregar metadatos
                    addFileMetadata(container, url, 'PDF');
                }
            });
        }).catch(error => {
            console.error('Error al renderizar PDF:', error);
            showFallbackThumbnail(containerId, url, 'PDF');
        });
    }

    // Función para crear miniatura de video (estilo consistente)
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

            const thumbnail = new Image();
            thumbnail.src = canvas.toDataURL();
            thumbnail.className = 'file-thumbnail'; // Clase consistente
            thumbnail.style.maxWidth = '100%';
            thumbnail.style.maxHeight = '150px';
            thumbnail.style.objectFit = 'contain';
            thumbnail.style.display = 'block';
            thumbnail.style.margin = '0 auto';
            container.appendChild(thumbnail);

            // Agregar metadatos e icono de play
            addFileMetadata(container, url, 'Video');
            addPlayIcon(container);
        };

        video.onerror = function () {
            showFallbackThumbnail(containerId, url, 'Video');
        };
    }

    // Función para crear miniatura de imagen (base para otros tipos)
    function createImageThumbnail(url, containerId) {
        const container = document.getElementById(containerId);
        if (!container) return;

        const img = document.createElement('img');
        img.src = url;
        img.className = 'file-thumbnail';
        img.style.maxWidth = '100%';
        img.style.maxHeight = '150px';
        img.style.objectFit = 'contain';
        img.style.display = 'block';
        img.style.margin = '0 auto';

        img.onload = function() {
            container.innerHTML = '';
            container.appendChild(img);
            addFileMetadata(container, url, 'Imagen');
        };

        img.onerror = function() {
            showFallbackThumbnail(containerId, url, 'Imagen');
        };
    }

    // Función auxiliar para agregar metadatos del archivo (estilo consistente)
    function addFileMetadata(container, url, type) {
        const metadataContainer = document.createElement('div');
        metadataContainer.className = 'file-metadata';
        metadataContainer.style.textAlign = 'center';
        metadataContainer.style.marginTop = '8px';
        metadataContainer.style.fontSize = '12px';

        const fileName = document.createElement('div');
        fileName.className = 'file-name';
        fileName.textContent = getShortFileName(url);
        fileName.style.whiteSpace = 'nowrap';
        fileName.style.overflow = 'hidden';
        fileName.style.textOverflow = 'ellipsis';
        
        const fileType = document.createElement('div');
        fileType.className = 'file-type';
        fileType.textContent = type;
        fileType.style.color = '#666';
        fileType.style.fontSize = '11px';

        metadataContainer.appendChild(fileName);
        metadataContainer.appendChild(fileType);
        container.appendChild(metadataContainer);
    }

    // Función auxiliar para agregar icono de play (estilo consistente)
    function addPlayIcon(container) {
        const playIcon = document.createElement('div');
        playIcon.innerHTML = '▶';
        playIcon.style.position = 'absolute';
        playIcon.style.top = '50%';
        playIcon.style.left = '50%';
        playIcon.style.transform = 'translate(-50%, -50%)';
        playIcon.style.color = 'white';
        playIcon.style.fontSize = '24px';
        playIcon.style.textShadow = '0 0 5px rgba(0,0,0,0.5)';
        container.appendChild(playIcon);
    }

    // Función auxiliar para mostrar miniatura genérica (estilo consistente)
    function showFallbackThumbnail(containerId, url, type) {
        const container = document.getElementById(containerId);
        if (!container) return;

        const icons = {
            'PDF': '📄',
            'Video': '🎬',
            'Imagen': '🖼️',
            'DOC': '📝',
            'DOCX': '📝',
            'XLS': '📊',
            'XLSX': '📊',
            'PPT': '📽️',
            'PPTX': '📽️',
            'TXT': '📑',
            'ZIP': '🗄️',
            'RAR': '🗄️',
            'default': '📁'
        };

        container.innerHTML = `
            <div class="file-icon" style="font-size: 50px; text-align: center; margin: 10px 0;">${icons[type] || icons.default}</div>
        `;
        
        // Agregar metadatos con el mismo estilo
        addFileMetadata(container, url, type);
    }

    // Función auxiliar para obtener nombre corto de archivo
    function getShortFileName(url, maxLength = 20) {
        const fileName = url.split('/').pop();
        return fileName.length > maxLength
            ? fileName.substring(0, maxLength) + '...'
            : fileName;
    }

    // Función auxiliar para mostrar notificación
    function showNotification(message, type = 'success') {
        const notification = document.createElement('div');
        notification.className = `notificacion ${type}`;
        notification.textContent = message;
        document.body.appendChild(notification);

        setTimeout(() => notification.remove(), 3000);
    }

    // Función para eliminar archivos (versión mejorada)
    async function eliminarArchivo(urlArchivo, containerElement) {
        if (!confirm('¿Estás seguro de que deseas eliminar este archivo permanentemente?')) {
            return;
        }

        // Mostrar estado de carga
        containerElement.classList.add('eliminando');

        try {
            // Crear FormData para enviar los datos
            const formData = new FormData();
            formData.append('id_incidencia', id);

            // Asegurarse de que la URL sea relativa al servidor
            const urlRelativa = new URL(urlArchivo).pathname;
            formData.append('url_archivo', urlRelativa);

            const response = await fetch("../backend/eliminar_archivo.php", {
                method: "POST",
                body: formData
            });

            const data = await response.json();

            if (!response.ok || !data.success) {
                console.error('Error del servidor:', data);
                throw new Error(data.error || `Error al eliminar el archivo. Código: ${response.status}`);
            }

            // Eliminar visualmente el contenedor del archivo
            containerElement.remove();

            // Mostrar notificación de éxito
            mostrarNotificacion('Archivo eliminado correctamente', 'success');

        } catch (error) {
            console.error("Error al eliminar archivo:", error);

            // Mostrar detalles de depuración si están disponibles
            const mensajeError = error.message || 'Error desconocido al eliminar el archivo';
            mostrarNotificacion(mensajeError, 'error');

            containerElement.classList.remove('eliminando');
        }
    }

    // Función para cargar y mostrar archivos adjuntos (versión mejorada para todos los tipos)
    function cargarArchivosAdjuntos(archivos) {
        const contenedorArchivos = document.getElementById("contenedor-archivos");
        contenedorArchivos.innerHTML = "";

        if (archivos && archivos.length > 0) {
            archivos.forEach((archivo, index) => {
                const ext = archivo.split('.').pop().toLowerCase();
                const archivoContainer = document.createElement('div');
                archivoContainer.className = 'archivo-container';
                archivoContainer.style.position = 'relative';
                archivoContainer.style.textAlign = 'center';
                archivoContainer.style.margin = '10px';
                archivoContainer.style.padding = '10px';
                archivoContainer.style.border = '1px solid #ddd';
                archivoContainer.style.borderRadius = '5px';
                archivoContainer.style.width = '200px';
                archivoContainer.style.display = 'inline-block';
                archivoContainer.style.verticalAlign = 'top';
                
                const containerId = `file-container-${index}`;
                archivoContainer.id = containerId;

                // Crear enlace para abrir el archivo
                const link = document.createElement('a');
                link.href = archivo;
                link.target = '_blank';
                link.style.textDecoration = 'none';
                link.style.color = 'inherit';
                link.style.display = 'block';

                // Determinar el tipo de archivo y mostrar la miniatura apropiada
                if (["jpg", "jpeg", "png", "gif", "webp"].includes(ext)) {
                    // Miniaturas para imágenes
                    createImageThumbnail(archivo, containerId);
                } else if (ext === "pdf") {
                    // Miniaturas para PDF
                    showFallbackThumbnail(containerId, archivo, 'PDF');
                    setTimeout(() => renderPDFThumbnail(archivo, containerId), 100);
                } else if (["mp4", "webm", "ogg", "mov"].includes(ext)) {
                    // Miniaturas para video
                    showFallbackThumbnail(containerId, archivo, 'Video');
                    setTimeout(() => createVideoThumbnail(archivo, containerId), 100);
                } else if (["doc", "docx"].includes(ext)) {
                    // Documentos de Word
                    showFallbackThumbnail(containerId, archivo, 'DOC');
                } else if (["xls", "xlsx"].includes(ext)) {
                    // Hojas de cálculo
                    showFallbackThumbnail(containerId, archivo, 'XLS');
                } else if (["ppt", "pptx"].includes(ext)) {
                    // Presentaciones
                    showFallbackThumbnail(containerId, archivo, 'PPT');
                } else if (["zip", "rar"].includes(ext)) {
                    // Archivos comprimidos
                    showFallbackThumbnail(containerId, archivo, 'ZIP');
                } else {
                    // Icono genérico para otros tipos de archivo
                    showFallbackThumbnail(containerId, archivo, ext.toUpperCase());
                }

                // Agregar botón de eliminar
                const deleteBtn = document.createElement('button');
                deleteBtn.className = 'eliminar-archivo';
                deleteBtn.innerHTML = '×';
                deleteBtn.style.position = 'absolute';
                deleteBtn.style.top = '5px';
                deleteBtn.style.right = '5px';
                deleteBtn.style.background = 'red';
                deleteBtn.style.color = 'white';
                deleteBtn.style.border = 'none';
                deleteBtn.style.borderRadius = '50%';
                deleteBtn.style.width = '20px';
                deleteBtn.style.height = '20px';
                deleteBtn.style.cursor = 'pointer';
                deleteBtn.style.display = 'flex';
                deleteBtn.style.alignItems = 'center';
                deleteBtn.style.justifyContent = 'center';
                deleteBtn.style.padding = '0';
                deleteBtn.onclick = (e) => {
                    e.preventDefault();
                    e.stopPropagation();
                    eliminarArchivo(archivo, archivoContainer);
                };

                archivoContainer.appendChild(link);
                archivoContainer.appendChild(deleteBtn);
                contenedorArchivos.appendChild(archivoContainer);
            });
        } else {
            contenedorArchivos.innerHTML = "<p>No hay archivos adjuntos.</p>";
        }
    }

    // Cargar datos de la incidencia (sin cambios)
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
                               accept=".pdf,.jpg,.jpeg,.png,.gif,.webp,.mp4,.webm,.ogg,.mov,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.txt,.zip,.rar" 
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

//     // Iniciar la carga de datos
//     cargarDetalleIncidencia();
// });