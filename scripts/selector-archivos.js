// scripts/selector-archivos.js

/**
 * Selector de archivos adjuntos: convierte un <input type="file" multiple>
 * en una zona para tocar o arrastrar archivos, con vista previa de cada
 * uno, botón para quitarlo y aviso si pesa más de lo permitido. El input
 * real se conserva (y sus .files se mantienen al día), así que el resto del
 * código puede seguir leyendo input.files. Estilos: "SELECTOR DE ARCHIVOS"
 * en styles.css.
 *
 *   const selector = SipconsArchivos.crear(input, { limites });
 *   selector.archivos()   -> File[]
 *   selector.revisar()    -> null o { mensaje, lista } si algo no se puede enviar
 *   selector.limpiar()
 *   selector.establecerLimites({ archivo_mb, total_mb, max_archivos, extensiones })
 */
(function () {
    const ICONOS = {
        pdf: 'fa-file-pdf', doc: 'fa-file-word', docx: 'fa-file-word',
        xls: 'fa-file-excel', xlsx: 'fa-file-excel', csv: 'fa-file-csv',
        ppt: 'fa-file-powerpoint', pptx: 'fa-file-powerpoint',
        zip: 'fa-file-archive', rar: 'fa-file-archive', '7z': 'fa-file-archive',
        mp4: 'fa-file-video', webm: 'fa-file-video', mov: 'fa-file-video', m4v: 'fa-file-video', '3gp': 'fa-file-video',
        mp3: 'fa-file-audio', wav: 'fa-file-audio', m4a: 'fa-file-audio', aac: 'fa-file-audio', ogg: 'fa-file-audio', oga: 'fa-file-audio'
    };
    const IMAGENES_CON_VISTA = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'];
    const MB = 1048576;

    const extension = nombre => (String(nombre).split('.').pop() || '').toLowerCase();
    const formatearMB = bytes => bytes < MB
        ? Math.max(1, Math.round(bytes / 1024)).toLocaleString('es-MX') + ' KB'
        : (bytes / MB).toLocaleString('es-MX', { maximumFractionDigits: 1 }) + ' MB';
    const claveArchivo = f => `${f.name}|${f.size}|${f.lastModified}`;

    function crear(input, opciones = {}) {
        let limites = opciones.limites || {};
        let archivos = Array.from(input.files || []);
        const urls = new Map();
        const puedeEditarLista = typeof DataTransfer === 'function';

        // ---- estructura ----
        const caja = document.createElement('div');
        caja.className = 'selector-archivos';
        input.parentNode.insertBefore(caja, input);

        if (!input.id) input.id = `archivos-${Math.random().toString(36).slice(2, 8)}`;
        input.classList.add('selector-archivos-input');
        caja.appendChild(input);

        const zona = document.createElement('label');
        zona.className = 'selector-archivos-zona';
        zona.htmlFor = input.id;
        zona.innerHTML = `
            <i class="fas fa-cloud-upload-alt" aria-hidden="true"></i>
            <span class="selector-archivos-titulo"></span>
            <small class="selector-archivos-ayuda"></small>`;
        zona.querySelector('.selector-archivos-titulo').textContent =
            opciones.texto || 'Toca para elegir fotos o archivos';
        caja.appendChild(zona);

        const lista = document.createElement('ul');
        lista.className = 'selector-archivos-lista';
        lista.setAttribute('aria-live', 'polite');
        caja.appendChild(lista);

        function actualizarAyuda() {
            const partes = ['Imágenes, PDF, videos o documentos'];
            if (limites.archivo_mb) partes.push(`hasta ${limites.archivo_mb} MB cada uno`);
            if (window.matchMedia && window.matchMedia('(hover: hover)').matches) partes.push('también puedes arrastrarlos aquí');
            zona.querySelector('.selector-archivos-ayuda').textContent = partes.join(' · ');
            if (limites.extensiones && limites.extensiones.length) {
                input.accept = 'image/*,video/*,' + limites.extensiones.map(e => '.' + e).join(',');
            }
        }

        // ---- estado ----
        function sincronizarInput() {
            if (!puedeEditarLista) return;
            const dt = new DataTransfer();
            archivos.forEach(f => dt.items.add(f));
            input.files = dt.files;
        }

        function agregar(nuevos) {
            const existentes = new Set(archivos.map(claveArchivo));
            Array.from(nuevos).forEach(f => {
                if (!existentes.has(claveArchivo(f))) archivos.push(f);
            });
            sincronizarInput();
            pintar();
            avisarCambio();
        }

        function quitar(indice) {
            const [quitado] = archivos.splice(indice, 1);
            if (quitado && urls.has(quitado)) {
                URL.revokeObjectURL(urls.get(quitado));
                urls.delete(quitado);
            }
            sincronizarInput();
            pintar();
            avisarCambio();
        }

        function avisarCambio() {
            // Para quien escucha cambios del formulario (p. ej. el borrador del detalle)
            input.dispatchEvent(new Event('archivos-cambiados', { bubbles: true }));
            if (opciones.alCambiar) opciones.alCambiar(archivos.slice());
        }

        function problemaDe(archivo) {
            const ext = extension(archivo.name);
            if (limites.extensiones && limites.extensiones.length && !limites.extensiones.includes(ext)) {
                return 'Este tipo de archivo no se permite';
            }
            if (limites.archivo_mb && archivo.size > limites.archivo_mb * MB) {
                return `Pesa más de ${limites.archivo_mb} MB`;
            }
            return '';
        }

        function pintar() {
            lista.innerHTML = '';
            caja.classList.toggle('con-archivos', archivos.length > 0);
            archivos.forEach((archivo, i) => {
                const item = document.createElement('li');
                item.className = 'selector-archivos-item';
                const problema = problemaDe(archivo);
                if (problema) item.classList.add('con-problema');

                const vista = document.createElement('div');
                vista.className = 'selector-archivos-vista';
                const ext = extension(archivo.name);
                if (IMAGENES_CON_VISTA.includes(ext)) {
                    if (!urls.has(archivo)) urls.set(archivo, URL.createObjectURL(archivo));
                    const img = document.createElement('img');
                    img.src = urls.get(archivo);
                    img.alt = '';
                    vista.appendChild(img);
                } else {
                    const icono = document.createElement('i');
                    icono.className = `fas ${ICONOS[ext] || (ext === 'heic' || ext === 'heif' ? 'fa-file-image' : 'fa-file-alt')}`;
                    vista.appendChild(icono);
                }

                const info = document.createElement('div');
                info.className = 'selector-archivos-info';
                const nombre = document.createElement('span');
                nombre.className = 'selector-archivos-nombre';
                nombre.textContent = archivo.name;
                nombre.title = archivo.name;
                const detalle = document.createElement('small');
                detalle.textContent = problema ? `${formatearMB(archivo.size)} · ${problema}` : formatearMB(archivo.size);
                info.append(nombre, detalle);

                item.append(vista, info);
                if (puedeEditarLista) {
                    const boton = document.createElement('button');
                    boton.type = 'button';
                    boton.className = 'selector-archivos-quitar';
                    boton.title = 'Quitar';
                    boton.setAttribute('aria-label', `Quitar ${archivo.name}`);
                    boton.innerHTML = '<i class="fas fa-times"></i>';
                    boton.addEventListener('click', () => quitar(i));
                    item.appendChild(boton);
                }
                lista.appendChild(item);
            });
        }

        // ---- eventos ----
        input.addEventListener('change', () => {
            if (!puedeEditarLista) {
                archivos = Array.from(input.files);
                pintar();
                avisarCambio();
                return;
            }
            // El input reemplaza la selección (o la vacía si se cancela): se suman
            // a los que ya estaban y se vuelve a sincronizar el input con la lista
            agregar(Array.from(input.files));
        });

        ['dragenter', 'dragover'].forEach(tipo => zona.addEventListener(tipo, (e) => {
            e.preventDefault();
            zona.classList.add('arrastrando');
        }));
        ['dragleave', 'drop'].forEach(tipo => zona.addEventListener(tipo, () => zona.classList.remove('arrastrando')));
        zona.addEventListener('drop', (e) => {
            e.preventDefault();
            if (e.dataTransfer && e.dataTransfer.files.length) agregar(e.dataTransfer.files);
        });

        actualizarAyuda();
        pintar();

        return {
            archivos: () => archivos.slice(),
            limpiar() {
                urls.forEach(url => URL.revokeObjectURL(url));
                urls.clear();
                archivos = [];
                input.value = '';
                pintar();
            },
            establecerLimites(nuevos) {
                limites = nuevos || {};
                actualizarAyuda();
                pintar();
            },
            // Lo que impediría enviar (para avisar antes de mandar al servidor)
            revisar() {
                if (!archivos.length) return null;
                const conProblema = archivos.filter(problemaDe);
                if (conProblema.length) {
                    return {
                        mensaje: 'Estos archivos no se pueden adjuntar:',
                        lista: conProblema.map(a => `${a.name} — ${problemaDe(a)} (${formatearMB(a.size)})`)
                    };
                }
                const total = archivos.reduce((suma, a) => suma + a.size, 0);
                if (limites.total_mb && total > limites.total_mb * MB * 0.95) {
                    return { mensaje: `Los archivos suman ${formatearMB(total)} y el máximo por envío es ${limites.total_mb} MB. Envía primero algunos y después los demás.` };
                }
                if (limites.max_archivos && archivos.length > limites.max_archivos) {
                    return { mensaje: `Puedes adjuntar hasta ${limites.max_archivos} archivos a la vez.` };
                }
                return null;
            }
        };
    }

    // Límites del servidor (se piden una sola vez por página)
    let promesaLimites = null;
    function cargarLimites() {
        if (!promesaLimites) {
            promesaLimites = fetch('../backend/limites_subida.php', { headers: { 'Accept': 'application/json' } })
                .then(r => (r.ok ? r.json() : {}))
                .catch(() => ({}));
        }
        return promesaLimites;
    }

    window.SipconsArchivos = { crear, cargarLimites };
})();
