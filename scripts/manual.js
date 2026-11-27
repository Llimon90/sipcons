// Manual de usuario (public/manual.html): índice lateral, buscador de temas,
// sección activa al desplazarse y descarga en PDF (impresión del navegador
// con el diseño de css/manual.css @media print, con la marca de la empresa).
(function () {
    'use strict';

    var secciones = Array.prototype.slice.call(document.querySelectorAll('.mn-contenido > section[id]'));
    var indice = document.getElementById('mn-indice');

    function normalizar(t) {
        return (t || '').toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '');
    }

    // Índice
    indice.innerHTML = secciones.map(function (s, i) {
        return '<li><a href="#' + s.id + '" data-seccion="' + s.id + '"><span>' + (i + 1) + '</span>' + s.getAttribute('data-titulo') + '</a></li>';
    }).join('');
    var enlaces = Array.prototype.slice.call(indice.querySelectorAll('a'));

    // Sección activa al desplazarse
    if ('IntersectionObserver' in window) {
        var visibles = {};
        var observador = new IntersectionObserver(function (entradas) {
            entradas.forEach(function (e) { visibles[e.target.id] = e.isIntersecting; });
            var actual = secciones.filter(function (s) { return visibles[s.id] && !s.hidden; })[0];
            if (!actual) return;
            enlaces.forEach(function (a) { a.classList.toggle('activo', a.getAttribute('data-seccion') === actual.id); });
        }, { rootMargin: '-15% 0px -70% 0px' });
        secciones.forEach(function (s) { observador.observe(s); });
    }

    // Buscador: muestra solo las secciones que contienen todas las palabras
    var buscador = document.getElementById('mn-buscar');
    var sinResultados = document.getElementById('mn-sin-resultados');
    var textos = secciones.map(function (s) { return normalizar(s.textContent); });
    buscador.addEventListener('input', function () {
        var palabras = normalizar(buscador.value).split(/\s+/).filter(Boolean);
        var hay = 0;
        secciones.forEach(function (s, i) {
            var coincide = palabras.every(function (p) { return textos[i].indexOf(p) !== -1; });
            s.hidden = !coincide;
            enlaces[i].parentNode.hidden = !coincide;
            if (coincide) hay++;
            // Abre las preguntas que contienen la búsqueda
            if (palabras.length) {
                s.querySelectorAll('details').forEach(function (d) {
                    d.open = palabras.every(function (p) { return normalizar(d.textContent).indexOf(p) !== -1; });
                });
            }
        });
        sinResultados.hidden = hay > 0;
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === '/' && document.activeElement === document.body) { e.preventDefault(); buscador.focus(); }
    });

    // PDF: se imprimen todas las secciones con las preguntas abiertas
    document.getElementById('mn-fecha-impresion').textContent =
        new Date().toLocaleDateString('es-MX', { day: 'numeric', month: 'long', year: 'numeric' });
    var abiertas = [];
    window.addEventListener('beforeprint', function () {
        secciones.forEach(function (s, i) { s.hidden = false; enlaces[i].parentNode.hidden = false; });
        abiertas = Array.prototype.filter.call(document.querySelectorAll('.mn-faq'), function (d) { return !d.open; });
        abiertas.forEach(function (d) { d.open = true; });
    });
    window.addEventListener('afterprint', function () {
        abiertas.forEach(function (d) { d.open = false; });
        buscador.dispatchEvent(new Event('input'));
    });
    document.getElementById('mn-pdf').addEventListener('click', function () {
        var titulo = document.title;
        var marca = (window.APP_BRAND && window.APP_BRAND.nombre) || 'Sistema';
        document.title = 'Manual de usuario - ' + marca; // nombre sugerido del archivo PDF
        window.print();
        setTimeout(function () { document.title = titulo; }, 1000);
    });
})();
