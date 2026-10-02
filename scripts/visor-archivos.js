// scripts/visor-archivos.js

/**
 * Visor de archivos en un modal a pantalla completa (estilos "VISOR DE
 * ARCHIVOS" en styles.css). Muestra imágenes, PDF (con pdf.js si la página
 * lo carga; si no, con el visor del navegador), video, audio y texto. Para
 * los demás formatos ofrece abrir o descargar. Siempre deja a la vista la
 * opción "Abrir en pestaña nueva".
 *
 *   SipconsVisor.abrir([{ url, nombre }, ...], indiceInicial)
 *
 * Teclado: Esc cierra, ← → cambian de archivo. En móvil se desliza el dedo
 * para cambiar de archivo y el botón "atrás" del teléfono cierra el visor.
 */
(function () {
    const TIPOS = {
        imagen: ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'svg'],
        pdf: ['pdf'],
        video: ['mp4', 'webm', 'ogg', 'mov', 'm4v'],
        audio: ['mp3', 'wav', 'm4a', 'aac', 'oga'],
        texto: ['txt', 'csv', 'log']
    };
    const ICONOS = {
        doc: 'fa-file-word', docx: 'fa-file-word',
        xls: 'fa-file-excel', xlsx: 'fa-file-excel', csv: 'fa-file-csv',
        ppt: 'fa-file-powerpoint', pptx: 'fa-file-powerpoint',
        zip: 'fa-file-archive', rar: 'fa-file-archive', '7z': 'fa-file-archive'
    };
    const MAX_PAGINAS_PDF = 50;

    let el = null;           // nodos del visor (se crean al primer uso)
    let archivos = [];
    let indice = 0;
    let carga = 0;           // se incrementa en cada render para cancelar el anterior
    let enHistorial = false; // se agregó una entrada al historial al abrir
    let focoPrevio = null;

    function extension(url) {
        return String(url).split('?')[0].split('#')[0].split('.').pop().toLowerCase();
    }

    function tipoDe(url) {
        const ext = extension(url);
        return Object.keys(TIPOS).find(tipo => TIPOS[tipo].includes(ext)) || 'otro';
    }

    function iconoDe(url) {
        const tipo = tipoDe(url);
        if (tipo === 'imagen') return 'fa-file-image';
        if (tipo === 'pdf') return 'fa-file-pdf';
        if (tipo === 'video') return 'fa-file-video';
        if (tipo === 'audio') return 'fa-file-audio';
        return ICONOS[extension(url)] || 'fa-file-alt';
    }

    function nombreDe(url) {
        try {
            return decodeURIComponent(String(url).split('?')[0].split('/').pop());
        } catch (e) {
            return String(url).split('/').pop();
        }
    }

    function crear() {
        el = document.createElement('div');
        el.className = 'visor';
        el.setAttribute('role', 'dialog');
        el.setAttribute('aria-modal', 'true');
        el.setAttribute('aria-label', 'Vista previa de archivo');
        el.innerHTML = `
            <div class="visor-barra">
                <div class="visor-titulo">
                    <span class="visor-nombre"></span>
                    <span class="visor-contador"></span>
                </div>
                <a class="visor-btn" data-accion="pestana" target="_blank" rel="noopener" title="Abrir en pestaña nueva">
                    <i class="fas fa-external-link-alt"></i><span class="visor-btn-texto">Abrir en pestaña nueva</span>
                </a>
                <a class="visor-btn" data-accion="descargar" download title="Descargar">
                    <i class="fas fa-download"></i><span class="visor-btn-texto">Descargar</span>
                </a>
                <button type="button" class="visor-btn" data-accion="cerrar" title="Cerrar (Esc)" aria-label="Cerrar">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="visor-cuerpo"></div>
            <button type="button" class="visor-btn visor-nav visor-nav--prev" title="Anterior (←)" aria-label="Archivo anterior">
                <i class="fas fa-chevron-left"></i>
            </button>
            <button type="button" class="visor-btn visor-nav visor-nav--next" title="Siguiente (→)" aria-label="Archivo siguiente">
                <i class="fas fa-chevron-right"></i>
            </button>`;
        document.body.appendChild(el);

        el.querySelector('[data-accion="cerrar"]').addEventListener('click', cerrar);
        el.querySelector('.visor-nav--prev').addEventListener('click', () => mover(-1));
        el.querySelector('.visor-nav--next').addEventListener('click', () => mover(1));

        // Clic en el fondo (fuera del archivo) cierra
        const cuerpo = el.querySelector('.visor-cuerpo');
        cuerpo.addEventListener('click', (e) => {
            if (e.target === cuerpo) cerrar();
        });

        // Deslizar el dedo a los lados cambia de archivo
        let inicioX = null, inicioY = null;
        cuerpo.addEventListener('touchstart', (e) => {
            if (e.touches.length !== 1) { inicioX = null; return; }
            inicioX = e.touches[0].clientX;
            inicioY = e.touches[0].clientY;
        }, { passive: true });
        cuerpo.addEventListener('touchend', (e) => {
            if (inicioX === null) return;
            const dx = e.changedTouches[0].clientX - inicioX;
            const dy = e.changedTouches[0].clientY - inicioY;
            inicioX = null;
            if (Math.abs(dx) > 60 && Math.abs(dx) > Math.abs(dy) * 1.5) mover(dx < 0 ? 1 : -1);
        });

        document.addEventListener('keydown', (e) => {
            if (!el.classList.contains('abierto')) return;
            if (e.key === 'Escape') { e.preventDefault(); cerrar(); }
            else if (e.key === 'ArrowLeft') mover(-1);
            else if (e.key === 'ArrowRight') mover(1);
            else if (e.key === 'Tab') mantenerFoco(e);
        });

        window.addEventListener('popstate', () => {
            if (enHistorial && el.classList.contains('abierto')) {
                enHistorial = false;
                cerrarVisor();
            }
        });
    }

    // Mantiene el foco del teclado dentro del visor mientras está abierto
    function mantenerFoco(e) {
        const enfocables = Array.from(el.querySelectorAll('a[href], button, video, audio, iframe'))
            .filter(n => n.offsetParent !== null);
        if (!enfocables.length) return;
        const primero = enfocables[0];
        const ultimo = enfocables[enfocables.length - 1];
        if (e.shiftKey && document.activeElement === primero) { e.preventDefault(); ultimo.focus(); }
        else if (!e.shiftKey && document.activeElement === ultimo) { e.preventDefault(); primero.focus(); }
    }

    function aviso(icono, mensaje, url) {
        const div = document.createElement('div');
        div.className = 'visor-aviso';
        div.innerHTML = `
            <i class="fas ${icono}"></i>
            <p></p>
            <div class="visor-acciones">
                <a class="visor-btn" target="_blank" rel="noopener"><i class="fas fa-external-link-alt"></i> Abrir en pestaña nueva</a>
                <a class="visor-btn" download><i class="fas fa-download"></i> Descargar</a>
            </div>`;
        div.querySelector('p').textContent = mensaje;
        div.querySelectorAll('a').forEach(a => { a.href = url; });
        return div;
    }

    async function renderPdf(url, contenedor, cuerpo, idCarga) {
        const pdf = await pdfjsLib.getDocument(url).promise;
        if (idCarga !== carga) return;

        const ancho = Math.min(cuerpo.clientWidth - 20, 960);
        const escalaPantalla = window.devicePixelRatio || 1;
        const total = Math.min(pdf.numPages, MAX_PAGINAS_PDF);

        for (let n = 1; n <= total; n++) {
            const pagina = await pdf.getPage(n);
            if (idCarga !== carga) return;

            const base = pagina.getViewport({ scale: 1 });
            const viewport = pagina.getViewport({ scale: (ancho / base.width) * escalaPantalla });
            const canvas = document.createElement('canvas');
            canvas.width = viewport.width;
            canvas.height = viewport.height;
            canvas.setAttribute('aria-label', `Página ${n} de ${pdf.numPages}`);
            contenedor.appendChild(canvas);
            await pagina.render({ canvasContext: canvas.getContext('2d'), viewport }).promise;
            if (n === 1) {
                const cargando = contenedor.querySelector('.visor-aviso');
                if (cargando) cargando.remove();
            }
        }

        if (pdf.numPages > MAX_PAGINAS_PDF && idCarga === carga) {
            contenedor.appendChild(aviso('fa-file-pdf',
                `Se muestran las primeras ${MAX_PAGINAS_PDF} de ${pdf.numPages} páginas. Abre el documento para verlo completo.`, url));
        }
    }

    function mostrar() {
        const idCarga = ++carga;
        const archivo = archivos[indice];
        const url = archivo.url;
        const nombre = archivo.nombre || nombreDe(url);
        const tipo = tipoDe(url);

        el.querySelector('.visor-nombre').textContent = nombre;
        el.querySelector('.visor-nombre').title = nombre;
        el.querySelector('.visor-contador').textContent = archivos.length > 1
            ? `${indice + 1} de ${archivos.length}`
            : '';
        el.querySelector('[data-accion="pestana"]').href = url;
        const descargar = el.querySelector('[data-accion="descargar"]');
        descargar.href = url;
        descargar.setAttribute('download', nombre);
        el.querySelector('.visor-nav--prev').hidden = archivos.length < 2;
        el.querySelector('.visor-nav--next').hidden = archivos.length < 2;

        const cuerpo = el.querySelector('.visor-cuerpo');
        cuerpo.innerHTML = '';
        cuerpo.scrollTop = 0;
        cuerpo.classList.toggle('visor-cuerpo--doc', tipo === 'pdf' || tipo === 'texto');

        if (tipo === 'imagen') {
            const img = document.createElement('img');
            img.alt = nombre;
            img.src = url;
            img.onerror = () => {
                if (idCarga !== carga) return;
                cuerpo.innerHTML = '';
                cuerpo.appendChild(aviso('fa-file-image', 'No se pudo cargar la imagen.', url));
            };
            cuerpo.appendChild(img);
        } else if (tipo === 'video' || tipo === 'audio') {
            const media = document.createElement(tipo);
            media.src = url;
            media.controls = true;
            media.preload = 'metadata';
            media.onerror = () => {
                if (idCarga !== carga) return;
                cuerpo.innerHTML = '';
                cuerpo.appendChild(aviso(iconoDe(url), 'El navegador no puede reproducir este formato.', url));
            };
            cuerpo.appendChild(media);
        } else if (tipo === 'pdf') {
            const contenedor = document.createElement('div');
            contenedor.className = 'visor-pdf';
            cuerpo.appendChild(contenedor);

            if (window.pdfjsLib) {
                const cargando = aviso('fa-spinner fa-spin', 'Cargando documento...', url);
                cargando.querySelector('.visor-acciones').remove();
                contenedor.appendChild(cargando);
                renderPdf(url, contenedor, cuerpo, idCarga).catch(error => {
                    console.error('Error al mostrar PDF:', error);
                    if (idCarga !== carga) return;
                    contenedor.innerHTML = '';
                    contenedor.appendChild(aviso('fa-file-pdf', 'No se pudo mostrar el PDF aquí.', url));
                });
            } else {
                const iframe = document.createElement('iframe');
                iframe.src = url;
                iframe.title = nombre;
                contenedor.appendChild(iframe);
            }
        } else if (tipo === 'texto') {
            const contenedor = document.createElement('div');
            contenedor.className = 'visor-pdf';
            const iframe = document.createElement('iframe');
            iframe.src = url;
            iframe.title = nombre;
            contenedor.appendChild(iframe);
            cuerpo.appendChild(contenedor);
        } else {
            cuerpo.appendChild(aviso(iconoDe(url),
                `La vista previa no está disponible para archivos .${extension(url)}.`, url));
        }
    }

    function mover(paso) {
        if (archivos.length < 2) return;
        indice = (indice + paso + archivos.length) % archivos.length;
        mostrar();
    }

    function abrir(lista, inicial) {
        if (!el) crear();
        archivos = lista.map(a => (typeof a === 'string' ? { url: a } : a));
        if (!archivos.length) return;
        indice = Math.max(0, Math.min(inicial || 0, archivos.length - 1));

        if (!el.classList.contains('abierto')) {
            focoPrevio = document.activeElement;
            el.classList.add('abierto');
            document.body.classList.add('visor-abierto');
            history.pushState({ sipconsVisor: true }, '');
            enHistorial = true;
        }
        mostrar();
        el.querySelector('[data-accion="cerrar"]').focus();
    }

    function cerrarVisor() {
        carga++;
        el.classList.remove('abierto');
        document.body.classList.remove('visor-abierto');
        el.querySelector('.visor-cuerpo').innerHTML = ''; // detiene videos/audio
        if (focoPrevio && focoPrevio.focus) focoPrevio.focus();
    }

    function cerrar() {
        if (!el || !el.classList.contains('abierto')) return;
        if (enHistorial) {
            history.back(); // popstate cierra el visor
        } else {
            cerrarVisor();
        }
    }

    window.SipconsVisor = { abrir, cerrar, tipoDe, iconoDe, nombreDe };
})();
