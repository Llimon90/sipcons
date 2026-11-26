// Sección "Cotizaciones" del detalle de incidencia (public/detalle.html):
// lista las cotizaciones ligadas y crea borradores con el cliente, la sucursal
// y el contacto de la incidencia (también el técnico):
//  - «Cotizar» en blanco, para cotizar antes de hacer el trabajo.
//  - «Cotizar lo utilizado», cuando el trabajo ya se hizo (o se hizo antes de
//    la autorización): trae las refacciones registradas, ya instaladas.
(function () {
    var C = window.AppCotizaciones;
    var existentes = 0;

    function $(id) { return document.getElementById(id); }

    function cargar(incidenciaId) {
        return C.api('listar', { incidencia_id: incidenciaId }).then(function (j) {
            existentes = j.cotizaciones.length;
            $('cotizaciones-incidencia').innerHTML = j.cotizaciones.length
                ? C.tablaCompacta(j.cotizaciones)
                : '<p class="texto-suave">Aún no hay cotizaciones para esta orden de servicio. Usa «Cotizar» para preparar refacciones y mano de obra antes del trabajo, o «Cotizar lo aplicado» si ya se hizo el trabajo y el cliente pide la cotización para autorizarla.</p>';
        });
    }

    function crear(id, boton, conRefacciones) {
        var previo = existentes
            ? AppAviso.confirmar('Esta orden de servicio ya tiene ' + (existentes === 1 ? 'una cotización' : existentes + ' cotizaciones') + '. Si el cliente pidió cambios, conviene abrirla y crear una nueva versión.', { titulo: '¿Crear otra cotización?', boton: 'Crear otra' })
            : Promise.resolve(true);
        return previo.then(function (ok) {
            if (!ok) return;
            boton.disabled = true;
            return C.api('crear_desde_incidencia', null, { incidencia_id: id, incluir_refacciones: conRefacciones }).then(function (j) {
                if (conRefacciones) AppAviso.mostrar('Borrador listo con las refacciones utilizadas. Agrega la mano de obra o el servicio desde el catálogo.', 'ok');
                location.href = 'cotizacion.html?id=' + j.cotizacion.id;
            });
        }).catch(function (e) {
            boton.disabled = false;
            AppAviso.mostrar(e.message, 'error');
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        var seccion = $('seccion-cotizaciones');
        var id = Number(new URLSearchParams(location.search).get('id'));
        if (!seccion || !id) return;

        cargar(id).then(function () {
            seccion.hidden = false;
        }).catch(function (e) {
            // Sin acceso al módulo ni a incidencias, o sin la migración: no se muestra
            if (e.status !== 403 && e.status !== 500) { seccion.hidden = false; $('cotizaciones-incidencia').textContent = e.message; }
        });

        $('btn-cotizar').addEventListener('click', function () { crear(id, this, false); });
        $('btn-cotizar-utilizado').addEventListener('click', function () { crear(id, this, true); });
        document.addEventListener('refacciones:cambio', function (e) {
            var b = $('btn-cotizar-utilizado');
            b.hidden = !e.detail.total;
            b.querySelector('span').textContent = 'Cotizar lo aplicado (' + e.detail.total + ')';
        });

        $('cotizaciones-incidencia').addEventListener('click', function (e) {
            var fila = e.target.closest('[data-href]');
            if (fila && !e.target.closest('a')) location.href = fila.getAttribute('data-href');
        });
    });
})();
