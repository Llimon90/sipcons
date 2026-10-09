pdfjsLib.GlobalWorkerOptions.workerSrc = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/2.16.105/pdf.worker.min.js';

// Funciones de utilidad
function getShortFileName(url, maxLength = 20) {
    const fileName = SipconsVisor.nombreDe(url);
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

        // Se lee como texto primero: si PHP falla sin devolver JSON (respuesta
        // vacía, HTML de error o redirección al login) se ve qué llegó.
        const texto = await response.text();
        let data;
        try {
            data = JSON.parse(texto);
        } catch (e) {
            console.error(`Respuesta no JSON (HTTP ${response.status}, ${response.url}):`, texto || '(vacía)');
            throw new Error(`El servidor no devolvió una respuesta válida (código ${response.status})`);
        }

        if (!response.ok || !data.success) {
            console.error('Error del servidor:', data);
            throw new Error(data.error || `Error al eliminar el archivo. Código: ${response.status}`);
        }

        const contenedorArchivos = containerElement.parentElement;
        containerElement.remove();
        if (contenedorArchivos && !contenedorArchivos.querySelector('.archivo-container')) {
            contenedorArchivos.innerHTML = '<p class="archivos-vacio">No hay archivos adjuntos.</p>';
        }
        showNotification('Archivo eliminado correctamente', 'success');

    } catch (error) {
        console.error("Error al eliminar archivo:", error);
        showNotification(error.message || 'Error desconocido al eliminar el archivo', 'error');
        containerElement.classList.remove('eliminando');
    }
}

// Miniatura de la primera página a tamaño de miniatura (antes se dibujaba a
// tamaño completo). El documento lo comparte el visor: abrirlo después no
// lo vuelve a descargar.
const ANCHO_MINIATURA_PX = 320;

async function renderPdfThumbnail(archivo, canvas) {
    try {
        const pdf = await SipconsVisor.cargarPdf(archivo);
        const page = await pdf.getPage(1);
        const base = page.getViewport({ scale: 1 });
        const viewport = page.getViewport({ scale: ANCHO_MINIATURA_PX / base.width });
        canvas.width = viewport.width;
        canvas.height = viewport.height;
        await page.render({ canvasContext: canvas.getContext('2d'), viewport }).promise;
    } catch (error) {
        console.error('Error al renderizar miniatura de PDF:', error);
        throw error;
    }
}

// Las miniaturas PDF se generan de una en una y solo cuando están a la vista;
// antes se esperaba cada PDF completo antes de mostrar el siguiente archivo.
let colaMiniaturas = Promise.resolve();
let observadorMiniaturas = null;

function programarMiniaturaPdf(archivo, canvas) {
    const generar = () => {
        colaMiniaturas = colaMiniaturas.then(() => renderPdfThumbnail(archivo, canvas))
            .catch(() => { if (canvas.isConnected) canvas.replaceWith(iconoArchivo(archivo)); });
    };
    if (!('IntersectionObserver' in window)) return generar();
    if (!observadorMiniaturas) {
        observadorMiniaturas = new IntersectionObserver((entradas) => {
            entradas.forEach(e => {
                if (!e.isIntersecting) return;
                observadorMiniaturas.unobserve(e.target);
                e.target._generarMiniatura();
            });
        }, { rootMargin: '600px 0px' });
    }
    canvas._generarMiniatura = generar;
    observadorMiniaturas.observe(canvas);
}

function createFilePreview(archivo) {
    const tipo = SipconsVisor.tipoDe(archivo);
    let previewElement;

    if (tipo === 'imagen') {
        previewElement = document.createElement('img');
        previewElement.src = archivo;
        previewElement.alt = '';
        previewElement.loading = 'lazy';
        previewElement.onerror = () => previewElement.replaceWith(iconoArchivo(archivo));
    } else if (tipo === 'pdf') {
        previewElement = document.createElement('canvas');
    } else if (tipo === 'video') {
        previewElement = document.createElement('video');
        previewElement.src = `${archivo}#t=0.1`;
        previewElement.preload = 'metadata';
        previewElement.muted = true;
        previewElement.loop = true;
        previewElement.playsInline = true;
        previewElement.onmouseover = () => previewElement.play().catch(() => {});
        previewElement.onmouseout = () => previewElement.pause();
    }

    return previewElement;
}

function iconoArchivo(archivo) {
    const icono = document.createElement('i');
    icono.className = `fas ${SipconsVisor.iconoDe(archivo)} archivo-icono`;
    return icono;
}

// Abre en el visor el archivo pulsado, con los demás archivos que sigan en pantalla
function abrirEnVisor(archivoContainer) {
    const contenedores = Array.from(document.querySelectorAll('#contenedor-archivos .archivo-container'));
    const lista = contenedores.map(c => ({ url: c.dataset.url }));
    SipconsVisor.abrir(lista, contenedores.indexOf(archivoContainer));
}

function createFileContainer(archivo, id) {
    const archivoContainer = document.createElement('div');
    archivoContainer.className = 'archivo-container';
    archivoContainer.dataset.url = archivo;

    const nombre = SipconsVisor.nombreDe(archivo);

    // El enlace conserva la URL: Ctrl/Cmd + clic o clic central abren en pestaña nueva
    const link = document.createElement('a');
    link.className = 'archivo-enlace';
    link.href = archivo;
    link.target = '_blank';
    link.rel = 'noopener';
    link.title = `Ver ${nombre}`;
    link.addEventListener('click', (e) => {
        if (e.ctrlKey || e.metaKey || e.shiftKey || e.button !== 0) return;
        e.preventDefault();
        abrirEnVisor(archivoContainer);
    });

    const miniatura = document.createElement('div');
    miniatura.className = 'archivo-miniatura';
    const previewElement = createFilePreview(archivo);
    miniatura.appendChild(previewElement || iconoArchivo(archivo));
    link.appendChild(miniatura);

    const fileNameSpan = document.createElement('span');
    fileNameSpan.className = 'file-name';
    fileNameSpan.textContent = getShortFileName(archivo, 28);
    link.appendChild(fileNameSpan);

    const deleteBtn = document.createElement('button');
    deleteBtn.type = 'button';
    deleteBtn.className = 'eliminar-archivo';
    deleteBtn.title = 'Eliminar archivo';
    deleteBtn.setAttribute('aria-label', `Eliminar ${nombre}`);
    deleteBtn.innerHTML = '<i class="fas fa-times"></i>';
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
        if (archivos.some(a => SipconsVisor.tipoDe(a) === 'pdf')) SipconsVisor.precalentarPdf();
        for (const archivo of archivos) {
            const { container, preview } = createFileContainer(archivo, id);
            contenedorArchivos.appendChild(container);
            if (preview && preview.tagName === 'CANVAS') programarMiniaturaPdf(archivo, preview);
        }
    } else {
        contenedorArchivos.innerHTML = '<p class="archivos-vacio">No hay archivos adjuntos.</p>';
    }
}

function escapeAttr(valor) {
    return String(valor ?? '')
        .replace(/&/g, '&amp;')
        .replace(/"/g, '&quot;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;');
}

// Roles que pueden eliminar incidencias (el backend vuelve a validarlo)
const ROLES_ELIMINAR_INCIDENCIA = ['Administrador', 'Técnico/Administrador', 'Programador'];

// Cliente (mayúsculas) -> contactos, para los desplegables de cliente y "reporta"
let contactosPorCliente = {};
let clientesValidos = [];

async function configurarClienteYContacto(clienteOriginal) {
    const inputCliente = document.getElementById('cliente');
    const inputContacto = document.getElementById('contacto');
    const datalistClientes = document.getElementById('lista-clientes');
    const datalistContactos = document.getElementById('lista-contactos');

    function actualizarContactos() {
        const clave = inputCliente.value.trim().toUpperCase();
        datalistContactos.innerHTML = '';
        (contactosPorCliente[clave] || []).forEach(nombre => {
            const option = document.createElement('option');
            option.value = nombre;
            datalistContactos.appendChild(option);
        });
    }

    // Un cliente distinto al original debe existir en el catálogo. El original
    // se respeta aunque ya no exista (incidencias antiguas), para no bloquear el guardado.
    function validarCliente() {
        const valor = inputCliente.value.trim().toUpperCase();
        const permitido = !valor
            || valor === String(clienteOriginal || '').trim().toUpperCase()
            || clientesValidos.includes(valor);
        inputCliente.setCustomValidity(permitido ? '' : 'El cliente escrito no existe. Selecciona un cliente válido de la lista.');
    }

    inputCliente.addEventListener('input', () => { validarCliente(); actualizarContactos(); });
    // Al cambiar de cliente, un contacto que no pertenece al nuevo cliente se
    // limpia para que no se guarde uno ajeno (el campo es obligatorio).
    let clienteAnterior = String(clienteOriginal || '').trim().toUpperCase();
    inputCliente.addEventListener('change', () => {
        actualizarContactos();
        const actual = inputCliente.value.trim().toUpperCase();
        if (actual === clienteAnterior) return;
        clienteAnterior = actual;

        const lista = contactosPorCliente[actual];
        const contacto = inputContacto.value.trim().toLowerCase();
        if (lista && contacto && !lista.some(n => n.toLowerCase() === contacto)) {
            inputContacto.value = '';
            showNotification('El contacto no pertenece al nuevo cliente; selecciona quién reporta.', 'error');
            inputContacto.focus();
        }
    });

    SipconsSerie.vincular(inputCliente, document.getElementById('lista-series'));

    try {
        const response = await fetch(`../backend/obtener-clientes.php?t=${Date.now()}`, { cache: 'no-store' });
        const clientes = await response.json();
        if (!Array.isArray(clientes)) return;

        clientes.forEach(cliente => {
            const option = document.createElement('option');
            option.value = cliente.nombre;
            datalistClientes.appendChild(option);

            const clave = cliente.nombre.trim().toUpperCase();
            clientesValidos.push(clave);
            contactosPorCliente[clave] = SipconsContactos.parsear(cliente.contactos);
        });

        validarCliente();
        actualizarContactos();
    } catch (error) {
        console.error('Error al cargar clientes:', error);
    }
}

async function eliminarIncidencia(id, folio) {
    if (!confirm(`¿Eliminar definitivamente la incidencia ${folio || id}?\n\nSe borrará de la base de datos. El movimiento quedará registrado en el historial.`)) {
        return;
    }

    try {
        const response = await fetch('../backend/eliminar-incidencia.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ id })
        });
        const result = await response.json();

        if (!response.ok || !result.success) {
            throw new Error(result.error || 'No se pudo eliminar la incidencia');
        }

        estadoDetalle.salirSinAviso = true;
        borrarBorrador();
        showNotification('Incidencia eliminada correctamente');
        setTimeout(() => { window.location.href = 'incidencias.html'; }, 1200);
    } catch (error) {
        console.error('Error al eliminar incidencia:', error);
        showNotification(error.message, 'error');
    }
}

// Funciones relacionadas con el formulario
function tecnicoGroupHTML(opcionesHTML, requerido) {
    return `
        <div class="tecnico-group">
            <select name="tecnicos[]" class="tecnico-select" ${requerido ? 'required' : ''}>
                ${opcionesHTML}
            </select>
            <button type="button" class="eliminar-tecnico" title="Quitar técnico" aria-label="Quitar técnico">
                <i class="fas fa-trash-alt"></i>
            </button>
        </div>`;
}

function createFormHTML(data, tecnicosLista) {
    // Convertir técnico existente en array si no lo es
    const tecnicosIniciales = Array.isArray(data.tecnico) ? data.tecnico : 
                            (data.tecnico ? [data.tecnico] : []);

    return `
        <form id="form-editar">
            <p class="detalle-folio"><strong># REPORTE INTERNO:</strong> ${data.numero_incidente}</p>
            <div class="form-row">
                <div>
                    <label for="reporte_cliente"># INCIDENCIA CLIENTE:</label>
                    <input type="text" id="reporte_cliente" value="${escapeAttr(data.reporte_cliente)}">
                </div>
                <div>
                    <label for="cliente">CLIENTE:</label>
                    <input type="text" id="cliente" list="lista-clientes" autocomplete="off" placeholder="Escribe para buscar cliente..." value="${escapeAttr(data.cliente)}" required>
                    <datalist id="lista-clientes"></datalist>
                </div>
            </div>

            <div class="form-row">
                <div>
                    <label for="contacto">CONTACTO:</label>
                    <input type="text" id="contacto" list="lista-contactos" autocomplete="off" placeholder="Selecciona o escribe quién reporta" value="${escapeAttr(data.contacto)}" required>
                    <datalist id="lista-contactos"></datalist>
                </div>
                <div>
                    <label for="sucursal">SUCURSAL:</label>
                    <input type="text" id="sucursal" value="${escapeAttr(data.sucursal)}">
                </div>
            </div>

            <div class="form-row">
                <div>
                    <label for="categoria">CATEGORÍA:</label>
                    <select id="categoria">
                        <option value="">SELECCIONE UNA OPCIÓN</option>
                        <option value="Mr. Tienda/Mr. Chef" ${data.categoria && data.categoria.trim() === 'Mr. Tienda/Mr. Chef' ? 'selected' : ''}>Mr. Tienda/Mr. Chef</option>
                        <option value="Distribuidora el Florido" ${data.categoria && data.categoria.trim() === 'Distribuidora el Florido' ? 'selected' : ''}>Distribuidora el Florido</option>
                        <option value="Calimax" ${data.categoria && data.categoria.trim() === 'Calimax' ? 'selected' : ''}>Calimax</option>
                        <option value="Recolección" ${data.categoria && data.categoria.trim() === 'Recolección' ? 'selected' : ''}>Recolección</option>
                        <option value="Otros" ${data.categoria && data.categoria.trim() === 'Otros' ? 'selected' : ''}>Otros</option>
                    </select>
                </div>
                <div>
                    <label for="fecha">FECHA:</label>
                    <input type="date" id="fecha" value="${data.fecha || ''}" required>
                </div>
            </div>

            <div class="form-campo">
                <label for="numero_serie">NÚMERO DE SERIE DEL EQUIPO (opcional):</label>
                <input type="text" id="numero_serie" list="lista-series" autocomplete="off" maxlength="100" placeholder="Selecciona del padrón o escribe la serie" value="${escapeAttr(data.numero_serie)}">
                <datalist id="lista-series"></datalist>
            </div>

            <div class="form-campo">
                <label>TÉCNICOS:</label>
                <div id="tecnicos-container">
                    ${tecnicosIniciales.map((tecnico, index) => tecnicoGroupHTML(
                        SipconsTecnicos.opcionesHTML(tecnicosLista, tecnico, "Sin técnico asignado"), index > 0
                    )).join('')}
                    ${tecnicosIniciales.length === 0 ? tecnicoGroupHTML(
                        SipconsTecnicos.opcionesHTML(tecnicosLista, '', "Seleccione una opción"), false
                    ) : ''}
                </div>
                <button type="button" id="agregar-tecnico" class="btn-agregar-tecnico">
                    <i class="fas fa-plus"></i> Agregar técnico
                </button>
            </div>

            <div class="form-campo">
                <label for="estatus">ESTATUS:</label>
                <select id="estatus">
                    <option value="Abierto" ${data.estatus === "Abierto" ? 'selected' : ''}>Abierto</option>
                    <option value="Asignado" ${data.estatus === "Asignado" ? 'selected' : ''}>Asignado</option>
                    <option value="Pendiente" ${data.estatus === "Pendiente" ? 'selected' : ''}>Pendiente</option>
                    <option value="Completado" ${data.estatus === "Completado" ? 'selected' : ''}>Completado</option>
                    <option value="Cerrado sin factura" ${data.estatus === "Cerrado sin factura" ? 'selected' : ''}>Cerrado sin factura</option>
                    <option value="Cerrado con factura" ${data.estatus === "Cerrado con factura" ? 'selected' : ''}>Cerrado con factura</option>
                </select>
            </div>

            <div class="form-row">
                <div>
                    <label for="falla">FALLA:</label>
                    <textarea id="falla" required>${data.falla || ''}</textarea>
                </div>
                <div>
                    <label for="accion">TRABAJO REALIZADO:</label>
                    <textarea id="accion">${data.accion || ''}</textarea>
                </div>
            </div>

            <div class="form-campo">
                <label for="notas">NOTAS ADICIONALES</label>
                <textarea id="notas">${data.notas || ''}</textarea>
            </div>

            <div class="form-campo">
                <label for="archivos">AGREGAR NUEVOS ARCHIVOS:</label>
                <input type="file" id="archivos" name="archivos[]" multiple
                       accept=".pdf,.jpg,.jpeg,.png,.gif,.webp,.mp4,.webm,.ogg,.mov,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.txt,.zip,.rar">
            </div>

            <div class="detalle-acciones">
                <button type="submit">
                    <i class="fas fa-save"></i> Guardar cambios
                </button>
                <button type="button" id="btn-eliminar-incidencia" class="btn-rojo" style="display: none;">
                    <i class="fas fa-trash-alt"></i> Eliminar incidencia
                </button>
            </div>
        </form>

    `;
}

function setupTecnicosMultiples(tecnicosLista) {
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
        const plantilla = document.createElement('template');
        plantilla.innerHTML = tecnicoGroupHTML(
            SipconsTecnicos.opcionesHTML(tecnicosLista, '', 'Seleccione una opción'), true
        ).trim();
        const tecnicoGroup = plantilla.content.firstElementChild;
        const select = tecnicoGroup.querySelector('select');
        const deleteBtn = tecnicoGroup.querySelector('.eliminar-tecnico');

        deleteBtn.addEventListener('click', function() {
            tecnicoGroup.remove();
            actualizarOpcionesTecnicos();
        });

        select.addEventListener('change', actualizarOpcionesTecnicos);

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

// =====================================================================
// GUARDADO CONFIABLE
// - El resultado se muestra en un modal (dialogo.js) que no desaparece solo,
//   y el éxito solo se anuncia cuando el servidor confirma que la base de
//   datos quedó con lo enviado (ver backend/actualiza.php).
// - Lo que se escribe se respalda en el navegador (borrador local) hasta que
//   se guarda; si la página se cierra o el guardado falla, al volver se
//   ofrece recuperarlo.
// - "version" detecta si la incidencia cambió en otro lugar (otra persona, u
//   otra pestaña/equipo del mismo usuario) para no pisar esos cambios.
// =====================================================================

const CAMPOS_FORM = ['reporte_cliente', 'cliente', 'contacto', 'sucursal', 'categoria', 'fecha',
    'numero_serie', 'estatus', 'falla', 'accion', 'notas'];

const NOMBRES_CAMPO = {
    reporte_cliente: '# Incidencia cliente', cliente: 'Cliente', contacto: 'Contacto',
    sucursal: 'Sucursal', categoria: 'Categoría', fecha: 'Fecha', numero_serie: 'Número de serie',
    estatus: 'Estatus', falla: 'Falla', accion: 'Trabajo realizado', notas: 'Notas', tecnicos: 'Técnicos'
};

// No se ofrece un borrador más viejo que esto
const BORRADOR_DIAS = 30;

// Motivos de error en los que reintentar igual no sirve (hay que cambiar algo antes)
const MOTIVOS_SIN_REINTENTO = ['envio_muy_grande', 'archivo_muy_grande', 'faltan_datos', 'sin_permiso', 'no_existe', 'id_invalido'];

const estadoDetalle = {
    id: null,
    folio: '',
    version: '',
    datosServidor: null, // la incidencia tal como llegó del servidor
    original: null,      // el formulario recién cargado (para saber si hay cambios)
    limites: null,
    selector: null,      // selector de archivos nuevos (selector-archivos.js)
    idPagina: Math.random().toString(36).slice(2, 10), // identifica el borrador escrito por esta página
    borradorPendiente: false, // hay un borrador anterior sin recuperar ni descartar
    guardando: false,
    salirSinAviso: false
};

function leerFormulario() {
    const valores = {};
    CAMPOS_FORM.forEach(campo => {
        const el = document.getElementById(campo);
        valores[campo] = el ? el.value : '';
    });
    valores.numero_serie = valores.numero_serie.trim();
    valores.tecnicos = Array.from(document.querySelectorAll('.tecnico-select'))
        .map(select => select.value)
        .filter(Boolean);
    return valores;
}

// Los mismos campos que leerFormulario(), pero tomados de la respuesta del servidor
function valoresDeDatos(datos) {
    const valores = {};
    CAMPOS_FORM.forEach(campo => { valores[campo] = datos[campo] == null ? '' : String(datos[campo]); });
    valores.fecha = valores.fecha.substring(0, 10);
    valores.numero_serie = valores.numero_serie.trim();
    valores.tecnicos = (Array.isArray(datos.tecnico) ? datos.tecnico : []).filter(Boolean);
    return valores;
}

function mismosValores(a, b) {
    return JSON.stringify(a) === JSON.stringify(b);
}

function camposDistintos(a, b) {
    return Object.keys(NOMBRES_CAMPO).filter(campo => JSON.stringify(a[campo]) !== JSON.stringify(b[campo]));
}

function archivosSeleccionados() {
    const input = document.getElementById('archivos');
    return input ? Array.from(input.files) : [];
}

function hayCambiosSinGuardar() {
    if (!estadoDetalle.original) return false;
    return !mismosValores(leerFormulario(), estadoDetalle.original) || archivosSeleccionados().length > 0;
}

function formatearFechaHora(valor) {
    const fecha = valor instanceof Date ? valor : new Date(String(valor).replace(' ', 'T'));
    if (isNaN(fecha)) return '';
    const dos = n => String(n).padStart(2, '0');
    return `${dos(fecha.getDate())}/${dos(fecha.getMonth() + 1)}/${fecha.getFullYear()} a las ${dos(fecha.getHours())}:${dos(fecha.getMinutes())}`;
}

// ---------- borrador local ----------

function claveBorrador() {
    return `sipcons_borrador_incidencia_${estadoDetalle.id}`;
}

function leerBorrador() {
    try {
        const borrador = JSON.parse(localStorage.getItem(claveBorrador()) || 'null');
        if (!borrador || !borrador.valores) return null;
        if (Date.now() - borrador.fecha > BORRADOR_DIAS * 86400000) {
            borrarBorrador();
            return null;
        }
        return borrador;
    } catch (e) {
        return null;
    }
}

// Reglas para no perder un borrador ajeno:
//  - esta página solo borra el borrador que ella misma escribió (otra pestaña
//    con la misma incidencia puede tener el suyo);
//  - mientras hay un borrador anterior sin recuperar ni descartar, no se
//    sobrescribe.
function guardarBorrador() {
    // Ya se guardó (o se va a recargar a propósito): no hay nada que respaldar
    if (!estadoDetalle.original || estadoDetalle.salirSinAviso) return;
    const valores = leerFormulario();
    try {
        const existente = JSON.parse(localStorage.getItem(claveBorrador()) || 'null');
        const esMio = existente && existente.pagina === estadoDetalle.idPagina;
        if (mismosValores(valores, estadoDetalle.original)) {
            if (esMio) localStorage.removeItem(claveBorrador());
        } else if (!estadoDetalle.borradorPendiente) {
            localStorage.setItem(claveBorrador(), JSON.stringify({
                pagina: estadoDetalle.idPagina,
                valores,
                fecha: Date.now(),
                version: estadoDetalle.version,
                // Contra qué se escribió: permite integrar si la incidencia cambia después
                base: estadoDetalle.original,
                baseServidor: valoresDeDatos(estadoDetalle.datosServidor),
                archivos: archivosSeleccionados().length
            }));
        }
    } catch (e) { /* sin almacenamiento disponible: solo se pierde el respaldo */ }
}

// soloPropio: borra únicamente si lo escribió esta página (no el de otra pestaña)
function borrarBorrador(soloPropio = false) {
    try {
        if (soloPropio) {
            const existente = JSON.parse(localStorage.getItem(claveBorrador()) || 'null');
            if (existente && existente.pagina !== estadoDetalle.idPagina) return;
        }
        localStorage.removeItem(claveBorrador());
    } catch (e) { /* nada */ }
}

let temporizadorBorrador = null;
function programarBorrador() {
    clearTimeout(temporizadorBorrador);
    temporizadorBorrador = setTimeout(guardarBorrador, 400);
}

function aplicarValores(valores) {
    CAMPOS_FORM.forEach(campo => {
        const el = document.getElementById(campo);
        if (el && valores[campo] !== undefined) el.value = valores[campo];
    });
    // Actualiza la lista de contactos del cliente recuperado
    const cliente = document.getElementById('cliente');
    if (cliente) cliente.dispatchEvent(new Event('input', { bubbles: true }));

    const contenedor = document.getElementById('tecnicos-container');
    const agregar = document.getElementById('agregar-tecnico');
    const deseados = valores.tecnicos && valores.tecnicos.length ? valores.tecnicos : [''];
    let grupos = contenedor.querySelectorAll('.tecnico-group');
    for (let intentos = 0; grupos.length < deseados.length && intentos < 20; intentos++) {
        agregar.click();
        grupos = contenedor.querySelectorAll('.tecnico-group');
    }
    Array.from(grupos).slice(deseados.length).forEach(grupo => grupo.remove());
    const selects = contenedor.querySelectorAll('.tecnico-select');
    selects.forEach((select, i) => { select.value = deseados[i] || ''; });
    selects.forEach(select => select.dispatchEvent(new Event('change', { bubbles: true })));
}

function mostrarAvisoBorrador(borrador) {
    const form = document.getElementById('form-editar');
    const aviso = document.createElement('div');
    aviso.className = 'aviso-borrador';
    aviso.setAttribute('role', 'status');

    // Si la incidencia cambió después de escribir el borrador (y se sabe contra
    // qué se escribió), se ofrece integrar en vez de reemplazar.
    const cambioDespues = borrador.version && borrador.version !== estadoDetalle.version;
    const puedeIntegrar = cambioDespues && borrador.base && borrador.baseServidor;

    const notas = [`Los escribiste el ${formatearFechaHora(new Date(borrador.fecha))}.`];
    if (cambioDespues) {
        notas.push(puedeIntegrar
            ? 'Después de eso se guardaron otros cambios en esta incidencia; puedes integrarlos con los tuyos.'
            : 'La incidencia cambió desde entonces: revisa los datos antes de guardar.');
    }
    if (borrador.archivos > 0) {
        notas.push('Los archivos que habías elegido tendrás que seleccionarlos otra vez.');
    }

    aviso.innerHTML = `
        <i class="fas fa-history" aria-hidden="true"></i>
        <div class="aviso-borrador-texto"><strong>Tienes cambios que no se guardaron</strong><span></span></div>
        <div class="aviso-borrador-acciones">
            <button type="button" class="btn-recuperar"></button>
            <button type="button" class="btn-descartar">Descartar</button>
        </div>`;
    aviso.querySelector('.aviso-borrador-texto span').textContent = notas.join(' ');
    aviso.querySelector('.btn-recuperar').textContent = puedeIntegrar ? 'Integrar mis cambios' : 'Recuperar mis cambios';

    aviso.querySelector('.btn-recuperar').addEventListener('click', async () => {
        if (puedeIntegrar) {
            const hecho = await integrarVersiones({
                mios: borrador.valores,
                original: borrador.base,
                baseServidor: borrador.baseServidor,
                actual: estadoDetalle.datosServidor,
                quien: 'la versión guardada'
            });
            if (hecho) {
                estadoDetalle.borradorPendiente = false;
                aviso.remove();
                guardarBorrador();
            }
        } else {
            estadoDetalle.borradorPendiente = false;
            aplicarValores(borrador.valores);
            aviso.remove();
            guardarBorrador();
            showNotification('Se recuperaron tus cambios. Revísalos y presiona "Guardar cambios".');
        }
    });
    aviso.querySelector('.btn-descartar').addEventListener('click', () => {
        estadoDetalle.borradorPendiente = false;
        borrarBorrador();
        aviso.remove();
    });

    estadoDetalle.borradorPendiente = true;
    form.prepend(aviso);
}

// ---------- integrar dos versiones ----------
//
// Compara campo por campo contra lo que había cuando se empezó a editar:
//  - solo cambió quien edita aquí  -> se queda su valor
//  - solo cambió la otra versión   -> se toma el de la otra versión
//  - cambiaron ambos y son iguales -> no hay nada que decidir
//  - cambiaron ambos y difieren    -> el usuario elige (o combina textos/técnicos)

const CAMPOS_COMBINABLES = ['falla', 'accion', 'notas', 'tecnicos'];

function combinarTextos(suyo, mio) {
    const a = (suyo || '').trim();
    const b = (mio || '').trim();
    if (!a) return mio;
    if (!b) return suyo;
    if (b.includes(a)) return mio;
    if (a.includes(b)) return suyo;
    return `${a}\n\n${b}`;
}

function combinarValor(campo, suyo, mio) {
    if (campo === 'tecnicos') return Array.from(new Set([...(suyo || []), ...(mio || [])]));
    return combinarTextos(suyo, mio);
}

function textoDeValor(campo, valor) {
    if (campo === 'tecnicos') return (valor || []).join(', ');
    return String(valor ?? '');
}

function compararVersiones(mios, original, baseServidor, suyos) {
    const resultado = {};
    const deLaOtra = [];
    const conflictos = [];
    const igual = (a, b) => JSON.stringify(a ?? '') === JSON.stringify(b ?? '');

    Object.keys(NOMBRES_CAMPO).forEach(campo => {
        const cambieYo = !igual(mios[campo], original[campo]);
        const cambioOtra = !igual(suyos[campo], baseServidor[campo]);

        if (!cambioOtra || igual(mios[campo], suyos[campo])) {
            resultado[campo] = mios[campo];
        } else if (!cambieYo) {
            resultado[campo] = suyos[campo];
            deLaOtra.push(campo);
        } else {
            resultado[campo] = mios[campo];
            conflictos.push(campo);
        }
    });
    return { resultado, deLaOtra, conflictos };
}

// Pregunta qué conservar en los campos que cambiaron ambas versiones.
// Devuelve { campo: 'mio' | 'suyo' | 'ambos' } o null si se canceló.
async function elegirEnConflictos(conflictos, mios, suyos, quien) {
    const contenido = document.createElement('div');

    conflictos.forEach(campo => {
        const grupo = document.createElement('fieldset');
        grupo.className = 'integrar-campo';
        const leyenda = document.createElement('legend');
        leyenda.textContent = NOMBRES_CAMPO[campo];
        grupo.appendChild(leyenda);

        const opciones = [
            { valor: 'mio', titulo: 'Lo tuyo', texto: textoDeValor(campo, mios[campo]) },
            { valor: 'suyo', titulo: `Lo de ${quien}`, texto: textoDeValor(campo, suyos[campo]) }
        ];
        if (CAMPOS_COMBINABLES.includes(campo)) {
            opciones.push({ valor: 'ambos', titulo: 'Conservar ambos', texto: textoDeValor(campo, combinarValor(campo, suyos[campo], mios[campo])) });
        }
        // Por defecto no se pierde nada: combinar cuando se puede; si no, lo propio
        const porDefecto = CAMPOS_COMBINABLES.includes(campo) ? 'ambos' : 'mio';

        opciones.forEach(op => {
            const etiqueta = document.createElement('label');
            etiqueta.className = 'integrar-opcion';
            const radio = document.createElement('input');
            radio.type = 'radio';
            radio.name = `integrar-${campo}`;
            radio.value = op.valor;
            radio.checked = op.valor === porDefecto;
            const cuerpo = document.createElement('span');
            const titulo = document.createElement('strong');
            titulo.textContent = op.titulo;
            const texto = document.createElement('span');
            texto.className = 'integrar-texto' + (op.texto.trim() ? '' : ' vacio');
            texto.textContent = op.texto.trim() ? op.texto : '(vacío)';
            cuerpo.append(titulo, texto);
            etiqueta.append(radio, cuerpo);
            grupo.appendChild(etiqueta);
        });
        contenido.appendChild(grupo);
    });

    const eleccion = await SipconsDialogo.mostrar({
        tipo: 'info',
        ancho: true,
        titulo: conflictos.length === 1 ? 'Elige qué conservar en este campo' : 'Elige qué conservar en estos campos',
        mensaje: `Tú y ${quien} cambiaron lo mismo. El resto de los cambios de ambos ya se integró.`,
        contenido,
        botones: [
            { texto: 'Cancelar', valor: null, estilo: 'secundario' },
            { texto: 'Aplicar', valor: 'aplicar', estilo: 'primario' }
        ]
    });
    if (eleccion !== 'aplicar') return null;

    const decisiones = {};
    conflictos.forEach(campo => {
        const marcado = contenido.querySelector(`input[name="integrar-${campo}"]:checked`);
        decisiones[campo] = marcado ? marcado.value : 'mio';
    });
    return decisiones;
}

function elementoDeCampo(campo) {
    return campo === 'tecnicos' ? document.getElementById('tecnicos-container') : document.getElementById(campo);
}

function resaltarCampos(campos) {
    document.querySelectorAll('.campo-integrado').forEach(el => el.classList.remove('campo-integrado'));
    campos.forEach(campo => {
        const el = elementoDeCampo(campo);
        if (!el) return;
        el.classList.add('campo-integrado');
        const quitar = () => el.classList.remove('campo-integrado');
        el.addEventListener('input', quitar, { once: true });
        el.addEventListener('change', quitar, { once: true });
    });
    const primero = campos.map(elementoDeCampo).find(Boolean);
    if (primero) primero.scrollIntoView({ behavior: 'smooth', block: 'center' });
}

function mostrarAvisoIntegrado(cantidad) {
    const form = document.getElementById('form-editar');
    form.querySelectorAll('.aviso-borrador').forEach(a => a.remove());

    const aviso = document.createElement('div');
    aviso.className = 'aviso-borrador aviso-borrador--info';
    aviso.setAttribute('role', 'status');
    aviso.innerHTML = `
        <i class="fas fa-layer-group" aria-hidden="true"></i>
        <div class="aviso-borrador-texto"><strong>Se integraron ambas versiones</strong><span></span></div>
        <div class="aviso-borrador-acciones">
            <button type="button" class="btn-guardar-integrado">Guardar cambios</button>
        </div>`;
    aviso.querySelector('.aviso-borrador-texto span').textContent = cantidad
        ? 'Los campos marcados traen cambios de la otra versión. Revisa y guarda para terminar; todavía no se ha guardado.'
        : 'Tus cambios ya incluyen los de la otra versión. Guarda para terminar; todavía no se ha guardado.';
    aviso.querySelector('.btn-guardar-integrado').addEventListener('click', () => {
        aviso.remove();
        handleFormSubmit(null);
    });
    form.prepend(aviso);
}

/**
 * Junta en el formulario los cambios propios con los de la versión `actual`
 * (la que está guardada ahora). No guarda: deja todo listo para revisar.
 * Devuelve true si se integró, false si el usuario canceló.
 */
async function integrarVersiones({ mios, original, baseServidor, actual, quien }) {
    const suyos = valoresDeDatos(actual);
    const { resultado, deLaOtra, conflictos } = compararVersiones(mios, original, baseServidor, suyos);

    if (conflictos.length) {
        const decisiones = await elegirEnConflictos(conflictos, mios, suyos, quien);
        if (!decisiones) return false;
        conflictos.forEach(campo => {
            if (decisiones[campo] === 'suyo') {
                resultado[campo] = suyos[campo];
                deLaOtra.push(campo);
            } else if (decisiones[campo] === 'ambos') {
                resultado[campo] = combinarValor(campo, suyos[campo], mios[campo]);
                deLaOtra.push(campo);
            }
        });
    }

    // A partir de aquí se edita sobre la versión actual: el próximo guardado ya no choca
    const archivosCambiaron = JSON.stringify(actual.archivos || []) !== JSON.stringify(estadoDetalle.datosServidor.archivos || []);
    estadoDetalle.version = actual.version || estadoDetalle.version;
    estadoDetalle.datosServidor = actual;
    estadoDetalle.original = valoresDeDatos(actual);
    if (archivosCambiaron) cargarArchivosAdjuntos(actual.archivos || [], estadoDetalle.id);

    aplicarValores(resultado);
    guardarBorrador();
    resaltarCampos(deLaOtra);
    mostrarAvisoIntegrado(deLaOtra.length);
    return true;
}

// ---------- envío ----------

function marcarGuardando(activo) {
    estadoDetalle.guardando = activo;
    const boton = document.querySelector('#form-editar button[type="submit"]');
    if (!boton) return;
    if (activo) {
        boton.dataset.textoOriginal = boton.innerHTML;
        boton.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Guardando…';
        boton.classList.add('guardando');
        boton.disabled = true;
    } else {
        if (boton.dataset.textoOriginal) boton.innerHTML = boton.dataset.textoOriginal;
        boton.classList.remove('guardando');
        boton.disabled = false;
    }
}

function textoBotonGuardar(texto) {
    const boton = document.querySelector('#form-editar button[type="submit"]');
    if (boton && estadoDetalle.guardando) boton.innerHTML = `<i class="fas fa-spinner fa-spin"></i> ${texto}`;
}

function registrarGuardadoEnAnaliticas(correcto, motivo, ms, estado) {
    try {
        if (!window.sipconsTrack) return;
        window.sipconsTrack('guardado',
            correcto ? 'Guardado correcto' : `Guardado fallido: ${motivo}`,
            `${correcto ? 'ok' : 'error'} · ${estadoDetalle.folio || estadoDetalle.id}`,
            ms, estado);
        if (window.sipconsFlush) window.sipconsFlush();
    } catch (e) { /* las analíticas nunca deben estorbar */ }
}

// Revisa los archivos antes de enviar (peso, tipo, cantidad), para avisar al momento
function revisarArchivosAntesDeEnviar() {
    return estadoDetalle.selector ? estadoDetalle.selector.revisar() : null;
}

async function handleFormSubmit(e, forzar = false) {
    if (e) e.preventDefault();
    if (estadoDetalle.guardando) return;

    const form = document.getElementById('form-editar');
    if (!form.reportValidity()) return;

    const problemaArchivos = revisarArchivosAntesDeEnviar();
    if (problemaArchivos) {
        await SipconsDialogo.mostrar({
            tipo: 'aviso',
            titulo: 'Revisa los archivos',
            mensaje: problemaArchivos.mensaje,
            lista: problemaArchivos.lista,
            nota: 'No se guardó nada todavía. Lo que escribiste sigue en pantalla.',
            botones: [{ texto: 'Entendido', valor: 'ok', estilo: 'primario' }]
        });
        return;
    }

    const valores = leerFormulario();
    const formData = new FormData();
    formData.append('id', estadoDetalle.id);
    formData.append('version', estadoDetalle.version);
    if (forzar) formData.append('forzar', '1');
    CAMPOS_FORM.forEach(campo => formData.append(campo, valores[campo]));
    formData.append('tecnico', valores.tecnicos.join('/'));

    guardarBorrador();
    marcarGuardando(true);
    const inicio = performance.now();

    // Fotos reducidas antes de subir y avance visible en el botón
    const elegidos = archivosSeleccionados();
    if (elegidos.length) {
        textoBotonGuardar('Preparando archivos…');
        const listos = await SipconsArchivos.prepararParaEnvio(elegidos);
        listos.forEach(archivo => formData.append('archivos[]', archivo));
    }

    const envio = await SipconsArchivos.enviarConAvance('../backend/actualiza.php', formData, (porcentaje) => {
        textoBotonGuardar(porcentaje < 100 && elegidos.length ? `Subiendo… ${porcentaje}%` : 'Guardando…');
    });
    marcarGuardando(false);

    const datos = envio.datos;
    const sinConexion = envio.sinConexion;
    const sesionCerrada = envio.sesionCerrada;
    const estado = envio.estado;
    const ms = performance.now() - inicio;

    if (datos && datos.success) {
        registrarGuardadoEnAnaliticas(true, '', ms, estado);
        return guardadoCorrecto(datos);
    }

    const motivo = sinConexion ? 'sin_conexion' : sesionCerrada ? 'sesion_cerrada'
        : (datos && datos.motivo) || `respuesta_invalida_${estado}`;
    registrarGuardadoEnAnaliticas(false, motivo, ms, estado);

    if (sesionCerrada) return avisarSesionCerrada(forzar);
    if (estado === 409 && datos && datos.actual) return resolverConflicto(datos);

    let mensaje;
    if (sinConexion) {
        mensaje = 'No hubo respuesta del servidor. Revisa tu conexión a internet e inténtalo de nuevo.';
    } else if (datos && datos.error) {
        mensaje = datos.error;
    } else {
        mensaje = 'El servidor respondió de forma inesperada y no se pudo confirmar el guardado.';
    }

    const botones = [{ texto: 'Cerrar', valor: null, estilo: 'secundario' }];
    if (!MOTIVOS_SIN_REINTENTO.includes(motivo)) {
        botones.push({ texto: 'Intentar de nuevo', valor: 'reintentar', estilo: 'primario' });
    }
    const eleccion = await SipconsDialogo.mostrar({
        tipo: 'error',
        titulo: 'No se guardaron los cambios',
        mensaje,
        nota: 'Lo que escribiste sigue en pantalla; no se perdió nada.',
        botones
    });
    if (eleccion === 'reintentar') handleFormSubmit(null, forzar);
}

async function guardadoCorrecto(datos) {
    estadoDetalle.salirSinAviso = true;
    clearTimeout(temporizadorBorrador);
    borrarBorrador(true);

    const agregados = (datos.archivos_agregados || []).length;
    await SipconsDialogo.mostrar({
        tipo: 'ok',
        titulo: 'Cambios guardados',
        mensaje: `La incidencia ${estadoDetalle.folio} se guardó correctamente el ${formatearFechaHora(datos.guardado_en || new Date())}.`,
        nota: agregados ? `Se ${agregados === 1 ? 'adjuntó 1 archivo' : `adjuntaron ${agregados} archivos`}.` : '',
        botones: [{ texto: 'Aceptar', valor: 'ok', estilo: 'primario' }]
    });

    // Sin recargar la página (antes se volvía a descargar todo, incluidos los
    // archivos): se sigue editando sobre la versión recién guardada.
    const incidencia = datos.incidencia;
    if (!incidencia) {
        window.location.reload();
        return;
    }
    estadoDetalle.version = incidencia.version || estadoDetalle.version;
    estadoDetalle.datosServidor = incidencia;
    estadoDetalle.original = leerFormulario();
    if (estadoDetalle.selector) estadoDetalle.selector.limpiar();
    if (agregados) cargarArchivosAdjuntos(incidencia.archivos || [], estadoDetalle.id);
    document.querySelectorAll('#form-editar .aviso-borrador').forEach(a => a.remove());
    document.querySelectorAll('.campo-integrado').forEach(el => el.classList.remove('campo-integrado'));
    clearTimeout(temporizadorBorrador);
    estadoDetalle.salirSinAviso = false;
}

async function avisarSesionCerrada(forzar) {
    const eleccion = await SipconsDialogo.mostrar({
        tipo: 'aviso',
        titulo: 'Tu sesión se cerró',
        mensaje: 'Para guardar, vuelve a iniciar sesión. Lo que escribiste sigue en pantalla y no se perderá.',
        botones: [
            { texto: 'Cerrar', valor: null, estilo: 'secundario' },
            { texto: 'Iniciar sesión', valor: 'login', estilo: 'primario' }
        ]
    });
    if (eleccion !== 'login') return;

    window.open('../auth/login.html', '_blank');
    const siguiente = await SipconsDialogo.mostrar({
        tipo: 'info',
        titulo: 'Inicia sesión y regresa',
        mensaje: 'Se abrió una pestaña para iniciar sesión. Cuando termines, vuelve a esta pestaña y presiona "Guardar ahora".',
        botones: [
            { texto: 'Cerrar', valor: null, estilo: 'secundario' },
            { texto: 'Guardar ahora', valor: 'reintentar', estilo: 'primario' }
        ]
    });
    if (siguiente === 'reintentar') handleFormSubmit(null, forzar);
}

async function resolverConflicto(datos) {
    const actual = datos.actual;
    const valoresActuales = valoresDeDatos(actual);
    const mios = leerFormulario();

    // Si lo que hay ahora en la base ya es lo mismo que se quiere guardar
    // (p. ej. se guardó igual desde otra pestaña), no hay nada que resolver.
    if (mismosValores(mios, valoresActuales) && !archivosSeleccionados().length) {
        borrarBorrador(true);
        estadoDetalle.salirSinAviso = true;
        await SipconsDialogo.mostrar({
            tipo: 'ok',
            titulo: 'Cambios guardados',
            mensaje: 'Estos cambios ya estaban guardados.',
            botones: [{ texto: 'Aceptar', valor: 'ok', estilo: 'primario' }]
        });
        window.location.reload();
        return;
    }

    const cambiados = camposDistintos(valoresDeDatos(estadoDetalle.datosServidor), valoresActuales)
        .map(campo => NOMBRES_CAMPO[campo]);
    const cuando = datos.modificado_en ? ` el ${formatearFechaHora(datos.modificado_en)}` : '';
    const quienTexto = datos.modificado_por_mi
        ? `Tú mismo la modificaste desde otra pestaña o equipo${cuando}`
        : `${datos.modificado_por || 'Otra persona'} la modificó${cuando}`;
    const quienCorto = datos.modificado_por_mi ? 'tu otra pestaña' : (datos.modificado_por || 'la otra persona');

    const eleccion = await SipconsDialogo.mostrar({
        tipo: 'aviso',
        titulo: 'Esta incidencia cambió mientras la editabas',
        mensaje: `${quienTexto}, después de que abriste esta página. Todavía no se guardaron tus cambios.`,
        lista: cambiados.length ? cambiados.map(nombre => `Cambió: ${nombre}`) : null,
        nota: '"Integrar ambas versiones" conserva tus cambios y los de la otra versión; solo si los dos cambiaron el mismo campo te preguntará qué dejar.',
        botones: [
            { texto: 'Cancelar', valor: null, estilo: 'secundario' },
            { texto: 'Descartar mis cambios', valor: 'descartar', estilo: 'secundario' },
            { texto: 'Guardar solo los míos', valor: 'forzar', estilo: 'peligro' },
            { texto: 'Integrar ambas versiones', valor: 'integrar', estilo: 'primario' }
        ]
    });

    if (eleccion === 'integrar') {
        await integrarVersiones({
            mios,
            original: estadoDetalle.original,
            baseServidor: valoresDeDatos(estadoDetalle.datosServidor),
            actual,
            quien: quienCorto
        });
    } else if (eleccion === 'forzar') {
        const confirmar = await SipconsDialogo.mostrar({
            tipo: 'aviso',
            titulo: '¿Reemplazar los otros cambios?',
            mensaje: 'Se guardará solo lo que tienes en pantalla y se perderán los cambios de la otra versión en los campos que no coinciden.',
            botones: [
                { texto: 'Volver', valor: null, estilo: 'secundario' },
                { texto: 'Sí, guardar solo los míos', valor: 'si', estilo: 'peligro' }
            ]
        });
        if (confirmar === 'si') handleFormSubmit(null, true);
    } else if (eleccion === 'descartar') {
        borrarBorrador(true);
        estadoDetalle.salirSinAviso = true;
        window.location.reload();
    }
}

// Función principal para cargar los detalles
async function cargarDetalleIncidencia(id) {
    try {
        const response = await fetch(`../backend/detalle.php?id=${encodeURIComponent(id)}`, {
            headers: { 'Accept': 'application/json' },
            cache: 'no-store'
        });
        const data = await response.json();

        if (!response.ok || data.error) {
            throw new Error(data.error || 'Error al cargar los detalles');
        }

        estadoDetalle.id = id;
        estadoDetalle.folio = data.numero_incidente || '';
        estadoDetalle.version = data.version || '';
        estadoDetalle.datosServidor = data;
        estadoDetalle.limites = data.limites_subida || null;

        // Lista de técnicos desde la BD (usuarios con rol técnico/supervisor/administrador)
        const tecnicosLista = await SipconsTecnicos.cargar();

        document.getElementById("detalle-incidencia").innerHTML = createFormHTML(data, tecnicosLista);
        const form = document.getElementById("form-editar");
        form.addEventListener("submit", (e) => handleFormSubmit(e));

        // Configurar la funcionalidad de múltiples técnicos
        setupTecnicosMultiples(tecnicosLista);

        // Selector de archivos nuevos (vista previa, quitar, aviso de peso)
        estadoDetalle.selector = SipconsArchivos.crear(document.getElementById('archivos'), {
            limites: estadoDetalle.limites || {},
            texto: 'Toca para agregar fotos o archivos'
        });

        // Desplegables escribibles de cliente y de quien reporta
        configurarClienteYContacto(data.cliente);

        // Botón de eliminar: solo visible para administradores
        const btnEliminar = document.getElementById('btn-eliminar-incidencia');
        window.sipconsOnAuthReady(function (auth) {
            const rol = auth.user && auth.user.rol;
            if (ROLES_ELIMINAR_INCIDENCIA.includes(rol)) {
                btnEliminar.style.display = 'inline-block';
                btnEliminar.addEventListener('click', () => eliminarIncidencia(id, data.numero_incidente));
            }
        });

        // El rol Técnico no puede cerrar incidencias con/sin factura
        window.sipconsAplicarRestriccionEstatus(document.getElementById("estatus"));

        if (data.archivos) {
            cargarArchivosAdjuntos(data.archivos, id);
        }

        // Punto de partida para saber si hay cambios sin guardar
        estadoDetalle.original = leerFormulario();

        const borrador = leerBorrador();
        if (borrador) {
            if (mismosValores(borrador.valores, estadoDetalle.original)) {
                borrarBorrador();
            } else {
                mostrarAvisoBorrador(borrador);
            }
        }

        ['input', 'change', 'click', 'archivos-cambiados'].forEach(evento => form.addEventListener(evento, programarBorrador));

    } catch (error) {
        console.error("Error al cargar detalles:", error);
        document.getElementById("detalle-incidencia").innerHTML =
            `<p>Error al cargar los detalles: ${escapeAttr(error.message)}</p>`;
    }
}

// Aviso del navegador al salir con cambios sin guardar (el borrador ya quedó respaldado)
window.addEventListener('beforeunload', (e) => {
    if (estadoDetalle.salirSinAviso || !hayCambiosSinGuardar()) return;
    guardarBorrador();
    e.preventDefault();
    e.returnValue = '';
});

// En móvil el navegador puede cerrar la pestaña sin "beforeunload": se respalda al ocultarse
document.addEventListener('visibilitychange', () => {
    if (document.visibilityState === 'hidden' && !estadoDetalle.salirSinAviso) guardarBorrador();
});

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
