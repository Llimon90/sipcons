// Configuración global de PDF.js
pdfjsLib.GlobalWorkerOptions.workerSrc = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/2.10.377/pdf.worker.min.js';

document.addEventListener('DOMContentLoaded', async () => {
    await cargarArchivos();
});

async function cargarArchivos() {
    try {
        const urlParams = new URLSearchParams(window.location.search);
        const id = urlParams.get("id");
        const response = await fetch(`../backend/get_ticket.php?id=${id}`);
        const data = await response.json();
        const archivos = JSON.parse(data.archivos);

        const contenedorArchivos = document.getElementById("contenedor-archivos");
        contenedorArchivos.innerHTML = '';

        archivos.forEach(archivo => {
            const ext = archivo.split('.').pop().toLowerCase();
            const thumbnailContainer = document.createElement('div');
            thumbnailContainer.classList.add('thumbnail-container');
            const link = document.createElement('a');
            link.href = archivo;
            link.target = "_blank";
            link.classList.add("archivo-link");

            if (["jpg", "jpeg", "png", "gif", "bmp", "webp"].includes(ext)) {
                const img = document.createElement('img');
                img.src = archivo;
                img.classList.add('archivo-thumbnail');
                img.onerror = () => showFallbackThumbnail(thumbnailContainer, archivo, 'Imagen');
                link.appendChild(img);
                addFileMetadata(thumbnailContainer, archivo, 'Imagen');

            } else if (["mp4", "webm", "ogg", "mov"].includes(ext)) {
                const video = document.createElement('video');
                video.src = archivo;
                video.preload = 'metadata';
                video.muted = true;
                video.playsInline = true;
                video.crossOrigin = 'anonymous';
                video.style.display = 'none';
                document.body.appendChild(video);

                video.addEventListener('loadedmetadata', () => {
                    video.currentTime = Math.min(1, video.duration / 4);
                });

                video.addEventListener('seeked', () => {
                    const canvas = document.createElement('canvas');
                    canvas.width = video.videoWidth;
                    canvas.height = video.videoHeight;
                    const ctx = canvas.getContext('2d');
                    ctx.drawImage(video, 0, 0, canvas.width, canvas.height);
                    link.innerHTML = '';
                    link.appendChild(canvas);
                    addPlayIcon(thumbnailContainer);
                    addFileMetadata(thumbnailContainer, archivo, 'Video');
                    document.body.removeChild(video);
                });

                video.addEventListener('error', () => {
                    showFallbackThumbnail(thumbnailContainer, archivo, 'Video');
                    document.body.removeChild(video);
                });

            } else if (ext === "pdf") {
                const canvas = document.createElement('canvas');
                renderPDFThumbnail(archivo, canvas);
                link.appendChild(canvas);
                addFileMetadata(thumbnailContainer, archivo, 'PDF');

            } else {
                showFallbackThumbnail(thumbnailContainer, archivo, 'Archivo');
            }

            const eliminarBtn = document.createElement('button');
            eliminarBtn.textContent = "Eliminar";
            eliminarBtn.classList.add("btn", "btn-danger", "mt-2");
            eliminarBtn.addEventListener("click", () => eliminarArchivo(archivo));
            thumbnailContainer.appendChild(link);
            thumbnailContainer.appendChild(eliminarBtn);
            contenedorArchivos.appendChild(thumbnailContainer);
        });

    } catch (error) {
        console.error("Error al cargar archivos:", error);
    }
}

function renderPDFThumbnail(url, canvas) {
    const loadingTask = pdfjsLib.getDocument(url);
    loadingTask.promise.then(pdf => {
        pdf.getPage(1).then(page => {
            const viewport = page.getViewport({ scale: 1 });
            const context = canvas.getContext('2d');
            canvas.height = viewport.height;
            canvas.width = viewport.width;
            const renderContext = { canvasContext: context, viewport };
            page.render(renderContext);
        });
    }).catch(error => {
        console.error('Error al renderizar miniatura de PDF:', error);
    });
}

function showFallbackThumbnail(container, archivo, tipo) {
    const fallback = document.createElement('div');
    fallback.classList.add('archivo-fallback');
    fallback.textContent = `${tipo} no soportado`;
    const link = document.createElement('a');
    link.href = archivo;
    link.target = "_blank";
    link.appendChild(fallback);
    container.appendChild(link);
    addFileMetadata(container, archivo, tipo);
}

function addFileMetadata(container, archivo, tipo) {
    const metadata = document.createElement('div');
    metadata.className = 'file-metadata';
    metadata.textContent = `${tipo}: ${archivo}`;
    container.appendChild(metadata);
}

function addPlayIcon(container) {
    const icon = document.createElement('span');
    icon.className = 'play-icon';
    icon.textContent = '▶️';
    container.appendChild(icon);
}

function eliminarArchivo(archivo) {
    if (confirm(`¿Seguro que deseas eliminar este archivo?\n${archivo}`)) {
        // Implementa la lógica para eliminar el archivo del servidor si es necesario
        alert("Archivo eliminado (simulado).");
    }
}

document.getElementById("form-editar").addEventListener("submit", async function (e) {
    e.preventDefault();
    const cliente = document.getElementById("cliente").value.trim();
    if (!cliente) {
        alert("El campo 'CLIENTE' es obligatorio.");
        return;
    }

    // Resto del manejo del envío del formulario
    alert("Formulario enviado correctamente (simulado).");
});

    

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

    // Iniciar la carga de datos
    cargarDetalleIncidencia();
});