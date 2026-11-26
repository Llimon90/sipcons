// Sitio de ventas: completa precios, días de prueba y contacto con lo que
// está configurado en el panel de plataforma (soluciones/info.php). Sin
// respuesta, la página sigue funcionando con los textos de respaldo.
(function () {
    'use strict';

    function esc(v) {
        return String(v == null ? '' : v).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }
    function pesos(n) {
        return '$' + Number(n).toLocaleString('es-MX', { maximumFractionDigits: 0 });
    }
    function todos(sel, fn) { document.querySelectorAll(sel).forEach(fn); }

    var anio = document.getElementById('anio');
    if (anio) anio.textContent = new Date().getFullYear();

    function pintarPlanes(planes, dias) {
        var cont = document.getElementById('planes');
        if (!cont || !planes.length) return;
        var medio = planes.length >= 3 ? 1 : -1;
        cont.innerHTML = planes.map(function (p, i) {
            var usuarios = p.max_usuarios ? 'Hasta ' + p.max_usuarios + ' usuarios' : 'Usuarios sin límite';
            var ahorro = p.precio_anual > 0 && p.precio_anual < p.precio_mensual * 12
                ? 'O ' + pesos(p.precio_anual) + ' al año: ahorras ' + pesos(p.precio_mensual * 12 - p.precio_anual) : '';
            return '<div class="plan' + (i === medio ? ' destacado' : '') + '">' +
                '<h3>' + esc(p.nombre) + '</h3>' +
                '<p style="color:var(--suave);margin:0;">' + esc(p.descripcion || '') + '</p>' +
                '<p class="precio">' + pesos(p.precio_mensual) + ' <small>MXN al mes</small></p>' +
                '<p class="anual">' + esc(ahorro) + '</p>' +
                '<ul><li>' + usuarios + '</li><li>Todos los módulos incluidos</li><li>Tu logo y tus colores</li><li>Soporte por WhatsApp</li></ul>' +
                '<a class="boton ' + (i === medio ? 'boton-primario' : 'boton-secundario') + '" href="../auth/registro.html">Probar ' + dias + ' días gratis</a>' +
                '</div>';
        }).join('');
    }

    fetch('info.php', { credentials: 'same-origin' })
        .then(function (r) { return r.ok ? r.json() : null; })
        .then(function (d) {
            if (!d) return;
            var dias = d.dias_prueba || 15;
            todos('[data-dias]', function (el) { el.textContent = dias; });
            pintarPlanes(d.planes || [], dias);
            // Sin registro abierto, "Prueba gratis" lleva a WhatsApp o al correo
            if (!d.registro) {
                todos('a[href$="auth/registro.html"]', function (a) {
                    a.href = d.whatsapp ? 'https://wa.me/52' + d.whatsapp.slice(-10) : (d.correo ? 'mailto:' + d.correo : '#contacto');
                    a.textContent = 'Solicitar acceso';
                });
            }
            if (!d.demo) todos('[data-demo]', function (el) { el.hidden = true; });
            if (d.whatsapp) {
                var wa = 'https://wa.me/52' + d.whatsapp.slice(-10) + '?text=' + encodeURIComponent('Hola, me interesa Servidesk para mi empresa.');
                todos('[data-whatsapp]', function (a) { a.href = wa; a.hidden = false; });
            }
            if (d.correo) todos('[data-correo]', function (a) { a.href = 'mailto:' + d.correo; a.textContent = d.correo; a.hidden = false; });
            if (d.aviso) todos('[data-aviso]', function (a) { a.href = d.aviso; a.hidden = false; });
        })
        .catch(function () { /* se quedan los textos de respaldo */ });
})();
