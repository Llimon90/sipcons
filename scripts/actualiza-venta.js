// Configuración de PDF.js
const pdfjsLib = window['pdfjs-dist/build/pdf'];
pdfjsLib.GlobalWorkerOptions.workerSrc = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/2.16.105/pdf.worker.min.js';

// Configuración de rutas
const BASE_UPLOADS_PATH = '/uploads/ventas/';

document.addEventListener('DOMContentLoaded', () => {
    const urlParams = new URLSearchParams(window.location.search);
    const ventaId = urlParams.get('id');
    const mensaje = document.getElementById('mensaje');
    const folio = document.getElementById('folio');
    const form = document.getElementById('form-venta');
    const btnGuardar = document.getElementById('btn-guardar');
    const uploadBtn = document.getElementById('upload-btn');
    const filesInput = document.getElementById('file-upload');
    const filesContainer = document.getElementById('files-container');
    
    // Mostrar mensajes
    const mostrarNotificacion = (text, type = 'info') => {
        mensaje.textContent = text;
        mensaje.className = `mensaje ${type}`;
        if (type === 'success') setTimeout(() => { 
            mensaje.textContent = ''; 
            mensaje.className = 'mensaje'; 
        }, 5000);
    };
    
    // Función para renderizar miniaturas de PDF
    async function renderizarMiniaturaPDF(url, canvas) {
        try {
            // Verificar primero si la URL es accesible
            const response = await fetch(url, { method: 'HEAD' });
            if (!response.ok) {
                throw new Error(`El archivo no existe o no se puede acceder (${response.status})`);
            }

            const pdf = await pdfjsLib.getDocument({
                url: url,
                // Opcional: agregar headers si es necesario para autenticación
                // httpHeaders: { 'Authorization': 'Bearer token' }
            }).promise;
            
            const page = await pdf.getPage(1);
            const viewport = page.getViewport({ scale: 0.5 });
            
            canvas.height = viewport.height;
            canvas.width = viewport.width;
            
            await page.render({
                canvasContext: canvas.getContext('2d'),
                viewport: viewport
            }).promise;
        } catch (error) {
            console.error('Error al renderizar PDF:', error);
            // Mostrar un icono genérico de PDF cuando falle
            if (canvas && canvas.parentElement) {
                canvas.parentElement.innerHTML = `
                    <div class="file-icon">PDF</div>
                    <div class="file-error">Vista previa no disponible</div>
                `;
            }
            throw error;
        }
    }
    
    // Función para eliminar archivos
    async function eliminarArchivoVenta(archivoId, element) {
        if (!confirm('¿Está seguro de eliminar este archivo?')) return;
        
        try {
            element.classList.add('eliminando');
            
            const response = await fetch('../backend/eliminar-archivo-venta.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ id: archivoId })
            });
            
            const data = await response.json();
            
            if (!data.exito) throw new Error(data.mensaje || 'Error al eliminar');
            
            element.remove();
            mostrarNotificacion('Archivo eliminado correctamente', 'success');
        } catch (error) {
            console.error('Error al eliminar archivo:', error);
            mostrarNotificacion(error.message, 'error');
            element.classList.remove('eliminando');
        }
    }
    
    // Cargar datos de la venta
    const cargarVenta = async () => {
        try {
            const response = await fetch(`../backend/obtener-venta.php?id=${ventaId}`);
            const data = await response.json();
            
            if (!data.exito) throw new Error(data.mensaje || 'Error al cargar venta');
            
            // Mostrar datos en el formulario
            folio.textContent = data.venta.folio || 'Sin folio';
            form.cliente.value = data.venta.cliente || '';
            form.sucursal.value = data.venta.sucursal || '';
            form.equipo.value = data.venta.equipo || '';
            form.marca.value = data.venta.marca || '';
            form.modelo.value = data.venta.modelo || '';
            form.numero_serie.value = data.venta.numero_serie || '';
            form.garantia.value = data.venta.garantia || '';
            form.notas.value = data.venta.notas || '';
            form.servicio.checked = data.venta.servicio || false;
            
            // Cargar archivos adjuntos
            cargarArchivosVenta(ventaId);
            
        } catch (error) {
            console.error('Error:', error);
            mostrarNotificacion(error.message, 'error');
        }
    };
    
    // Guardar cambios
    const guardarCambios = async () => {
        try {
            const data = {
                id: ventaId,
                cliente: form.cliente.value.trim(),
                sucursal: form.sucursal.value.trim(),
                equipo: form.equipo.value.trim(),
                marca: form.marca.value.trim(),
                modelo: form.modelo.value.trim(),
                numero_serie: form.numero_serie.value.trim(),
                garantia: form.garantia.value.trim(),
                servicio: form.servicio.checked,
                notas: form.notas.value.trim()
            };
            
            const response = await fetch('../backend/actualizar-venta.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(data)
            });
            
            const result = await response.json();
            
            if (!result.exito) throw new Error(result.mensaje || 'Error al actualizar');
            
            mostrarNotificacion('Cambios guardados correctamente', 'success');
        } catch (error) {
            console.error('Error:', error);
            mostrarNotificacion(error.message, 'error');
        }
    };
    
    // Función para cargar archivos
    async function cargarArchivosVenta(ventaId) {
        try {
            const response = await fetch(`../backend/obtener-archivos-venta.php?venta_id=${ventaId}`);
            const data = await response.json();
            
            filesContainer.innerHTML = '';
            
            if (data.exito && data.archivos.length > 0) {
                for (const archivo of data.archivos) {
                    await crearMiniaturaArchivo(archivo, filesContainer);
                }
            } else {
                filesContainer.innerHTML = '<p>No hay archivos adjuntos</p>';
            }
        } catch (error) {
            console.error('Error al cargar archivos:', error);
            mostrarNotificacion('Error al cargar archivos adjuntos', 'error');
        }
    }

    // Función para crear miniaturas
    async function crearMiniaturaArchivo(archivo, container) {
        const ext = archivo.nombre.split('.').pop().toLowerCase();
        const fileUrl = `${BASE_UPLOADS_PATH}${archivo.nombre}`;
        
        const fileElement = document.createElement('div');
        fileElement.className = 'file-thumbnail';
        fileElement.dataset.id = archivo.id;
        
        // Contenido de la miniatura
        let thumbnailContent;
        
        if (['jpg', 'jpeg', 'png', 'gif', 'webp'].includes(ext)) {
            thumbnailContent = `
                <img src="${fileUrl}" alt="${archivo.nombre_original}" 
                     onerror="this.onerror=null; this.parentElement.innerHTML='<div class=\\'file-icon\\'>IMG</div>'">`;
        } else if (ext === 'pdf') {
            thumbnailContent = `
                <canvas class="pdf-thumbnail"></canvas>
                <div class="pdf-loading">Cargando vista previa...</div>`;
        } else {
            thumbnailContent = `<div class="file-icon">${ext.toUpperCase()}</div>`;
        }
        
        fileElement.innerHTML = `
            ${thumbnailContent}
            <div class="file-name">${acortarNombre(archivo.nombre_original, 15)}</div>
            <div class="file-actions">
                <a href="${fileUrl}" download="${archivo.nombre_original}" 
                   class="btn-download" title="Descargar">↓</a>
                <button class="delete-file" title="Eliminar archivo">×</button>
            </div>
        `;
        
        container.appendChild(fileElement);
        
        // Renderizar PDF si es necesario
        if (ext === 'pdf') {
            try {
                await renderizarMiniaturaPDF(fileUrl, fileElement.querySelector('.pdf-thumbnail'));
                const loadingElement = fileElement.querySelector('.pdf-loading');
                if (loadingElement) loadingElement.remove();
            } catch (error) {
                console.error('Error al renderizar PDF:', error);
                const errorElement = fileElement.querySelector('.pdf-loading');
                if (errorElement) {
                    errorElement.textContent = 'Vista previa no disponible';
                    errorElement.style.color = '#f44336';
                }
            }
        }
        
        // Evento para eliminar
        fileElement.querySelector('.delete-file').addEventListener('click', () => {
            eliminarArchivoVenta(archivo.id, fileElement);
        });
    }

    // Función para subir archivos
    uploadBtn.addEventListener('click', async () => {
        if (filesInput.files.length === 0) {
            mostrarNotificacion('Seleccione al menos un archivo', 'error');
            return;
        }
        
        try {
            const formData = new FormData();
            formData.append('venta_id', ventaId);
            formData.append('upload_path', 'ventas');
            
            for (let i = 0; i < filesInput.files.length; i++) {
                formData.append('archivos[]', filesInput.files[i]);
            }
            
            const response = await fetch('../backend/subir-archivo-venta.php', {
                method: 'POST',
                body: formData
            });
            
            const data = await response.json();
            
            if (!data.exito) throw new Error(data.mensaje || 'Error al subir archivos');
            
            mostrarNotificacion('Archivos subidos correctamente', 'success');
            filesInput.value = '';
            cargarArchivosVenta(ventaId);
        } catch (error) {
            console.error('Error al subir archivos:', error);
            mostrarNotificacion(error.message, 'error');
        }
    });
    
    // Función auxiliar para acortar nombres
    function acortarNombre(nombre, maxLength) {
        if (nombre.length <= maxLength) return nombre;
        return nombre.substring(0, maxLength) + '...';
    }
    
    // Event listeners
    btnGuardar.addEventListener('click', guardarCambios);
    
    // Inicialización
    if (ventaId) {
        cargarVenta();
    } else {
        mostrarNotificacion('No se especificó ID de venta', 'error');
    }
});