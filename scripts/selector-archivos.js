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
    // Fotos que el navegador puede reducir antes de enviar (ver comprimirImagen)
    const seReduce = f => ['jpg', 'jpeg', 'png', 'webp', 'bmp'].includes(extension(f.name)) && f.size >= 600 * 1024;

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
            // Las fotos grandes se reducen al enviar: no se marcan como problema
            if (limites.archivo_mb && archivo.size > limites.archivo_mb * MB && !seReduce(archivo)) {
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
                detalle.textContent = problema ? `${formatearMB(archivo.size)} · ${problema}`
                    : seReduce(archivo) ? `${formatearMB(archivo.size)} · se reducirá al enviar` : formatearMB(archivo.size);
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
                // Las fotos que se reducen cuentan con un tamaño estimado tras reducirlas
                const total = archivos.reduce((suma, a) => suma + (seReduce(a) ? Math.min(a.size, 800 * 1024) : a.size), 0);
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

    // ---------- compresión de fotos ----------
    // Las fotos de celular (3-10 MB) hacían muy lento cada guardado. Se
    // reducen a COMPRIMIR_LADO_MAX px por lado en JPEG: una foto de 5 MB queda
    // en ~300-600 KB y se sigue viendo nítida como evidencia. Si el navegador
    // no puede abrir la imagen (p. ej. HEIC fuera de Safari) o el resultado no
    // es más chico, se envía la original.
    const COMPRIMIR_DESDE_BYTES = 600 * 1024;
    const COMPRIMIR_LADO_MAX = 2048;
    const COMPRIMIR_CALIDAD = 0.82;
    const COMPRIMIBLES = ['jpg', 'jpeg', 'png', 'webp', 'bmp', 'heic', 'heif'];

    async function abrirImagen(archivo) {
        if (window.createImageBitmap) {
            try { return await createImageBitmap(archivo, { imageOrientation: 'from-image' }); } catch (e) { /* se intenta abajo */ }
        }
        const url = URL.createObjectURL(archivo);
        try {
            const img = new Image();
            img.decoding = 'async';
            img.src = url;
            await img.decode();
            return img;
        } finally {
            URL.revokeObjectURL(url);
        }
    }

    async function comprimirImagen(archivo) {
        const ext = extension(archivo.name);
        if (!COMPRIMIBLES.includes(ext) || archivo.size < COMPRIMIR_DESDE_BYTES) return archivo;
        try {
            const imagen = await abrirImagen(archivo);
            const ancho = imagen.width, alto = imagen.height;
            const escala = Math.min(1, COMPRIMIR_LADO_MAX / Math.max(ancho, alto));
            const canvas = document.createElement('canvas');
            canvas.width = Math.round(ancho * escala);
            canvas.height = Math.round(alto * escala);
            const ctx = canvas.getContext('2d');
            ctx.fillStyle = '#fff'; // PNG con transparencia: fondo blanco en vez de negro
            ctx.fillRect(0, 0, canvas.width, canvas.height);
            ctx.drawImage(imagen, 0, 0, canvas.width, canvas.height);
            if (imagen.close) imagen.close();
            const blob = await new Promise(r => canvas.toBlob(r, 'image/jpeg', COMPRIMIR_CALIDAD));
            if (!blob || blob.size >= archivo.size) return archivo;
            const nombre = archivo.name.replace(/\.[^.]+$/, '') + '.jpg';
            return new File([blob], nombre, { type: 'image/jpeg', lastModified: archivo.lastModified });
        } catch (e) {
            return archivo;
        }
    }

    // Devuelve los archivos listos para enviar (fotos comprimidas), en orden
    async function prepararParaEnvio(archivos) {
        const listos = [];
        for (const archivo of archivos) listos.push(await comprimirImagen(archivo));
        return listos;
    }

    // ---------- envío con avance ----------
    /**
     * Envía un FormData por POST mostrando el avance de la subida.
     * Resuelve siempre (nunca rechaza) con:
     *   { estado, datos, sinConexion, sesionCerrada }
     * alProgresar(porcentaje) se llama mientras se suben los datos; con 100
     * la subida terminó y falta la respuesta del servidor.
     */
    function enviarConAvance(url, formData, alProgresar) {
        return new Promise(resolver => {
            const xhr = new XMLHttpRequest();
            xhr.open('POST', url, true);
            xhr.withCredentials = true;
            xhr.setRequestHeader('Accept', 'application/json');
            if (alProgresar && xhr.upload) {
                xhr.upload.onprogress = (e) => {
                    if (e.lengthComputable) alProgresar(Math.min(100, Math.round((e.loaded / e.total) * 100)));
                };
                xhr.upload.onload = () => alProgresar(100);
            }
            xhr.onload = () => {
                let datos = null;
                try { datos = JSON.parse(xhr.responseText); } catch (e) { datos = null; }
                const redirigidoALogin = xhr.responseURL && /login/i.test(xhr.responseURL) && !/\.php/i.test(xhr.responseURL.split('?')[0].split('/').pop());
                resolver({
                    estado: xhr.status,
                    datos,
                    sinConexion: false,
                    sesionCerrada: xhr.status === 401 || !!redirigidoALogin
                });
            };
            const sinRed = () => {
                if (window.SipconsConexion) window.SipconsConexion.revisar();
                resolver({ estado: 0, datos: null, sinConexion: true, sesionCerrada: false });
            };
            xhr.onerror = sinRed;
            xhr.ontimeout = sinRed;
            xhr.send(formData);
        });
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

    window.SipconsArchivos = { crear, cargarLimites, prepararParaEnvio, enviarConAvance };
})();
