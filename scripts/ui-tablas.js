// scripts/ui-tablas.js

/**
 * Prepara las tablas para verse como tarjetas en móvil (ver "TABLAS EN
 * MÓVIL" en styles.css): marca cada tabla con encabezado con la clase
 * .tabla-cards y copia el texto de su columna a cada celda en data-label.
 *
 * Las filas se generan por JavaScript en casi todas las páginas, así que
 * se vuelve a etiquetar cada vez que cambia el contenido del documento.
 * Una tabla con el atributo data-sin-cards se deja como tabla normal.
 */
(function () {
    function etiquetasDeEncabezado(tabla) {
        const filas = tabla.tHead ? tabla.tHead.rows : null;
        if (!filas || !filas.length) return null;

        // La última fila del encabezado es la que nombra cada columna
        const etiquetas = [];
        Array.from(filas[filas.length - 1].cells).forEach(th => {
            const texto = th.textContent.replace(/\s+/g, ' ').trim();
            for (let i = 0; i < (th.colSpan || 1); i++) etiquetas.push(texto);
        });
        return etiquetas.some(Boolean) ? etiquetas : null;
    }

    function prepararTabla(tabla) {
        if (tabla.hasAttribute('data-sin-cards')) return;

        const etiquetas = etiquetasDeEncabezado(tabla);
        if (!etiquetas) return;

        tabla.classList.add('tabla-cards');

        Array.from(tabla.tBodies).forEach(tbody => {
            Array.from(tbody.rows).forEach(fila => {
                let columna = 0;
                Array.from(fila.cells).forEach(celda => {
                    const etiqueta = celda.colSpan > 1 ? '' : (etiquetas[columna] || '');
                    if (celda.getAttribute('data-label') !== etiqueta) {
                        celda.setAttribute('data-label', etiqueta);
                    }
                    columna += celda.colSpan || 1;
                });
            });
        });
    }

    function prepararTodas() {
        document.querySelectorAll('table').forEach(prepararTabla);
    }

    let pendiente = false;
    function programar() {
        if (pendiente) return;
        pendiente = true;
        requestAnimationFrame(() => {
            pendiente = false;
            prepararTodas();
        });
    }

    document.addEventListener('DOMContentLoaded', () => {
        prepararTodas();
        new MutationObserver(programar).observe(document.body, { childList: true, subtree: true });
    });

    // Etiqueta de color para el estatus de una incidencia (clases .etiqueta-* de styles.css)
    const CLASE_ESTATUS = {
        'abierto': 'etiqueta-info',
        'asignado': 'etiqueta-morado',
        'pendiente': 'etiqueta-alerta',
        'programado': 'etiqueta-info',
        'completado': 'etiqueta-ok',
        'cerrado con factura': 'etiqueta-neutral',
        'cerrado sin factura': 'etiqueta-neutral'
    };

    function estatusHTML(estatus) {
        const texto = String(estatus ?? '').trim();
        if (!texto) return '';
        const clase = CLASE_ESTATUS[texto.toLowerCase()] || 'etiqueta-neutral';
        const seguro = texto.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
        return `<span class="etiqueta ${clase}">${seguro}</span>`;
    }

    window.SipconsTablas = { actualizar: prepararTodas, estatusHTML };
})();
