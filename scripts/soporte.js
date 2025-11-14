// Variables globales
let currentMarcaId = null;
let currentModeloId = null;
let searchTimeout = null;
let currentSearchTerm = '';

// Inicialización
document.addEventListener('DOMContentLoaded', function() {
    console.log('Inicializando sistema de soporte...');
    cargarMarcas();
    
    // Event listeners
    document.getElementById('addModelBtn').addEventListener('click', mostrarModalAgregarModelo);
    document.getElementById('cancelAddModel').addEventListener('click', cerrarModalAgregarModelo);
    document.getElementById('modelForm').addEventListener('submit', guardarModelo);
    
    // Búsqueda
    document.getElementById('searchInput').addEventListener('input', buscarContenido);
    document.getElementById('searchBtn').addEventListener('click', function() {
        const searchTerm = document.getElementById('searchInput').value.trim();
        if (searchTerm) {
            ejecutarBusqueda(searchTerm);
        }
    });
    
    // Limpiar búsqueda
    document.getElementById('searchInput').addEventListener('search', function() {
        if (this.value === '') {
            ocultarResultadosBusqueda();
        }
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
    container.innerHTML = '<div class="loading">Cargando marcas...</div>';
    
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
            throw new Error('Respuesta del servidor no es JSON válido');
        }
        
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
        <div class="model-card" onclick="abrirCarpetaMarca(${marca.id}, '${marca.nombre.replace(/'/g, "\\'")}', event)">
            <div class="model-icon">
                <i class="fas fa-industry"></i>
            </div>
            <h3>${marca.nombre}</h3>
            <p>Ver modelos</p>
            <div class="model-actions">
                <button class="btn-small btn-primary" onclick="cargarModelos(${marca.id}, '${marca.nombre.replace(/'/g, "\\'")}')">
                    <i class="fas fa-folder-open"></i>
                </button>
                <button class="btn-small btn-danger" onclick="eliminarMarca(${marca.id}, '${marca.nombre.replace(/'/g, "\\'")}')">
                    <i class="fas fa-trash"></i>
                </button>
            </div>
        </div>
    `).join('');
    
    console.log(`Mostrando ${marcas.length} marcas`);
}

// Función para abrir carpeta de marca al hacer clic en el contenedor
function abrirCarpetaMarca(marcaId, marcaNombre, event) {
    // Prevenir que se active si se hizo clic en un botón de acción
    if (event.target.closest('.model-actions')) {
        return;
    }
    
    // Abrir la carpeta de la marca
    cargarModelos(marcaId, marcaNombre);
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
            <button class="btn-primary" onclick="mostrarModalAgregarModelo()">
                <i class="fas fa-plus"></i> Agregar Modelo
            </button>
        </div>
        <div class="model-grid-content">
            ${modelos.map(modelo => `
                <div class="model-card" onclick="abrirCarpetaModelo(${modelo.id}, '${modelo.nombre.replace(/'/g, "\\'")}', event)">
                    <div class="model-icon">
                        <i class="fas fa-laptop"></i>
                    </div>
                    <h3>${modelo.nombre}</h3>
                    <p>${modelo.tipo_equipo}</p>
                    <div class="model-actions">
                        <button class="btn-small btn-primary" onclick="cargarDocumentos(${modelo.id}, '${modelo.nombre.replace(/'/g, "\\'")}')">
                            <i class="fas fa-folder-open"></i>
                        </button>
                        <button class="btn-small btn-warning" onclick="editarModelo(${modelo.id})">
                            <i class="fas fa-edit"></i>
                        </button>
                        <button class="btn-small btn-danger" onclick="eliminarModelo(${modelo.id}, '${modelo.nombre.replace(/'/g, "\\'")}')">
                            <i class="fas fa-trash"></i>
                        </button>
                    </div>
                </div>
            `).join('')}
        </div>
    `;
}

// Función para abrir carpeta de modelo al hacer clic en el contenedor
function abrirCarpetaModelo(modeloId, modeloNombre, event) {
    // Prevenir que se active si se hizo clic en un botón de acción
    if (event.target.closest('.model-actions')) {
        return;
    }
    
    // Abrir la carpeta del modelo
    cargarDocumentos(modeloId, modeloNombre);
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
                <button class="btn-primary" onclick="mostrarModalSubirDocumentos(${currentModeloId})">
                    <i class="fas fa-upload"></i> Subir Documentos
                </button>
            </div>
        `;
    } else {
        docList.innerHTML = `
            <div class="documentos-header">
                <button class="btn-primary" onclick="mostrarModalSubirDocumentos(${currentModeloId})">
                    <i class="fas fa-upload"></i> Subir Más Documentos
                </button>
            </div>
            ${documentos.map(doc => `
                <div class="doc-item" id="doc-${doc.id}">
                    <div class="doc-icon">
                        ${obtenerIconoTipoDocumento(doc.tipo_documento)}
                    </div>
                    <div class="doc-info">
                        <h4>${doc.nombre_archivo}</h4>
                        <p>Tipo: ${doc.tipo_documento.replace('_', ' ')}</p>
                        ${doc.descripcion ? `<p>Descripción: ${doc.descripcion}</p>` : ''}
                        <small>Subido: ${new Date(doc.fecha_subida).toLocaleDateString()}</small>
                    </div>
                    <div class="doc-actions">
                        <button class="btn-primary" onclick="verDocumento('${doc.ruta_publica || doc.ruta_archivo}')">
                            <i class="fas fa-eye"></i> Ver
                        </button>
                        <button class="btn-secondary" onclick="descargarDocumento('${doc.ruta_publica || doc.ruta_archivo}', '${doc.nombre_archivo}')">
                            <i class="fas fa-download"></i> Descargar
                        </button>
                        <button class="btn-danger" onclick="eliminarDocumento(${doc.id}, '${doc.nombre_archivo}')">
                            <i class="fas fa-trash"></i> Eliminar
                        </button>
                    </div>
                </div>
            `).join('')}
        `;
    }
    
    document.getElementById('back-to-models').onclick = volverAModelos;
}

// Funciones de gestión de modelos
async function editarModelo(modeloId) {
    try {
        const response = await fetch(`../backend/soporte_backend.php?action=get_modelo_info&modelo_id=${modeloId}`);
        const data = await response.json();
        
        if (data.success) {
            mostrarModalEditarModelo(data.modelo);
        } else {
            throw new Error(data.message || 'Error al cargar información del modelo');
        }
    } catch (error) {
        console.error('Error al editar modelo:', error);
        alert('Error al cargar información del modelo: ' + error.message);
    }
}

async function eliminarModelo(modeloId, modeloNombre) {
    if (!confirm(`¿Estás seguro de que deseas eliminar el modelo "${modeloNombre}"? Esta acción eliminará todos los documentos asociados y no se puede deshacer.`)) {
        return;
    }

    try {
        console.log('Eliminando modelo:', modeloId);
        const response = await fetch('../backend/soporte_backend.php?action=delete_modelo', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({ modelo_id: modeloId })
        });
        
        const data = await response.json();
        console.log('Respuesta eliminación modelo:', data);
        
        if (data.success) {
            alert('Modelo eliminado correctamente');
            cargarModelos(currentMarcaId, document.querySelector('#modelos-container h3').textContent.replace('Modelos de ', ''));
        } else {
            throw new Error(data.message || 'Error al eliminar modelo');
        }
    } catch (error) {
        console.error('Error al eliminar modelo:', error);
        alert('Error al eliminar el modelo: ' + error.message);
    }
}

async function eliminarMarca(marcaId, marcaNombre) {
    if (!confirm(`¿Estás seguro de que deseas eliminar la marca "${marcaNombre}"? Solo se puede eliminar si no tiene modelos activos.`)) {
        return;
    }

    try {
        console.log('Eliminando marca:', marcaId);
        const response = await fetch('../backend/soporte_backend.php?action=delete_marca', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({ marca_id: marcaId })
        });
        
        const data = await response.json();
        console.log('Respuesta eliminación marca:', data);
        
        if (data.success) {
            alert('Marca eliminada correctamente');
            cargarMarcas();
        } else {
            throw new Error(data.message || 'Error al eliminar marca');
        }
    } catch (error) {
        console.error('Error al eliminar marca:', error);
        alert('Error al eliminar la marca: ' + error.message);
    }
}

async function eliminarDocumento(documentoId, nombreArchivo) {
    if (!confirm(`¿Estás seguro de que deseas eliminar el documento "${nombreArchivo}"? Esta acción no se puede deshacer.`)) {
        return;
    }

    try {
        console.log('Eliminando documento:', documentoId);
        const response = await fetch('../backend/soporte_backend.php?action=delete_documento', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({ documento_id: documentoId })
        });
        
        const data = await response.json();
        console.log('Respuesta eliminación documento:', data);
        
        if (data.success) {
            // Eliminar del DOM
            document.getElementById(`doc-${documentoId}`).remove();
            alert('Documento eliminado correctamente');
        } else {
            throw new Error(data.message || 'Error al eliminar documento');
        }
    } catch (error) {
        console.error('Error al eliminar documento:', error);
        alert('Error al eliminar el documento: ' + error.message);
    }
}

// Funciones de búsqueda
function buscarContenido() {
    const searchTerm = document.getElementById('searchInput').value.trim();
    
    if (searchTerm.length === 0) {
        ocultarResultadosBusqueda();
        return;
    }

    if (searchTerm.length < 2) {
        return;
    }

    currentSearchTerm = searchTerm;
    
    if (searchTimeout) {
        clearTimeout(searchTimeout);
    }

    searchTimeout = setTimeout(() => {
        ejecutarBusqueda(searchTerm);
    }, 500);
}

async function ejecutarBusqueda(termino) {
    console.log('Buscando:', termino);
    
    const container = document.getElementById('marcas-container');
    container.innerHTML = '<div class="loading">Buscando...</div>';

    try {
        const response = await fetch(`../backend/soporte_backend.php?action=buscar&q=${encodeURIComponent(termino)}`);
        
        if (!response.ok) {
            throw new Error(`Error HTTP: ${response.status}`);
        }

        const data = await response.json();
        
        if (data.success && data.resultados) {
            mostrarResultadosBusqueda(data.resultados, termino);
        } else {
            throw new Error(data.message || 'Error en la búsqueda');
        }
    } catch (error) {
        console.error('Error en búsqueda:', error);
        container.innerHTML = `
            <div class="error">
                <i class="fas fa-exclamation-triangle"></i>
                <p>Error en la búsqueda</p>
                <small>${error.message}</small>
            </div>
        `;
    }
}

function mostrarResultadosBusqueda(resultados, termino) {
    const container = document.getElementById('marcas-container');
    const marcas = resultados.marcas || [];
    const modelos = resultados.modelos || [];
    const total = resultados.total || (marcas.length + modelos.length);

    if (total === 0) {
        container.innerHTML = `
            <div class="no-data">
                <i class="fas fa-search fa-3x"></i>
                <p>No se encontraron resultados para: "${termino}"</p>
                <small>Intenta con otros términos de búsqueda</small>
            </div>
        `;
        return;
    }

    let html = `
        <div class="search-results-header">
            <h3>Resultados de búsqueda: "${termino}"</h3>
            <div class="search-stats">
                ${marcas.length} marcas, ${modelos.length} modelos
            </div>
            <button class="btn-secondary" onclick="ocultarResultadosBusqueda()">
                <i class="fas fa-arrow-left"></i> Volver a todas las marcas
            </button>
        </div>
    `;

    if (marcas.length > 0) {
        html += `
            <div class="search-section">
                <h4><i class="fas fa-industry"></i> Marcas (${marcas.length})</h4>
                <div class="model-grid-content">
                    ${marcas.map(marca => `
                        <div class="model-card" onclick="abrirCarpetaMarca(${marca.id}, '${(marca.nombre || '').replace(/'/g, "\\'")}', event)">
                            <div class="model-icon">
                                <i class="fas fa-industry"></i>
                            </div>
                            <h3>${resaltarTexto(marca.nombre || '', termino)}</h3>
                            <p>Ver modelos de la marca</p>
                            <div class="search-badge">Marca</div>
                            <div class="model-actions">
                                <button class="btn-small btn-primary" onclick="cargarModelos(${marca.id}, '${(marca.nombre || '').replace(/'/g, "\\'")}')">
                                    <i class="fas fa-folder-open"></i>
                                </button>
                                <button class="btn-small btn-danger" onclick="eliminarMarca(${marca.id}, '${(marca.nombre || '').replace(/'/g, "\\'")}')">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </div>
                        </div>
                    `).join('')}
                </div>
            </div>
        `;
    }

    if (modelos.length > 0) {
        html += `
            <div class="search-section">
                <h4><i class="fas fa-laptop"></i> Modelos (${modelos.length})</h4>
                <div class="model-grid-content">
                    ${modelos.map(modelo => `
                        <div class="model-card" onclick="abrirCarpetaModelo(${modelo.id}, '${(modelo.nombre || '').replace(/'/g, "\\'")}', event)">
                            <div class="model-icon">
                                <i class="fas fa-laptop"></i>
                            </div>
                            <h3>${resaltarTexto(modelo.nombre || '', termino)}</h3>
                            <p>${resaltarTexto(modelo.tipo_equipo || '', termino)}</p>
                            <small>Marca: ${modelo.marca_nombre || 'N/A'}</small>
                            <div class="search-badge">Modelo</div>
                            <div class="model-actions">
                                <button class="btn-small btn-primary" onclick="cargarDocumentos(${modelo.id}, '${(modelo.nombre || '').replace(/'/g, "\\'")}')">
                                    <i class="fas fa-folder-open"></i>
                                </button>
                                <button class="btn-small btn-warning" onclick="editarModelo(${modelo.id})">
                                    <i class="fas fa-edit"></i>
                                </button>
                                <button class="btn-small btn-danger" onclick="eliminarModelo(${modelo.id}, '${(modelo.nombre || '').replace(/'/g, "\\'")}')">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </div>
                        </div>
                    `).join('')}
                </div>
            </div>
        `;
    }

    container.innerHTML = html;
}

function resaltarTexto(texto, termino) {
    if (!termino) return texto;
    const regex = new RegExp(`(${termino.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')})`, 'gi');
    return texto.replace(regex, '<mark class="search-highlight">$1</mark>');
}

function ocultarResultadosBusqueda() {
    document.getElementById('searchInput').value = '';
    currentSearchTerm = '';
    cargarMarcas();
}

// Funciones auxiliares
function obtenerIconoTipoDocumento(tipo) {
    const iconos = {
        'manual': 'fas fa-book',
        'lista_partes': 'fas fa-list',
        'tutorial': 'fas fa-video',
        'imagen': 'fas fa-image',
        'diagrama': 'fas fa-project-diagram',
        'esquema': 'fas fa-sitemap',
        'firmware': 'fas fa-microchip',
        'software': 'fas fa-desktop',
        'otro': 'fas fa-file'
    };
    return `<i class="${iconos[tipo] || 'fas fa-file'}"></i>`;
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

// Modal para agregar modelo
async function mostrarModalAgregarModelo() {
    console.log('Mostrando modal para agregar modelo');
    
    try {
        const response = await fetch('../backend/soporte_backend.php?action=get_marcas');
        
        if (!response.ok) {
            throw new Error(`HTTP error! status: ${response.status}`);
        }
        
        const data = await response.json();
        
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
            
            document.getElementById('addModelModal').style.display = 'block';
        } else {
            throw new Error(data.message || 'Error al cargar marcas');
        }
    } catch (error) {
        console.error('Error al cargar marcas para el modal:', error);
        alert('Error al cargar las marcas: ' + error.message);
    }
}

// Guardar nuevo modelo
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
        formData.append('manual_tipo', 'manual');
    }
    if (partes_file) {
        formData.append('partes_file', partes_file);
        formData.append('partes_tipo', 'lista_partes');
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

function cerrarModalAgregarModelo() {
    console.log('Cerrando modal de agregar modelo');
    document.getElementById('addModelModal').style.display = 'none';
    document.getElementById('modelForm').reset();
}

// Modal para subir documentos
async function mostrarModalSubirDocumentos(modeloId) {
    console.log('Mostrando modal para subir documentos para modelo:', modeloId);
    
    try {
        // Obtener información del modelo
        const response = await fetch(`../backend/soporte_backend.php?action=get_modelo_info&modelo_id=${modeloId}`);
        const data = await response.json();
        
        if (data.success) {
            const modelo = data.modelo;
            
            // Crear el contenido del modal
            const modalContent = `
                <div class="modal" id="uploadDocsModal" style="display: block;">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h3>Subir Documentos - ${modelo.nombre}</h3>
                            <span class="close-modal">&times;</span>
                        </div>
                        <div class="modal-body">
                            <form id="uploadDocsForm" enctype="multipart/form-data">
                                <input type="hidden" name="modelo_id" value="${modeloId}">
                                
                                <div class="form-group">
                                    <label for="manual_file">Manual de Usuario (opcional):</label>
                                    <input type="file" id="manual_file" name="manual_file" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png">
                                    <small>Formatos aceptados: PDF, Word, JPG, PNG</small>
                                </div>
                                
                                <div class="form-group">
                                    <label for="manual_descripcion">Descripción del Manual (opcional):</label>
                                    <textarea id="manual_descripcion" name="manual_descripcion" placeholder="Descripción del manual..."></textarea>
                                </div>
                                
                                <div class="form-separator"></div>
                                
                                <div class="form-group">
                                    <label for="partes_file">Lista de Partes (opcional):</label>
                                    <input type="file" id="partes_file" name="partes_file" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png">
                                    <small>Formatos aceptados: PDF, Word, JPG, PNG</small>
                                </div>
                                
                                <div class="form-group">
                                    <label for="partes_descripcion">Descripción de Lista de Partes (opcional):</label>
                                    <textarea id="partes_descripcion" name="partes_descripcion" placeholder="Descripción de la lista de partes..."></textarea>
                                </div>
                                
                                <div class="form-separator"></div>
                                
                                <div class="form-group">
                                    <label for="otros_documentos">Otros Documentos (opcional):</label>
                                    <input type="file" id="otros_documentos" name="otros_documentos[]" multiple accept=".pdf,.doc,.docx,.jpg,.jpeg,.png,.zip,.rar">
                                    <small>Puede seleccionar múltiples archivos. Formatos: PDF, Word, imágenes, ZIP, RAR</small>
                                </div>
                                
                                <div class="form-group">
                                    <label for="otros_descripcion">Descripción para Otros Documentos (opcional):</label>
                                    <textarea id="otros_descripcion" name="otros_descripcion" placeholder="Descripción general para los documentos adicionales..."></textarea>
                                </div>
                                
                                <div class="form-info">
                                    <p><i class="fas fa-info-circle"></i> Puede subir uno, varios o todos los tipos de documentos. No es obligatorio subir todos.</p>
                                </div>
                            </form>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn-secondary" onclick="cerrarModalSubirDocumentos()">Cancelar</button>
                            <button type="button" class="btn-primary" onclick="subirDocumentos(${modeloId})">
                                <i class="fas fa-upload"></i> Subir Documentos Seleccionados
                            </button>
                        </div>
                    </div>
                </div>
            `;
            
            // Agregar el modal al body si no existe
            let modal = document.getElementById('uploadDocsModal');
            if (!modal) {
                modal = document.createElement('div');
                modal.innerHTML = modalContent;
                document.body.appendChild(modal);
                
                // Agregar event listener para cerrar modal
                modal.querySelector('.close-modal').addEventListener('click', cerrarModalSubirDocumentos);
            } else {
                modal.style.display = 'block';
                modal.querySelector('h3').textContent = `Subir Documentos - ${modelo.nombre}`;
                modal.querySelector('input[name="modelo_id"]').value = modeloId;
            }
            
        } else {
            throw new Error(data.message || 'Error al cargar información del modelo');
        }
    } catch (error) {
        console.error('Error al mostrar modal de subir documentos:', error);
        alert('Error al cargar información del modelo: ' + error.message);
    }
}

// Función para subir documentos
async function subirDocumentos(modeloId) {
    console.log('Subiendo documentos para modelo:', modeloId);
    
    const form = document.getElementById('uploadDocsForm');
    const formData = new FormData(form);
    
    // Verificar que al menos un archivo fue seleccionado
    const manualFile = document.getElementById('manual_file').files[0];
    const partesFile = document.getElementById('partes_file').files[0];
    const otrosFiles = document.getElementById('otros_documentos').files;
    
    if (!manualFile && !partesFile && otrosFiles.length === 0) {
        alert('Por favor seleccione al menos un documento para subir.');
        return;
    }
    
    // Mostrar loading
    const submitBtn = document.querySelector('#uploadDocsModal .btn-primary');
    const originalText = submitBtn.innerHTML;
    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Subiendo...';
    submitBtn.disabled = true;
    
    try {
        console.log('Enviando documentos al servidor...');
        
        // CORRECCIÓN: Usar la acción correcta "upload_documentos" en lugar de "subir_documentos"
        const response = await fetch('../backend/soporte_backend.php?action=upload_documentos', {
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
            alert('✅ Documentos subidos correctamente');
            cerrarModalSubirDocumentos();
            
            // Recargar los documentos
            if (currentModeloId === modeloId) {
                const modeloNombre = document.getElementById('modelo-title').textContent.replace('Documentos - ', '');
                cargarDocumentos(modeloId, modeloNombre);
            }
        } else {
            throw new Error(data.message || 'Error desconocido al subir documentos');
        }
    } catch (error) {
        console.error('Error al subir documentos:', error);
        alert('❌ Error al subir los documentos: ' + error.message);
    } finally {
        // Restaurar botón
        submitBtn.innerHTML = originalText;
        submitBtn.disabled = false;
    }
}

// Cerrar modal de subir documentos
function cerrarModalSubirDocumentos() {
    const modal = document.getElementById('uploadDocsModal');
    if (modal) {
        modal.style.display = 'none';
        // Limpiar el formulario
        const form = document.getElementById('uploadDocsForm');
        if (form) {
            form.reset();
        }
    }
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

// Funciones para documentos
function verDocumento(ruta) {
    console.log('Viendo documento:', ruta);
    const modal = document.getElementById('previewModal');
    const pdfViewer = document.getElementById('pdfViewer');
    
    const url = `../backend/descargar_documento.php?archivo=${encodeURIComponent(ruta)}`;
    pdfViewer.src = url;
    modal.style.display = 'block';
}

function descargarDocumento(ruta, nombre) {
    console.log('Descargando documento:', nombre);
    const link = document.createElement('a');
    const url = `../backend/descargar_documento.php?archivo=${encodeURIComponent(ruta)}&download=1`;
    link.href = url;
    link.download = nombre;
    link.click();
}

// Cerrar modales al hacer click fuera
window.onclick = function(event) {
    const modals = document.querySelectorAll('.modal');
    modals.forEach(modal => {
        if (event.target === modal) {
            modal.style.display = 'none';
            if (modal.id === 'uploadDocsModal') {
                cerrarModalSubirDocumentos();
            }
        }
    });
}

// Funciones placeholder para futuras implementaciones
function mostrarModalEditarModelo(modelo) {
    alert('Funcionalidad de edición en desarrollo para el modelo: ' + modelo.nombre);
    // Aquí iría el código para mostrar un modal de edición
}

// Exportar funciones para debugging
window.soporteApp = {
    cargarMarcas,
    cargarModelos,
    cargarDocumentos,
    mostrarModalAgregarMarca,
    mostrarModalAgregarModelo,
    mostrarModalSubirDocumentos,
    subirDocumentos,
    editarModelo,
    eliminarModelo,
    eliminarMarca,
    eliminarDocumento,
    abrirCarpetaMarca,
    abrirCarpetaModelo
};