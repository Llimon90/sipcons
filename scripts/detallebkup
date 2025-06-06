pdfjsLib.GlobalWorkerOptions.workerSrc = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/2.16.105/pdf.worker.min.js';


document.addEventListener("DOMContentLoaded", function () {
    const params = new URLSearchParams(window.location.search);
    const id = params.get("id");

    if (!id) {
        document.getElementById("detalle-incidencia").innerHTML = "<p>Error: ID no encontrado.</p>";
        return;
    }

    function getShortFileName(url, maxLength = 20) {
        const fileName = url.split('/').pop();
        return fileName.length > maxLength
            ? fileName.substring(0, maxLength) + '...'
            : fileName;
    }

    function showNotification(message, type = 'success') {
        const notification = document.createElement('div');
        notification.className = `notificacion ${type}`;
        notification.textContent = message;
        document.body.appendChild(notification);
        setTimeout(() => notification.remove(), 3000);
    }

    async function eliminarArchivo(urlArchivo, containerElement) {
    if (!confirm('¿Estás seguro de que deseas eliminar este archivo permanentemente?')) {
        return;
    }

    containerElement.classList.add('eliminando');

    try {
        const formData = new FormData();
        formData.append('id_incidencia', id);

        // Extraer la ruta relativa completa del archivo desde la URL
        const url = new URL(urlArchivo, window.location.origin);
        const rutaRelativa = url.pathname.replace(/^\/+/, ''); // Elimina las barras iniciales
        formData.append('url_archivo', rutaRelativa);

        const response = await fetch("../backend/eliminar_archivo.php", {
            method: "POST",
            body: formData
        });

        const data = await response.json();

        if (!response.ok || !data.success) {
            console.error('Error del servidor:', data);
            throw new Error(data.error || `Error al eliminar el archivo. Código: ${response.status}`);
        }

        containerElement.remove();
        showNotification('Archivo eliminado correctamente', 'success');

    } catch (error) {
        console.error("Error al eliminar archivo:", error);
        showNotification(error.message || 'Error desconocido al eliminar el archivo', 'error');
        containerElement.classList.remove('eliminando');
    }
}

    
    function cargarArchivosAdjuntos(archivos) {
        const contenedorArchivos = document.getElementById("contenedor-archivos");
        contenedorArchivos.innerHTML = "";

        if (archivos && archivos.length > 0) {
            archivos.forEach(async (archivo, index) => {
                const ext = archivo.split('.').pop().toLowerCase();
                const archivoContainer = document.createElement('div');
                archivoContainer.className = 'archivo-container';
                archivoContainer.style.position = 'relative';
                archivoContainer.style.margin = '10px';
                archivoContainer.style.padding = '10px';
                archivoContainer.style.border = '1px solid #ddd';
                archivoContainer.style.borderRadius = '5px';
                archivoContainer.style.display = 'inline-block';

                const link = document.createElement('a');
                link.href = archivo;
                link.target = '_blank';
                link.style.textDecoration = 'none';
                link.style.color = '#333';
                link.style.display = 'block';

                let previewElement;

                if (['jpg', 'jpeg', 'png', 'gif', 'webp'].includes(ext)) {
                    previewElement = document.createElement('img');
                    previewElement.src = archivo;
                    previewElement.style.maxWidth = '100px';
                    previewElement.style.maxHeight = '100px';
                    previewElement.style.cursor = 'pointer';
                    previewElement.onclick = () => window.open(archivo, '_blank');
                    link.appendChild(previewElement);
                    const fileNameSpan = document.createElement('span');
                    fileNameSpan.textContent = getShortFileName(archivo);
                    fileNameSpan.style.display = 'block';
                    fileNameSpan.style.textAlign = 'center';
                    link.appendChild(fileNameSpan);
                } else if (ext === 'pdf') {
                    const canvas = document.createElement('canvas');
                    canvas.style.maxWidth = '100px';
                    canvas.style.maxHeight = '100px';
                    canvas.style.cursor = 'pointer';
                    canvas.onclick = () => window.open(archivo, '_blank');
                    link.appendChild(canvas);
                    const fileNameSpan = document.createElement('span');
                    fileNameSpan.textContent = getShortFileName(archivo);
                    fileNameSpan.style.display = 'block';
                    fileNameSpan.style.textAlign = 'center';
                    link.appendChild(fileNameSpan);

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
                        canvas.remove();
                        const errorSpan = document.createElement('span');
                        errorSpan.textContent = 'Error al cargar miniatura';
                        errorSpan.style.display = 'block';
                        errorSpan.style.textAlign = 'center';
                        link.appendChild(errorSpan);
                        link.onclick = () => window.open(archivo, '_blank');
                    }
                } else if (['mp4', 'webm', 'ogg', 'mov'].includes(ext)) {
                    previewElement = document.createElement('video');
                    previewElement.src = archivo;
                    previewElement.style.maxWidth = '100px';
                    previewElement.style.maxHeight = '100px';
                    previewElement.controls = false;
                    previewElement.muted = true;
                    previewElement.loop = true;
                    previewElement.style.cursor = 'pointer';
                    previewElement.onmouseover = () => previewElement.play();
                    previewElement.onmouseout = () => previewElement.pause();
                    previewElement.onclick = () => window.open(archivo, '_blank');
                    link.appendChild(previewElement);
                    const fileNameSpan = document.createElement('span');
                    fileNameSpan.textContent = getShortFileName(archivo);
                    fileNameSpan.style.display = 'block';
                    fileNameSpan.style.textAlign = 'center';
                    link.appendChild(fileNameSpan);
                } else {
                    link.textContent = getShortFileName(archivo);
                }

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
                    eliminarArchivo(archivo, archivoContainer); // 'archivo' es la URL completa
                };;

                archivoContainer.appendChild(link);
                archivoContainer.appendChild(deleteBtn);
                contenedorArchivos.appendChild(archivoContainer);
            });
        } else {
            contenedorArchivos.innerHTML = "<p>No hay archivos adjuntos.</p>";
        }
    }

    async function cargarDetalleIncidencia() {
        try {
            const response = await fetch(`../backend/detalle.php?id=${id}`);
            const data = await response.json();

            if (!response.ok || data.error) {
                throw new Error(data.error || 'Error al cargar los detalles');
            }

            document.getElementById("detalle-incidencia").innerHTML = `
                <form id="form-editar">
                    <p><strong># REPORTE INTERNO:</strong> ${data.numero_incidente}</p>
                    <div style="display: flex; gap: 20px; margin-bottom: 15px;">
                        <div style="flex: 1;">
                            <label># INCIDENCIA CLIENTE:</label>&nbsp;
                            <input type="text" id="numero" value="${data.numero || ''}" style="width: 100%;">
                        </div>&nbsp; &nbsp;
                        <div style="flex: 1;">
                            <label>CLIENTE:</label>&nbsp;
                            <input type="text" id="cliente" value="${data.cliente || ''}" required style="width: 100%;">
                        </div>&nbsp;&nbsp;
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

                        
                        <div id="tecnicos-container" style="flex: 1;">
                            <label for="tecnico">TÉCNICO:</label>
                                <select id="tecnico" name="tecnico" required style="width: 100%;">
                                    <option value="" disabled ${!data.tecnico ? 'selected' : ''}>Seleccione una opción</option>
                                    <option value="Victor Cordoba" ${data.tecnico === "Victor Cordoba" ? 'selected' : ''}>Victor Cordoba</option>
                                    <option value="Tomás Vázquez" ${data.tecnico === "Tomás Vázquez" ? 'selected' : ''}>Tomás Vázquez</option>
                                    <option value="Francisco Aguiar" ${data.tecnico === "Francisco Aguiar" ? 'selected' : ''}>Francisco Aguiar</option>
                                    <option value="Mauricio Díaz" ${data.tecnico === "Mauricio Díaz" ? 'selected' : ''}>Mauricio Díaz</option>
                                    <option value="Humberto Vázquez" ${data.tecnico === "Humberto Vázquez" ? 'selected' : ''}>Humberto Vázquez</option>
                                    <option value="Jose López" ${data.tecnico === "Jose López" ? 'selected' : ''}>José López</option>
                                    <option value="Hoscar Martínez" ${data.tecnico === "Hoscar Martínez" ? 'selected' : ''}>Hoscar Martínez</option>
                                    <option value="Jacob Ventura" ${data.tecnico === "Jacob Ventura" ? 'selected' : ''}>Jacob Ventura</option>
                                    <option value="Luis Limón" ${data.tecnico === "Luis Limón" ? 'selected' : ''}>Luis Limón</option>
                                    <option value="Ernesto Chávez" ${data.tecnico === "Ernesto Chávez" ? 'selected' : ''}>Ernesto Chávez</option>
                                </select>

                        </div>
                    </div>

                    <div style="margin-bottom: 15px;">
                        <label>ESTATUS:</label>
                        <select id="estatus" style="width: 100%;">
                            <option value="Abierto" ${data.estatus === "Abierto" ? 'selected' : ''}>Abierto</option>
                            <option value="Asignado" ${data.estatus === "Asignado" ? 'selected' : ''}>Asignado</option>
                            <option value="Pendiente" ${data.estatus === "Pendiente" ? 'selected' : ''}>Pendiente</option>
                            <option value="Completado" ${data.estatus === "Completado" ? 'selected' : ''}>Completado</option>
                            <option value="Cerrado sin factura" ${data.estatus === "Cerrado sin factura" ? 'selected' : ''}>Cerrado sin factura</option>
                            <option value="Cerrado con factura" ${data.estatus === "Cerrado con factura" ? 'selected' : ''}>Cerrado con factura</option>
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
                        <label>NOTAS ADICIONALES</label>
                        <textarea id="notas" style="width: 100%;">${data.notas || ''}</textarea>
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

            if (data.archivos) {
                cargarArchivosAdjuntos(data.archivos);
            }

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
                formData.append("notas", document.getElementById("notas").value);


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

    cargarDetalleIncidencia();
});


  