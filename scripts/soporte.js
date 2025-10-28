// Variables globales
let currentMarcaId = null;
let currentModeloId = null;

// Inicialización
document.addEventListener('DOMContentLoaded', function() {
    console.log('Inicializando sistema de soporte...');
    cargarMarcas();
    
    // Event listeners
    document.getElementById('addModelBtn').addEventListener('click', mostrarModalAgregarModelo);
    document.getElementById('cancelAddModel').addEventListener('click', cerrarModalAgregarModelo);
    document.getElementById('modelForm').addEventListener('submit', guardarModelo);
    document.getElementById('searchBtn').addEventListener('click', buscarContenido);
    document.getElementById('searchInput').addEventListener('keypress', function(e) {
        if (e.key === 'Enter') buscarContenido();
    });
    
    // Modales
    document.querySelectorAll('.close-modal').forEach(closeBtn => {
        closeBtn.addEventListener('click', function() {
            this.closest('.modal').style.display = 'none';
        });
    });
    
    console.log('Sistema de soporte inicializado');
});

// Cargar marcas desde la BD
async function cargarMarcas() {
    console.log('Cargando marcas...');
    const container = document.getElementById('marcas-container');
    
    try {
        const response = await fetch('../backend/soporte_backend.php?action=get_marcas');
        
        if (!response.ok) {
            throw new Error(`HTTP error! status: ${response.status}`);
        }
        
        const text = await response.text();
        console.log('Respuesta del servidor:', text);
        
        const data = JSON.parse(text);
        
        if (data.success) {
            console.log(`Marcas cargadas: ${data.count}`);
            mostrarMarcas(data.marcas);
        } else {
            throw new Error(data.message || 'Error desconocido al cargar marcas');
        }
    } catch (error) {
        console.error('Error al cargar marcas:', error);
        container.innerHTML = `
            <div class="error">
                <i class="fas fa-exclamation-triangle"></i>
                <p>Error al cargar las marcas</p>
                <small>${error.message}</small>
                <button class="btn-primary" onclick="cargarMarcas()">
                    <i class="fas fa-redo"></i> Reintentar
                </button>
            </div>
        `;
    }
}

// Mostrar marcas en el grid
function mostrarMarcas(marcas) {
    const container = document.getElementById('marcas-container');
    
    if (!marcas || marcas.length === 0) {
        container.innerHTML = `
            <div class="no-data">
                <i class="fas fa-industry fa-3x"></i>
                <p>No hay marcas registradas</p>
                <button class="btn-primary" onclick="mostrarModalAgregarMarca()">
                    <i class="fas fa-plus"></i> Agregar Primera Marca
                </button>
            </div>
        `;
        return;
    }
    
    container.innerHTML = marcas.map(marca => `
        <div class="model-card" onclick="cargarModelos(${marca.id}, '${marca.nombre.replace(/'/g, "\\'")}')">
            <div class="model-icon">
                <i class="fas fa-industry"></i>
            </div>
            <h3>${marca.nombre}</h3>
            <p>Ver modelos</p>
        </div>
    `).join('');
    
    console.log(`Mostrando ${marcas.length} marcas`);
}

// Cargar modelos de una marca
async function cargarModelos(marcaId, marcaNombre) {
    console.log(`Cargando modelos para marca ${marcaId}: ${marcaNombre}`);
    currentMarcaId = marcaId;
    
    const modelosContainer = document.getElementById('modelos-container');
    modelosContainer.innerHTML = '<div class="loading">Cargando modelos...</div>';
    
    try {
        const response = await fetch(`../backend/soporte_backend.php?action=get_modelos&marca_id=${marcaId}`);
        
        if (!response.ok) {
            throw new Error(`HTTP error! status: ${response.status}`);
        }
        
        const data = await response.json();
        
        if (data.success) {
            console.log(`Modelos cargados: ${data.count}`);
            mostrarModelos(data.modelos, marcaNombre);
            actualizarBreadcrumb(marcaNombre);
        } else {
            throw new Error(data.message || 'Error desconocido al cargar modelos');
        }
    } catch (error) {
        console.error('Error al cargar modelos:', error);
        modelosContainer.innerHTML = `
            <div class="error">
                <i class="fas fa-exclamation-triangle"></i>
                <p>Error al cargar los modelos</p>
                <small>${error.message}</small>
                <button class="btn-secondary" onclick="volverAMarcas()">
                    <i class="fas fa-arrow-left"></i> Volver a Marcas
                </button>
            </div>
        `;
    }
}

// Mostrar modelos en el grid
function mostrarModelos(modelos, marcaNombre) {
    const marcasContainer = document.getElementById('marcas-container');
    const modelosContainer = document.getElementById('modelos-container');
    
    marcasContainer.style.display = 'none';
    modelosContainer.style.display = 'block';
    
    if (!modelos || modelos.length === 0) {
        modelosContainer.innerHTML = `
            <div class="no-data">
                <i class="fas fa-tools fa-3x"></i>
                <p>No hay modelos registrados para ${marcaNombre}</p>
                <button class="btn-primary" onclick="mostrarModalAgregarModelo()">
                    <i class="fas fa-plus"></i> Agregar Primer Modelo
                </button>
                <button class="btn-secondary" onclick="volverAMarcas()">
                    <i class="fas fa-arrow-left"></i> Volver a Marcas
                </button>
            </div>
        `;
        return;
    }
    
    modelosContainer.innerHTML = `
        <div class="section-header">
            <button class="back-button" onclick="volverAMarcas()">
                <i class="fas fa-arrow-left"></i> Volver a Marcas
            </button>
            <h3>Modelos de ${marcaNombre} (${modelos.length})</h3>
        </div>
        <div class="model-grid-content">
            ${modelos.map(modelo => `
                <div class="model-card" onclick="cargarDocumentos(${modelo.id}, '${modelo.nombre.replace(/'/g, "\\'")}')">
                    <div class="model-icon">
                        <i class="fas fa-laptop"></i>
                    </div>
                    <h3>${modelo.nombre}</h3>
                    <p>${modelo.tipo_equipo}</p>
                    <div class="model-actions">
                        <button class="btn-small" onclick="event.stopPropagation();">
                            <i class="fas fa-edit"></i>
                        </button>
                    </div>
                </div>
            `).join('')}
        </div>
    `;
}

// Cargar documentos de un modelo
async function cargarDocumentos(modeloId, modeloNombre) {
    console.log(`Cargando documentos para modelo ${modeloId}: ${modeloNombre}`);
    currentModeloId = modeloId;
    
    const docList = document.getElementById('doc-list');
    docList.innerHTML = '<div class="loading">Cargando documentos...</div>';
    
    try {
        const response = await fetch(`../backend/soporte_backend.php?action=get_documentos&modelo_id=${modeloId}`);
        
        if (!response.ok) {
            throw new Error(`HTTP error! status: ${response.status}`);
        }
        
        const data = await response.json();
        
        if (data.success) {
            console.log(`Documentos cargados: ${data.count}`);
            mostrarDocumentos(data.documentos, modeloNombre);
            actualizarBreadcrumbDocumentos(modeloNombre);
        } else {
            throw new Error(data.message || 'Error desconocido al cargar documentos');
        }
    } catch (error) {
        console.error('Error al cargar documentos:', error);
        docList.innerHTML = `
            <div class="error">
                <i class="fas fa-exclamation-triangle"></i>
                <p>Error al cargar los documentos</p>
                <small>${error.message}</small>
            </div>
        `;
    }
}

// Mostrar documentos
function mostrarDocumentos(documentos, modeloNombre) {
    const modelosContainer = document.getElementById('modelos-container');
    const documentosContainer = document.getElementById('documentos-container');
    
    modelosContainer.style.display = 'none';
    documentosContainer.style.display = 'block';
    
    const docList = document.getElementById('doc-list');
    const modeloTitle = document.getElementById('modelo-title');
    
    modeloTitle.textContent = `Documentos - ${modeloNombre}`;
    
    if (!documentos || documentos.length === 0) {
        docList.innerHTML = `
            <div class="no-data">
                <i class="fas fa-file-pdf fa-3x"></i>
                <p>No hay documentos para este modelo</p>
                <small>Puedes agregar documentos al crear el modelo</small>
            </div>
        `;
    } else {
        docList.innerHTML = documentos.map(doc => `
            <div class="doc-item">
                <div class="doc-icon">
                    <i class="fas fa-file-pdf"></i>
                </div>
                <div class="doc-info">
                    <h4>${doc.nombre_archivo}</h4>
                    <p>Tipo: ${doc.tipo_documento.replace('_', ' ')}</p>
                    <small>Subido: ${new Date(doc.fecha_subida).toLocaleDateString()}</small>
                </div>
                <div class="doc-actions">
                    <button class="btn-primary" onclick="verDocumento('${doc.ruta_publica || doc.ruta_archivo}')">
                        <i class="fas fa-eye"></i> Ver
                    </button>
                    <button class="btn-secondary" onclick="descargarDocumento('${doc.ruta_publica || doc.ruta_archivo}', '${doc.nombre_archivo}')">
                        <i class="fas fa-download"></i> Descargar
                    </button>
                </div>
            </div>
        `).join('');
    }
    
    // Configurar botón volver
    document.getElementById('back-to-models').onclick = volverAModelos;
}

// Modal para agregar marca
function mostrarModalAgregarMarca() {
    const marcaNombre = prompt('Ingrese el nombre de la nueva marca:');
    if (marcaNombre && marcaNombre.trim()) {
        agregarMarca(marcaNombre.trim());
    }
}

// Agregar nueva marca
async function agregarMarca(nombre) {
    try {
        console.log('Agregando nueva marca:', nombre);
        
        const response = await fetch('../backend/soporte_backend.php?action=add_marca', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({ nombre: nombre })
        });
        
        if (!response.ok) {
            throw new Error(`HTTP error! status: ${response.status}`);
        }
        
        const data = await response.json();
        
        if (data.success) {
            alert('Marca agregada correctamente');
            cargarMarcas();
        } else {
            throw new Error(data.message || 'Error desconocido al agregar marca');
        }
    } catch (error) {
        console.error('Error al agregar marca:', error);
        alert('Error al agregar la marca: ' + error.message);
    }
}

// Modal para agregar modelo - MEJORADA
async function mostrarModalAgregarModelo() {
    console.log('Mostrando modal para agregar modelo');
    
    try {
        const response = await fetch('../backend/soporte_backend.php?action=get_marcas');
        
        if (!response.ok) {
            throw new Error(`Error HTTP: ${response.status}`);
        }

        const text = await response.text();
        let data;
        
        try {
            data = JSON.parse(text);
        } catch (parseError) {
            throw new Error('Respuesta no es JSON válido');
        }
        
        if (data.success) {
            const selectMarca = document.getElementById('marca');
            selectMarca.innerHTML = '<option value="">Seleccionar marca</option>' +
                data.marcas.map(marca => `<option value="${marca.id}">${marca.nombre}</option>`).join('');
            
            // Agregar opción para nueva marca
            const optionNuevaMarca = document.createElement('option');
            optionNuevaMarca.value = 'new';
            optionNuevaMarca.textContent = '+ Agregar nueva marca';
            selectMarca.appendChild(optionNuevaMarca);
            
            // Manejar selección de nueva marca
            selectMarca.addEventListener('change', function() {
                if (this.value === 'new') {
                    mostrarModalAgregarMarca();
                    this.value = '';
                }
            });
            
            // Si estamos en una marca específica, seleccionarla
            if (currentMarcaId) {
                selectMarca.value = currentMarcaId;
            }

            // Limpiar y mostrar el modal
            document.getElementById('modelForm').reset();
            document.getElementById('addModelModal').style.display = 'block';
            
        } else {
            throw new Error(data.message || 'Error al cargar marcas');
        }
    } catch (error) {
        console.error('Error al cargar marcas para el modal:', error);
        alert('Error al cargar las marcas: ' + error.message);
    }
}

// Función para guardar nuevo modelo - CORREGIDA
async function guardarModelo(e) {
    e.preventDefault();
    console.log('Guardando nuevo modelo...');
    
    // Obtener los valores del formulario
    const marca_id = document.getElementById('marca').value;
    const modelo_nombre = document.getElementById('modelo_nombre').value.trim();
    const modelo_tipo = document.getElementById('modelo_tipo').value.trim();
    const manual_file = document.getElementById('manual_file').files[0];
    const partes_file = document.getElementById('partes_file').files[0];

    console.log('Datos del formulario:', {
        marca_id,
        modelo_nombre,
        modelo_tipo,
        manual_file: manual_file ? manual_file.name : 'No seleccionado',
        partes_file: partes_file ? partes_file.name : 'No seleccionado'
    });

    // Validar campos requeridos
    if (!marca_id || !modelo_nombre || !modelo_tipo) {
        alert('Por favor complete todos los campos requeridos: Marca, Nombre del Modelo y Tipo de Equipo');
        return;
    }

    // Crear FormData
    const formData = new FormData();
    formData.append('marca_id', marca_id);
    formData.append('nombre', modelo_nombre);
    formData.append('tipo_equipo', modelo_tipo);

    // Agregar archivos si están seleccionados
    if (manual_file) {
        formData.append('manual_file', manual_file);
    }
    if (partes_file) {
        formData.append('partes_file', partes_file);
    }

    // Mostrar loading
    const submitBtn = document.querySelector('#modelForm button[type="submit"]');
    const originalText = submitBtn.innerHTML;
    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Guardando...';
    submitBtn.disabled = true;

    try {
        console.log('Enviando datos al servidor...');
        const response = await fetch('../backend/soporte_backend.php?action=add_modelo', {
            method: 'POST',
            body: formData
        });

        console.log('Respuesta del servidor recibida, status:', response.status);
        
        if (!response.ok) {
            throw new Error(`Error HTTP: ${response.status}`);
        }

        const text = await response.text();
        console.log('Respuesta del servidor:', text);

        let data;
        try {
            data = JSON.parse(text);
        } catch (parseError) {
            console.error('Error parseando JSON:', parseError);
            throw new Error('Respuesta del servidor no es JSON válido: ' + text);
        }
        
        if (data.success) {
            alert('✅ Modelo agregado correctamente');
            cerrarModalAgregarModelo();
            
            // Recargar la vista actual
            if (currentMarcaId) {
                const marcaNombre = document.querySelector('#marca option:checked').textContent;
                cargarModelos(currentMarcaId, marcaNombre);
            } else {
                cargarMarcas();
            }
        } else {
            throw new Error(data.message || 'Error desconocido al agregar modelo');
        }
    } catch (error) {
        console.error('Error al agregar modelo:', error);
        alert('❌ Error al agregar el modelo: ' + error.message);
    } finally {
        // Restaurar botón
        submitBtn.innerHTML = originalText;
        submitBtn.disabled = false;
    }
}
// Funciones de navegación
function volverAMarcas() {
    console.log('Volviendo a marcas');
    document.getElementById('modelos-container').style.display = 'none';
    document.getElementById('documentos-container').style.display = 'none';
    document.getElementById('marcas-container').style.display = 'block';
    actualizarBreadcrumb();
    currentMarcaId = null;
    currentModeloId = null;
}

function volverAModelos() {
    console.log('Volviendo a modelos');
    document.getElementById('documentos-container').style.display = 'none';
    document.getElementById('modelos-container').style.display = 'block';
    actualizarBreadcrumb(document.querySelector('#marcas-container .model-card h3')?.textContent || '');
    currentModeloId = null;
}

function cerrarModalAgregarModelo() {
    console.log('Cerrando modal de agregar modelo');
    document.getElementById('addModelModal').style.display = 'none';
    document.getElementById('modelForm').reset();
}

// Actualizar breadcrumb
function actualizarBreadcrumb(marcaNombre = '') {
    const breadcrumb = document.getElementById('breadcrumb');
    if (marcaNombre) {
        breadcrumb.innerHTML = `<a href="javascript:volverAMarcas()">Inicio</a> > <span>${marcaNombre}</span>`;
    } else {
        breadcrumb.innerHTML = `<a href="soporte.html">Inicio</a> > <span>Marcas</span>`;
    }
}

function actualizarBreadcrumbDocumentos(modeloNombre) {
    const breadcrumb = document.getElementById('breadcrumb');
    breadcrumb.innerHTML = `
        <a href="javascript:volverAMarcas()">Inicio</a> > 
        <a href="javascript:volverAModelos()">Marcas</a> > 
        <span>${modeloNombre}</span>
    `;
}

// Funciones para documentos - ACTUALIZADAS
function verDocumento(ruta) {
    console.log('Viendo documento:', ruta);
    const modal = document.getElementById('previewModal');
    const pdfViewer = document.getElementById('pdfViewer');
    
    // Usar el script de descarga para visualizar
    const url = `../backend/descargar_documento.php?archivo=${encodeURIComponent(ruta)}`;
    pdfViewer.src = url;
    modal.style.display = 'block';
}

function descargarDocumento(ruta, nombre) {
    console.log('Descargando documento:', nombre);
    const url = `../backend/descargar_documento.php?archivo=${encodeURIComponent(ruta)}&download=1`;
    
    // Crear link temporal para descarga
    const link = document.createElement('a');
    link.href = url;
    link.download = nombre;
    link.style.display = 'none';
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
}// Buscar contenido
function buscarContenido() {
    const searchTerm = document.getElementById('searchInput').value.toLowerCase();
    console.log('Buscando:', searchTerm);
    alert('Funcionalidad de búsqueda en desarrollo. Término: ' + searchTerm);
}

// Cerrar modales al hacer click fuera
window.onclick = function(event) {
    const modals = document.querySelectorAll('.modal');
    modals.forEach(modal => {
        if (event.target === modal) {
            modal.style.display = 'none';
        }
    });
}

// Exportar funciones para debugging
window.soporteApp = {
    cargarMarcas,
    cargarModelos,
    cargarDocumentos,
    mostrarModalAgregarMarca,
    mostrarModalAgregarModelo
};