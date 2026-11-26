<?php
// Expone la identidad de la instancia al frontend como window.APP_BRAND y la
// aplica a la página: título, favicon, logos ([data-brand-logo]), textos
// ([data-brand="nombre|eslogan|empresa|pie_login"]) y título de la barra lateral.
// Se carga de forma síncrona en el <head>, después del <title>.
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';   // sesión: marca de la empresa del usuario
require_once __DIR__ . '/../config/branding.php';
require_once __DIR__ . '/../config/demo.php';
require_once __DIR__ . '/../config/plataforma.php';

header('Content-Type: application/javascript; charset=utf-8');
// Contenido distinto por empresa: nunca en cachés compartidas (CDN de Hostinger)
header('Cache-Control: private, no-store');
header('Vary: Cookie');

$b = brandConfig();
$publico = [
    'nombre'    => $b['nombre'],
    'eslogan'   => $b['eslogan'],
    'empresa'   => $b['empresa'],
    'logo'      => $b['logo'],
    // Sesión dentro de una empresa cliente: su nombre (y su logo, si lo subió)
    // van en el pie de la barra lateral, junto al usuario
    'cliente'   => EmpresaContexto::$id !== null,
    'logo_propio' => EmpresaContexto::$id !== null && $b['logo'] !== env('APP_LOGO', 'img/logo.svg'),
    'favicon'   => $b['favicon'],
    'pie_login' => $b['pie_login'],
    'aviso_privacidad' => $b['aviso_privacidad'],
    'terminos' => $b['terminos'] ?? '',
    // Registro autoservicio de pruebas abierto (lo usa auth/registro.html)
    'registro'  => EmpresaContexto::$id === null && registroAbierto(),
    'categorias'=> $b['categorias'],
    // Solo dentro de la empresa demo (aviso "Modo demostración"). El login de
    // clientes nunca recibe las credenciales: las pide auth/demo.php solo en /demo.
    'demo'      => isLoggedIn() && enEmpresaDemo() ? demoCredencialesPublicas() : null,
    'colores'   => $b['colores'],
    // Meta de cumplimiento de la empresa (días para cerrar una incidencia)
    'sla_dias'  => EmpresaContexto::$id !== null ? slaDiasEmpresa() : SLA_DIAS_DEFECTO,
    // S-H07: el "hoy" y la zona de la empresa; el navegador puede estar en otra zona
    'hoy'       => date('Y-m-d'),
    'zona_horaria' => date_default_timezone_get(),
];
?>
window.APP_BRAND = <?= json_encode($publico, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) ?>;
(function () {
    var b = window.APP_BRAND;
    // S-H07: fecha AAAA-MM-DD de la empresa, desplazada N días (no depende de la zona del navegador)
    window.appFechaEmpresa = function (dias) {
        var p = String(b.hoy || '').split('-');
        var d = p.length === 3 ? new Date(Date.UTC(+p[0], +p[1] - 1, +p[2])) : new Date();
        if (p.length !== 3) return d.toISOString().slice(0, 10);
        d.setUTCDate(d.getUTCDate() + (Number(dias) || 0));
        return d.toISOString().slice(0, 10);
    };
    var src = (document.currentScript && document.currentScript.src) || '';
    b.raiz = src.replace(/branding\/marca\.js\.php.*$/, '');

    function url(ruta) {
        if (!ruta) return '';
        return /^(https?:)?\/\//.test(ruta) || ruta.charAt(0) === '/' ? ruta : b.raiz + ruta;
    }
    b.logoUrl = url(b.logo);

    // Título de la pestaña: "<página> · <marca>". El título original se guarda
    // para la barra móvil (sidebar-state.js), donde el nombre de la marca sobra.
    b.tituloPagina = (document.title || '').trim();
    if (b.tituloPagina.indexOf(b.nombre) === -1) {
        document.title = b.tituloPagina ? b.tituloPagina + ' · ' + b.nombre : b.nombre;
    }

    if (b.favicon) {
        var links = document.querySelectorAll('link[rel~="icon"], link[rel="apple-touch-icon"]');
        Array.prototype.forEach.call(links, function (l) { l.href = url(b.favicon); });
    }

    // Llena un <select> con las categorías de orden de servicio de la instancia. Un valor
    // guardado que ya no está en la lista se conserva como opción extra.
    b.llenarCategorias = function (select, seleccionado, placeholder) {
        if (!select) return;
        var valor = (seleccionado || '').trim();
        var lista = b.categorias.slice();
        if (valor && lista.indexOf(valor) === -1) lista.push(valor);
        select.innerHTML = '';
        var vacia = document.createElement('option');
        vacia.value = '';
        vacia.textContent = placeholder || 'Seleccione una categoría';
        select.appendChild(vacia);
        lista.forEach(function (c) {
            var o = document.createElement('option');
            o.value = c;
            o.textContent = c;
            if (c === valor) o.selected = true;
            select.appendChild(o);
        });
    };

    // Aviso flotante en las páginas internas cuando la instancia es la demo
    function avisoDemo() {
        if (!b.demo || document.getElementById('aviso-demo') || /\/auth\//.test(location.pathname)) return;
        var aviso = document.createElement('div');
        aviso.id = 'aviso-demo';
        aviso.className = 'aviso-demo';
        aviso.setAttribute('role', 'status');
        aviso.innerHTML = '<i class="fas fa-flask"></i> <strong>Modo demostración</strong>' +
            '<span> · Los datos se restablecen todos los días a las ' + b.demo.reinicio + ' h</span>';
        document.body.appendChild(aviso);
        // Controles que el servidor rechaza en la demo: se deshabilitan con su explicación
        var motivo = 'No disponible en la cuenta de demostración';
        ['form-cambiar-password', 'btn-guardar-privilegios'].forEach(function (id) {
            var el = document.getElementById(id);
            if (!el) return;
            var controles = el.tagName === 'FORM' ? el.querySelectorAll('input, button') : [el];
            Array.prototype.forEach.call(controles, function (c) { c.disabled = true; c.title = motivo; });
            var nota = document.createElement('p');
            nota.className = 'nota-demo';
            nota.innerHTML = '<i class="fas fa-lock"></i> ' + motivo + '.';
            el.parentNode.insertBefore(nota, el.nextSibling);
        });
    }

    function aplicar() {
        avisoDemo();
        Array.prototype.forEach.call(document.querySelectorAll('[data-brand]'), function (el) {
            var valor = b[el.getAttribute('data-brand')];
            if (typeof valor === 'string') el.textContent = valor;
        });
        Array.prototype.forEach.call(document.querySelectorAll('img[data-brand-logo]'), function (img) {
            img.src = b.logoUrl;
            img.alt = b.nombre;
        });
        // El encabezado de la barra lateral decía "Panel": muestra el sistema.
        Array.prototype.forEach.call(document.querySelectorAll('.sidebar-title'), function (h) {
            if (h.querySelector('.sidebar-brand-text')) return;
            Array.prototype.slice.call(h.childNodes).forEach(function (n) {
                if (n.nodeType === 3 && n.textContent.trim()) h.removeChild(n);
            });
            var span = document.createElement('span');
            span.className = 'sidebar-brand-text';
            span.textContent = b.nombre;
            h.appendChild(span);
        });
        // Pie: usuario y, debajo, la empresa cliente (con su logo si lo subió)
        if (b.cliente) {
            Array.prototype.forEach.call(document.querySelectorAll('.sidebar .user-info'), function (u) {
                var nombre = u.querySelector('.user-name');
                if (!nombre || u.querySelector('.user-empresa')) return;
                var datos = document.createElement('div');
                datos.className = 'user-datos';
                u.insertBefore(datos, nombre);
                datos.appendChild(nombre);
                var emp = document.createElement('span');
                emp.className = 'user-empresa';
                emp.textContent = b.empresa;
                emp.title = b.empresa;
                datos.appendChild(emp);
                if (b.logo_propio) {
                    var icono = u.querySelector('i');
                    var img = document.createElement('img');
                    img.className = 'user-logo';
                    img.src = b.logoUrl;
                    img.alt = '';
                    if (icono) u.replaceChild(img, icono);
                }
            });
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', aplicar);
    } else {
        aplicar();
    }
})();

// Campos de fecha y hora: un clic en cualquier parte del campo abre el
// calendario (antes solo el icono). Vale para todas las páginas, también las
// que se pintan después (modales, formularios dinámicos).
(function () {
    var TIPOS = { date: 1, 'datetime-local': 1, month: 1, week: 1, time: 1 };
    document.addEventListener('click', function (e) {
        var campo = e.target;
        if (!campo || campo.tagName !== 'INPUT' || !TIPOS[campo.type] || campo.disabled || campo.readOnly) return;
        if (typeof campo.showPicker !== 'function') return;
        try { campo.showPicker(); } catch (err) { /* el navegador ya lo abrió o no lo permite */ }
    });
})();
