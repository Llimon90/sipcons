// Sección "Cotizaciones" del perfil del cliente (public/perfil-cliente.html):
// historial de cotizaciones del cliente y acceso a una nueva para él.
(function () {
    var C = window.AppCotizaciones;

    document.addEventListener('DOMContentLoaded', function () {
        var seccion = document.getElementById('seccion-cotizaciones');
        var id = Number(new URLSearchParams(location.search).get('id'));
        if (!seccion || !id) return;
        document.getElementById('btn-cotizar-cliente').href = 'cotizacion.html?cliente_id=' + id;

        C.api('listar', { cliente_id: id, todas_versiones: 1 }).then(function (j) {
            seccion.hidden = false;
            var aprobado = j.cotizaciones.filter(function (c) { return c.estatus === 'aprobada' || c.estatus === 'convertida'; });
            document.getElementById('cotizaciones-cliente').innerHTML =
                (j.cotizaciones.length ? '<p class="texto-suave">' + j.cotizaciones.length + ' cotizaciones · ' + aprobado.length + ' aprobadas</p>' : '') +
                C.tablaCompacta(j.cotizaciones);
        }).catch(function () { /* sin acceso al módulo: la sección no se muestra */ });

        document.getElementById('cotizaciones-cliente').addEventListener('click', function (e) {
            var fila = e.target.closest('[data-href]');
            if (fila && !e.target.closest('a')) location.href = fila.getAttribute('data-href');
        });
    });
})();
