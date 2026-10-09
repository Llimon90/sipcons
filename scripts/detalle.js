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
        for (const archivo of archivos) {
            const { container, preview } = createFileContainer(archivo, id);
            contenedorArchivos.appendChild(container);

            if (preview && preview.tagName === 'CANVAS') {
                try {
                    await renderPdfThumbnail(archivo, preview);
                } catch (error) {
                    preview.replaceWith(iconoArchivo(archivo));
                }
            }
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

function formatearMB(bytes) {
    return (bytes / 1048576).toLocaleString('es-MX', { maximumFractionDigits: 1 }) + ' MB';
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

function guardarBorrador() {
    if (!estadoDetalle.original) return;
    const valores = leerFormulario();
    try {
        if (mismosValores(valores, estadoDetalle.original)) {
            localStorage.removeItem(claveBorrador());
        } else {
            localStorage.setItem(claveBorrador(), JSON.stringify({
                valores,
                fecha: Date.now(),
                version: estadoDetalle.version,
                archivos: archivosSeleccionados().length
            }));
        }
    } catch (e) { /* sin almacenamiento disponible: solo se pierde el respaldo */ }
}

function borrarBorrador() {
    try { localStorage.removeItem(claveBorrador()); } catch (e) { /* nada */ }
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

    const notas = [`Los escribiste el ${formatearFechaHora(new Date(borrador.fecha))}.`];
    if (borrador.version && borrador.version !== estadoDetalle.version) {
        notas.push('La incidencia cambió desde entonces: revisa los datos antes de guardar.');
    }
    if (borrador.archivos > 0) {
        notas.push('Los archivos que habías elegido tendrás que seleccionarlos otra vez.');
    }

    aviso.innerHTML = `
        <i class="fas fa-history" aria-hidden="true"></i>
        <div class="aviso-borrador-texto"><strong>Tienes cambios que no se guardaron</strong><span></span></div>
        <div class="aviso-borrador-acciones">
            <button type="button" class="btn-recuperar">Recuperar mis cambios</button>
            <button type="button" class="btn-descartar">Descartar</button>
        </div>`;
    aviso.querySelector('.aviso-borrador-texto span').textContent = notas.join(' ');

    aviso.querySelector('.btn-recuperar').addEventListener('click', () => {
        aplicarValores(borrador.valores);
        aviso.remove();
        showNotification('Se recuperaron tus cambios. Revísalos y presiona "Guardar cambios".');
    });
    aviso.querySelector('.btn-descartar').addEventListener('click', () => {
        borrarBorrador();
        aviso.remove();
    });

    form.prepend(aviso);
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

// Revisa el tamaño de los archivos antes de enviar, para avisar al momento
function revisarArchivosAntesDeEnviar() {
    const archivos = archivosSeleccionados();
    const limites = estadoDetalle.limites || {};
    if (!archivos.length) return null;

    if (limites.archivo_mb) {
        const grandes = archivos.filter(a => a.size > limites.archivo_mb * 1048576);
        if (grandes.length) {
            return {
                mensaje: `${grandes.length === 1 ? 'Este archivo pesa' : 'Estos archivos pesan'} más de lo permitido (máximo ${limites.archivo_mb} MB por archivo):`,
                lista: grandes.map(a => `${a.name} (${formatearMB(a.size)})`)
            };
        }
    }
    const total = archivos.reduce((suma, a) => suma + a.size, 0);
    if (limites.total_mb && total > limites.total_mb * 1048576 * 0.95) {
        return {
            mensaje: `Los archivos suman ${formatearMB(total)} y el máximo por guardado es ${limites.total_mb} MB. Guarda primero algunos y después los demás.`
        };
    }
    if (limites.max_archivos && archivos.length > limites.max_archivos) {
        return { mensaje: `Puedes adjuntar hasta ${limites.max_archivos} archivos a la vez.` };
    }
    return null;
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
    archivosSeleccionados().forEach(archivo => formData.append('archivos[]', archivo));

    guardarBorrador();
    marcarGuardando(true);
    const inicio = performance.now();
    let respuesta = null;
    let datos = null;
    let sinConexion = false;

    try {
        respuesta = await fetch('../backend/actualiza.php', {
            method: 'POST',
            body: formData,
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json' }
        });
        const texto = await respuesta.text();
        try { datos = JSON.parse(texto); } catch (err) { datos = null; }
    } catch (err) {
        sinConexion = true;
    } finally {
        marcarGuardando(false);
    }

    const ms = performance.now() - inicio;
    const estado = respuesta ? respuesta.status : 0;
    const sesionCerrada = estado === 401 || (respuesta && respuesta.redirected && /login/i.test(respuesta.url));

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
    borrarBorrador();
    estadoDetalle.salirSinAviso = true;

    const agregados = (datos.archivos_agregados || []).length;
    await SipconsDialogo.mostrar({
        tipo: 'ok',
        titulo: 'Cambios guardados',
        mensaje: `La incidencia ${estadoDetalle.folio} se guardó correctamente el ${formatearFechaHora(datos.guardado_en || new Date())}.`,
        nota: agregados ? `Se ${agregados === 1 ? 'adjuntó 1 archivo' : `adjuntaron ${agregados} archivos`}.` : '',
        botones: [{ texto: 'Aceptar', valor: 'ok', estilo: 'primario' }]
    });
    window.location.reload();
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

    // Si lo que hay ahora en la base ya es lo mismo que se quiere guardar
    // (p. ej. se guardó igual desde otra pestaña), no hay nada que resolver.
    if (mismosValores(leerFormulario(), valoresActuales) && !archivosSeleccionados().length) {
        borrarBorrador();
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
    const quien = datos.modificado_por_mi
        ? `Tú mismo la modificaste desde otra pestaña o equipo${cuando}`
        : `${datos.modificado_por || 'Otra persona'} la modificó${cuando}`;

    const eleccion = await SipconsDialogo.mostrar({
        tipo: 'aviso',
        titulo: 'Esta incidencia cambió mientras la editabas',
        mensaje: `${quien}, después de que abriste esta página. Para no borrar esos cambios, todavía no se guardaron los tuyos.`,
        lista: cambiados.length ? cambiados.map(nombre => `Cambió: ${nombre}`) : null,
        nota: '"Ver versión actual" carga la incidencia como está ahora y te deja recuperar lo que escribiste. "Guardar de todos modos" reemplaza esos cambios con los tuyos.',
        botones: [
            { texto: 'Cancelar', valor: null, estilo: 'secundario' },
            { texto: 'Guardar de todos modos', valor: 'forzar', estilo: 'peligro' },
            { texto: 'Ver versión actual', valor: 'recargar', estilo: 'primario' }
        ]
    });

    if (eleccion === 'forzar') {
        handleFormSubmit(null, true);
    } else if (eleccion === 'recargar') {
        guardarBorrador();
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

        ['input', 'change', 'click'].forEach(evento => form.addEventListener(evento, programarBorrador));

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
