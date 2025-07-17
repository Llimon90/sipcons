pdfjsLib.GlobalWorkerOptions.workerSrc = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/2.16.105/pdf.worker.min.js';

// Funciones de utilidad
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

// Funciones relacionadas con archivos
async function eliminarArchivo(urlArchivo, containerElement, id) {
    if (!confirm('¿Estás seguro de que deseas eliminar este archivo permanentemente?')) {
        return;
    }

    containerElement.classList.add('eliminando');

    try {
        const formData = new FormData();
        formData.append('id_incidencia', id);

        // Extraemos SOLO el nombre del archivo (basename)
        const nombreArchivo = urlArchivo.split('/').pop();
        formData.append('url_archivo', nombreArchivo);

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
        previewElement.style.cursor = 'pointer';
    } else if (ext === 'pdf') {
        previewElement = document.createElement('canvas');
        previewElement.style.maxWidth = '100px';
        previewElement.style.maxHeight = '100px';
        previewElement.style.cursor = 'pointer';
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
    }

    return previewElement;
}

function createFileContainer(archivo, ext, id) {
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

    const previewElement = createFilePreview(archivo, ext);
    if (previewElement) {
        previewElement.onclick = () => window.open(archivo, '_blank');
        link.appendChild(previewElement);
    }

    const fileNameSpan = document.createElement('span');
    fileNameSpan.textContent = getShortFileName(archivo);
    fileNameSpan.style.display = 'block';
    fileNameSpan.style.textAlign = 'center';
    link.appendChild(fileNameSpan);

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
        eliminarArchivo(archivo, archivoContainer, id);
    };

    archivoContainer.appendChild(link);
    archivoContainer.appendChild(deleteBtn);

    return { container: archivoContainer, preview: previewElement };
}

async function cargarArchivosAdjuntos(archivos, id) {
    const contenedorArchivos = document.getElementById("contenedor-archivos");
    contenedorArchivos.innerHTML = "";

    if (archivos && archivos.length > 0) {
        for (const archivo of archivos) {
            const ext = archivo.split('.').pop().toLowerCase();
            const { container, preview } = createFileContainer(archivo, ext, id);

            if (ext === 'pdf' && preview) {
                try {
                    await renderPdfThumbnail(archivo, preview);
                } catch (error) {
                    preview.remove();
                    const errorSpan = document.createElement('span');
                    errorSpan.textContent = 'Error al cargar miniatura';
                    errorSpan.style.display = 'block';
                    errorSpan.style.textAlign = 'center';
                    container.querySelector('a').appendChild(errorSpan);
                }
            }

            contenedorArchivos.appendChild(container);
        }
    } else {
        contenedorArchivos.innerHTML = "<p>No hay archivos adjuntos.</p>";
    }
}

// Funciones relacionadas con el formulario
function createFormHTML(data) {
    // Convertir técnico existente en array si no lo es
    const tecnicosIniciales = Array.isArray(data.tecnico) ? data.tecnico : 
                            (data.tecnico ? [data.tecnico] : []);

    return `
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
                    <input type="text" id="sucursal" value="${data.sucursal || ''}"  style="width: 100%;">
                </div>
            </div>

            <div style="display: flex; gap: 20px; margin-bottom: 15px;">
                <div style="flex: 1;">
                    <label>FECHA:</label>
                    <input type="date" id="fecha" value="${data.fecha || ''}" required style="width: 100%;">
                </div>
                
            <div style="flex: 1;">
                <label>TÉCNICOS:</label>
                <div id="tecnicos-container">
                    ${tecnicosIniciales.map((tecnico, index) => `
<div class="tecnico-group" style="margin-bottom: 10px; display: flex; align-items: center;">
    <select name="tecnicos[]" class="tecnico-select" ${index === 0 ? '' : 'required'} style="width: 90%;">
        <option value="" ${!tecnico ? 'selected' : ''}>Sin técnico asignado</option>
        <option value="Victor Hugo Cordoba" ${tecnico === "Victor Hugo Cordoba" ? 'selected' : ''}>Victor Hugo Cordoba</option>
        <option value="Tomás Valdéz" ${tecnico === "Tomás Valdéz" ? 'selected' : ''}>Tomás Valdéz</option>
        <option value="Francisco Aguiar" ${tecnico === "Francisco Aguiar" ? 'selected' : ''}>Francisco Aguiar</option>
        <option value="Mauricio Díaz" ${tecnico === "Mauricio Díaz" ? 'selected' : ''}>Mauricio Díaz</option>
        <option value="Humberto Vázquez" ${tecnico === "Humberto Vázquez" ? 'selected' : ''}>Humberto Vázquez</option>
        <option value="Jose López" ${tecnico === "Jose López" ? 'selected' : ''}>José López</option>
        <option value="Hoscar Martínez" ${tecnico === "Hoscar Martínez" ? 'selected' : ''}>Hoscar Martínez</option>
        <option value="Jacob Ventura" ${tecnico === "Jacob Ventura" ? 'selected' : ''}>Jacob Ventura</option>
        <option value="Luis Limón" ${tecnico === "Luis Limón" ? 'selected' : ''}>Luis Limón</option>
        <option value="Ernesto Chávez" ${tecnico === "Ernesto Chávez" ? 'selected' : ''}>Ernesto Chávez</option>
    </select>
    <button type="button" class="eliminar-tecnico" style="background: none; border: none; cursor: pointer; padding: 0; margin-left: 5px;">
        <i class="fas fa-trash-alt" style="color: #ff0000;"></i>
    </button>
</div>
`).join('')}
${tecnicosIniciales.length === 0 ? `
    <div class="tecnico-group" style="margin-bottom: 10px; display: flex; align-items: center;">
        <select name="tecnicos[]" class="tecnico-select" style="width: 90%;">
              <option value="">Seleccione una opción</option>
              <option value="Victor Hugo Cordoba">Victor Cordoba</option>
              <option value="Tomás Valdéz">Tomás Valdéz</option>
              <option value="Francisco Aguiar">Francisco Aguiar</option>
              <option value="Mauricio Díaz">Mauricio Diaz</option>
              <option value="Humberto Vázquez">Humberto Vázquez</option>
              <option value="Jose López">José López</option>
              <option value="Hoscar Martínez">Hoscar Martínez</option>
              <option value="Jacob Ventura">Jacob Ventura</option>
              <option value="Luis Limón">Luis Limón</option>
              <option value="Ernesto Chávez">Ernesto Chávez</option>
        </select>
        <button type="button" class="eliminar-tecnico" style="background: none; border: none; cursor: pointer; padding: 0; margin-left: 5px;">
            <i class="fas fa-trash-alt" style="color: #ff0000;"></i>
        </button>
    </div>
` : ''}
                </div>
                <button type="button" id="agregar-tecnico" style="margin-top: 5px; padding: 5px 10px; background-color: #4CAF50; color: white; border: none; border-radius: 4px; cursor: pointer;">
                    + Agregar técnico
                </button>
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

        <script>
            document.addEventListener('DOMContentLoaded', function() {
                const tecnicosContainer = document.getElementById('tecnicos-container');
                const agregarTecnicoBtn = document.getElementById('agregar-tecnico');
                
                // Función para actualizar las opciones disponibles en los selects
                function actualizarOpcionesTecnicos() {
                    const selects = document.querySelectorAll('.tecnico-select');
                    const selectedValues = Array.from(selects).map(select => select.value);
                    
                    selects.forEach(select => {
                        const currentValue = select.value;
                        Array.from(select.options).forEach(option => {
                            if (option.value && option.value !== '') {
                                option.disabled = selectedValues.includes(option.value) && option.value !== currentValue;
                            }
                        });
                    });
                }
                
                // Agregar nuevo técnico
                agregarTecnicoBtn.addEventListener('click', function() {
                    const tecnicoGroup = document.createElement('div');
                    tecnicoGroup.className = 'tecnico-group';
                    tecnicoGroup.style.marginBottom = '10px';
                    tecnicoGroup.style.display = 'flex';
                    tecnicoGroup.style.alignItems = 'center';
                    
                    const select = document.createElement('select');
                    select.name = 'tecnicos[]';
                    select.className = 'tecnico-select';
                    select.required = true;
                    select.style.width = '90%';
                    
                    select.innerHTML = \`
              <option value="">Seleccione una opción</option>
              <option value="Victor Hugo Cordoba">Victor Cordoba</option>
              <option value="Tomás Valdéz">Tomás Valdéz</option>
              <option value="Francisco Aguiar">Francisco Aguiar</option>
              <option value="Mauricio Díaz">Mauricio Diaz</option>
              <option value="Humberto Vázquez">Humberto Vázquez</option>
              <option value="Jose López">José López</option>
              <option value="Hoscar Martínez">Hoscar Martínez</option>
              <option value="Jacob Ventura">Jacob Ventura</option>
              <option value="Luis Limón">Luis Limón</option>
              <option value="Ernesto Chávez">Ernesto Chávez</option>
                    \`;
                    
                    const deleteBtn = document.createElement('button');
                    deleteBtn.type = 'button';
                    deleteBtn.className = 'eliminar-tecnico';
                    deleteBtn.innerHTML = '×';
                    deleteBtn.style.marginLeft = '5px';
                    deleteBtn.style.background = 'red';
                    deleteBtn.style.color = 'white';
                    deleteBtn.style.border = 'none';
                    deleteBtn.style.borderRadius = '50%';
                    deleteBtn.style.width = '20px';
                    deleteBtn.style.height = '20px';
                    deleteBtn.style.cursor = 'pointer';
                    
                    deleteBtn.addEventListener('click', function() {
                        tecnicoGroup.remove();
                        actualizarOpcionesTecnicos();
                    });
                    
                    select.addEventListener('change', actualizarOpcionesTecnicos);
                    
                    tecnicoGroup.appendChild(select);
                    tecnicoGroup.appendChild(deleteBtn);
                    tecnicosContainer.appendChild(tecnicoGroup);
                    
                    actualizarOpcionesTecnicos();
                });
                
                // Configurar eventos para los selects existentes
                document.querySelectorAll('.tecnico-select').forEach(select => {
                    select.addEventListener('change', actualizarOpcionesTecnicos);
                });
                
                // Configurar eventos para los botones de eliminar existentes
                document.querySelectorAll('.eliminar-tecnico').forEach(btn => {
                    btn.addEventListener('click', function() {
                        this.closest('.tecnico-group').remove();
                        actualizarOpcionesTecnicos();
                    });
                });
            });
        </script>
    `;
}

function setupTecnicosMultiples() {
    const tecnicosContainer = document.getElementById('tecnicos-container');
    const agregarTecnicoBtn = document.getElementById('agregar-tecnico');
    
    if (!agregarTecnicoBtn) return;
    
    // Función para actualizar las opciones disponibles
    function actualizarOpcionesTecnicos() {
        const selects = document.querySelectorAll('.tecnico-select');
        const selectedValues = Array.from(selects)
            .map(select => select.value)
            .filter(val => val);
        
        selects.forEach(select => {
            const currentValue = select.value;
            Array.from(select.options).forEach(option => {
                if (option.value && option.value !== '') {
                    option.disabled = selectedValues.includes(option.value) && option.value !== currentValue;
                }
            });
        });
    }
    
    function crearSelectTecnico() {
        const tecnicoGroup = document.createElement('div');
        tecnicoGroup.className = 'tecnico-group';
        tecnicoGroup.style.marginBottom = '10px';
        tecnicoGroup.style.display = 'flex';
        tecnicoGroup.style.alignItems = 'center';
        
        const select = document.createElement('select');
        select.name = 'tecnicos[]';
        select.className = 'tecnico-select';
        select.required = true;
        select.style.width = '90%';
        
        select.innerHTML = `
              <option value="">Seleccione una opción</option>
              <option value="Victor Hugo Cordoba">Victor Cordoba</option>
              <option value="Tomás Valdéz">Tomás Valdéz</option>
              <option value="Francisco Aguiar">Francisco Aguiar</option>
              <option value="Mauricio Díaz">Mauricio Diaz</option>
              <option value="Humberto Vázquez">Humberto Vázquez</option>
              <option value="Jose López">José López</option>
              <option value="Hoscar Martínez">Hoscar Martínez</option>
              <option value="Jacob Ventura">Jacob Ventura</option>
              <option value="Luis Limón">Luis Limón</option>
              <option value="Ernesto Chávez">Ernesto Chávez</option>
        `;
        
        const deleteBtn = document.createElement('button');
        deleteBtn.type = 'button';
        deleteBtn.className = 'eliminar-tecnico';
        deleteBtn.innerHTML = '<i class="fas fa-trash-alt" style="color: #ff0000;"></i>';
        deleteBtn.style.background = 'none';
        deleteBtn.style.border = 'none';
        deleteBtn.style.cursor = 'pointer';
        deleteBtn.style.padding = '0';
        deleteBtn.style.marginLeft = '5px';
        
        deleteBtn.addEventListener('click', function() {
            tecnicoGroup.remove();
            actualizarOpcionesTecnicos();
        });
        
        select.addEventListener('change', actualizarOpcionesTecnicos);
        
        tecnicoGroup.appendChild(select);
        tecnicoGroup.appendChild(deleteBtn);
        tecnicosContainer.appendChild(tecnicoGroup);
        
        actualizarOpcionesTecnicos();
    }
    
    // Evento para el botón de agregar técnico
    agregarTecnicoBtn.addEventListener('click', crearSelectTecnico);
    
    // Configurar eventos para los selects existentes
    document.querySelectorAll('.tecnico-select').forEach(select => {
        select.addEventListener('change', actualizarOpcionesTecnicos);
    });
    
    // Configurar eventos para los botones de eliminar existentes
    document.querySelectorAll('.eliminar-tecnico').forEach(btn => {
        btn.addEventListener('click', function() {
            this.closest('.tecnico-group').remove();
            actualizarOpcionesTecnicos();
        });
    });
}

async function handleFormSubmit(e, id) {
    e.preventDefault();

    const formData = new FormData();
    formData.append("id", id);
    formData.append("numero", document.getElementById("numero").value);
    formData.append("cliente", document.getElementById("cliente").value);
    formData.append("contacto", document.getElementById("contacto").value);
    formData.append("sucursal", document.getElementById("sucursal").value);
    formData.append("fecha", document.getElementById("fecha").value);
    
    // Obtener todos los técnicos seleccionados y unirlos con "/"
    const tecnicosSelects = document.querySelectorAll('.tecnico-select');
    const tecnicos = Array.from(tecnicosSelects)
        .map(select => select.value)
        .filter(tecnico => tecnico); // Filtrar valores vacíos
    
    formData.append("tecnico", tecnicos.join('/')); // Unir con "/"
    
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
        
        // Actualizar la página después de guardar los cambios
        setTimeout(() => {
            window.location.reload();
        }, 1500);

    } catch (error) {
        console.error("Error al actualizar incidencia:", error);
        showNotification(error.message, 'error');
    }
}

// Función principal para cargar los detalles
async function cargarDetalleIncidencia(id) {
    try {
        const response = await fetch(`../backend/detalle.php?id=${id}`);
        const data = await response.json();

        if (!response.ok || data.error) {
            throw new Error(data.error || 'Error al cargar los detalles');
        }

        document.getElementById("detalle-incidencia").innerHTML = createFormHTML(data);
        
        // Configurar la funcionalidad de múltiples técnicos
        setupTecnicosMultiples();

        if (data.archivos) {
            cargarArchivosAdjuntos(data.archivos, id);
        }

        document.getElementById("form-editar").addEventListener("submit", (e) => handleFormSubmit(e, id));

    } catch (error) {
        console.error("Error al cargar detalles:", error);
        document.getElementById("detalle-incidencia").innerHTML =
            `<p>Error al cargar los detalles: ${error.message}</p>`;
    }
}

// Inicialización
document.addEventListener("DOMContentLoaded", function () {
    const params = new URLSearchParams(window.location.search);
    const id = params.get("id");

    if (!id) {
        document.getElementById("detalle-incidencia").innerHTML = "<p>Error: ID no encontrado.</p>";
        return;
    }

    cargarDetalleIncidencia(id);
});