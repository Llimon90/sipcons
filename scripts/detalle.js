// Configuración global de PDF.js (ya no es necesaria pero la dejamos por si acaso)
pdfjsLib.GlobalWorkerOptions.workerSrc = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/2.16.105/pdf.worker.min.js';

document.addEventListener("DOMContentLoaded", function () {
    const params = new URLSearchParams(window.location.search);
    const id = params.get("id");

    if (!id) {
        document.getElementById("detalle-incidencia").innerHTML = "<p>Error: ID no encontrado.</p>";
        return;
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

    // Función para eliminar archivos
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
            showNotification('Archivo eliminado correctamente', 'success');

        } catch (error) {
            console.error("Error al eliminar archivo:", error);
            showNotification(error.message || 'Error desconocido al eliminar el archivo', 'error');
            containerElement.classList.remove('eliminando');
        }
    }

    // Función para cargar y mostrar archivos adjuntos (versión simplificada)
    function cargarArchivosAdjuntos(archivos) {
        const contenedorArchivos = document.getElementById("contenedor-archivos");
        contenedorArchivos.innerHTML = "";

        if (archivos && archivos.length > 0) {
            archivos.forEach((archivo, index) => {
                const ext = archivo.split('.').pop().toLowerCase();
                const archivoContainer = document.createElement('div');
                archivoContainer.className = 'archivo-container';
                archivoContainer.style.position = 'relative';
                archivoContainer.style.margin = '10px';
                archivoContainer.style.padding = '10px';
                archivoContainer.style.border = '1px solid #ddd';
                archivoContainer.style.borderRadius = '5px';
                archivoContainer.style.display = 'inline-block';
                
                // Crear enlace para abrir el archivo
                const link = document.createElement('a');
                link.href = archivo;
                link.target = '_blank';
                link.style.textDecoration = 'none';
                link.style.color = '#333';
                link.style.display = 'block';
                link.textContent = getShortFileName(archivo);

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