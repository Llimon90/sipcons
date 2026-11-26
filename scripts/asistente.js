/*
 * Asistente guiado por rol (docs/REGLAS_NEGOCIO.md D-20).
 * Recorrido en pasos sobre Inicio y el menú real: resalta cada parte y explica para qué
 * sirve, distinto por rol. Se abre solo la primera vez; se puede cerrar en cualquier
 * paso y volver a abrir en Ayuda -> Recorrido guiado (index.html?recorrido=1).
 * El progreso se guarda en la cuenta del usuario (backend/asistente.php), no en el navegador.
 * Sin librerías externas.
 */
(function () {
    'use strict';

    var enPublic = /\/public\//.test(window.location.pathname);
    var raiz = enPublic ? '../' : '';
    var API = raiz + 'backend/asistente.php';

    function menu(pagina) { return '.sidebar .menu-lateral a[href$="' + pagina + '"]'; }

    var BIENVENIDA = { titulo: 'Bienvenido a tu sistema de servicio', texto: 'Te mostramos lo esencial en un minuto. Puedes cerrar el recorrido cuando quieras y volver a verlo en <b>Ayuda → Recorrido guiado</b>.' };
    var FINAL = { titulo: '¡Listo!', texto: 'Ya conoces lo principal. Si necesitas repasarlo, abre <b>Ayuda → Recorrido guiado</b>. El <b>Manual de usuario</b> explica cada módulo a detalle.' };

    var P = {
        bandejaTecnico: { sel: '#ini-contenido .ini-columnas > .ini-tarjeta', titulo: 'Tus órdenes de servicio', texto: 'Aquí aparecen las órdenes donde estás asignado, lo más antiguo y urgente arriba. Toca una para abrirla.' },
        bandeja: { sel: '#ini-bandeja', titulo: 'Bandeja de trabajo', texto: 'Las órdenes activas, con lo urgente arriba: sin técnico o fuera de la meta de días. Los filtros de arriba te ayudan a encontrar lo que necesita atención.' },
        indicadores: { sel: '.ini-kpi', titulo: 'Indicadores del día', texto: 'Abiertas, asignadas, pendientes, atrasadas y por cerrar. Toca un indicador para ver esas órdenes en la bandeja.' },
        ordenesTecnico: { sel: menu('incidencias.html'), titulo: 'Órdenes de servicio', texto: 'Al abrir una orden puedes cambiar su estatus (<b>En proceso</b>, <b>Pendiente</b> con su motivo o <b>Completado</b>), escribir el trabajo realizado y adjuntar fotos.' },
        refacciones: { sel: menu('incidencias.html'), titulo: 'Conceptos aplicados', texto: 'Dentro de la orden, en <b>Conceptos aplicados</b>, registras las refacciones (se descuentan del inventario), los servicios y mano de obra, y los equipos de reemplazo (quedan en el padrón del cliente).' },
        metricas: { sel: menu('dashboard-tecnico.html'), titulo: 'Mis métricas', texto: 'Tu tiempo de atención, lo que tienes pendiente y lo que ya pasó de la meta.' },
        manuales: { sel: menu('soporte.html'), titulo: 'Soporte técnico', texto: 'Manuales y listas de partes por marca y modelo, para consultarlos en sitio.' },
        ordenes: { sel: menu('incidencias.html'), titulo: 'Órdenes de servicio', texto: 'Registra órdenes nuevas y dales seguimiento. Al asignar un técnico la orden pasa sola a <b>Asignado</b>; cerrar o cancelar pide permiso de cierre.' },
        asignar: { sel: menu('incidencias.html'), titulo: 'Asignar y dar seguimiento', texto: 'Dentro de cada orden asignas uno o varios técnicos. Si una orden se queda sin técnicos regresa a <b>Abierto</b>.' },
        buscar: { sel: menu('reportes.html'), titulo: 'Buscar órdenes de servicio', texto: 'Busca por cliente, sucursal, técnico, estatus o fechas, incluidos los mantenimientos programados del padrón.' },
        cotizaciones: { sel: menu('cotizaciones.html'), titulo: 'Cotizaciones', texto: 'Prepara cotizaciones desde el catálogo, envíalas y márcalas aprobadas. Una aprobada puede convertirse en venta y en cuenta por cobrar.' },
        finanzas: { sel: menu('finanzas.html'), titulo: 'Finanzas', texto: 'Cuentas por cobrar, cobros (con su comprobante, que puedes adjuntar después) y gastos ligados a las órdenes.' },
        clientes: { sel: menu('clientes.html'), titulo: 'Clientes', texto: 'El catálogo de clientes, sus sucursales, contactos y el padrón de equipos. Una orden siempre usa un cliente del catálogo.' },
        informes: { sel: menu('informes.html'), titulo: 'Estadísticas', texto: 'Cumplimiento de la meta de días, tiempos de atención, carga por técnico y reincidencias.' },
        usuarios: { sel: menu('usuarios.html'), titulo: 'Usuarios', texto: 'Da de alta a tu equipo, elige su rol y si puede cerrar órdenes o autorizar descuentos.' },
        empresa: { sel: menu('mi-empresa.html'), titulo: 'Mi empresa', texto: 'Tu marca, la meta de días para cerrar, la zona horaria y tu plan.' },
        historial: { sel: menu('historial.html'), titulo: 'Historial', texto: 'Quién hizo cada cambio y cuándo, con el antes y el después.' }
    };

    var RECORRIDOS = {
        'Técnico': [BIENVENIDA, P.bandejaTecnico, P.ordenesTecnico, P.refacciones, P.metricas, P.manuales, FINAL],
        'Administrativo': [BIENVENIDA, P.bandeja, P.ordenes, P.cotizaciones, P.finanzas, P.clientes, FINAL],
        'Supervisor': [BIENVENIDA, P.indicadores, P.bandeja, P.asignar, P.buscar, P.informes, FINAL],
        'Administrador': [BIENVENIDA, P.indicadores, P.bandeja, P.ordenes, P.cotizaciones, P.finanzas, P.informes, P.usuarios, P.empresa, P.historial, FINAL]
    };

    // ------------------------------------------------------------ estado en el servidor
    function guardar(estado) {
        return fetch(API, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ estado: estado }) })
            .catch(function () {});
    }

    // ------------------------------------------------------------ recorrido
    var capa, marco, tarjeta, pasos = [], actual = 0;

    /** El módulo existe para este rol (auth-guard oculta lo no permitido). */
    function permitido(el) {
        if (!el) return false;
        var li = el.closest('li') || el;
        return !el.closest('[hidden]') && getComputedStyle(li).display !== 'none';
    }
    function visible(el) {
        if (!el || el.offsetParent === null) return false;
        var r = el.getBoundingClientRect();
        return r.width > 0 && r.height > 0;
    }

    var focoPrevio = null;
    function construir() {
        focoPrevio = document.activeElement;  // U-9: al cerrar, el foco regresa donde estaba
        capa = document.createElement('div');
        capa.className = 'asis-capa';
        marco = document.createElement('div');
        marco.className = 'asis-marco';
        tarjeta = document.createElement('div');
        tarjeta.className = 'asis-tarjeta';
        tarjeta.setAttribute('role', 'dialog');
        tarjeta.setAttribute('aria-modal', 'true');
        tarjeta.setAttribute('aria-labelledby', 'asis-titulo');
        document.body.appendChild(capa);
        document.body.appendChild(marco);
        document.body.appendChild(tarjeta);
        tarjeta.addEventListener('click', function (e) {
            var b = e.target.closest('[data-asis]');
            if (!b) return;
            var accion = b.getAttribute('data-asis');
            if (accion === 'cerrar') terminar('cerrado');
            else if (accion === 'anterior') mostrar(actual - 1);
            else if (actual >= pasos.length - 1) terminar('terminado');
            else mostrar(actual + 1);
        });
        document.addEventListener('keydown', teclado);
        window.addEventListener('resize', reubicar);
        window.addEventListener('scroll', reubicar, true);
    }

    function teclado(e) {
        if (!tarjeta) return;
        if (e.key === 'Tab') {
            // U-9: el foco no sale del diálogo (aria-modal) mientras está abierto
            var botones = Array.prototype.slice.call(tarjeta.querySelectorAll('button'));
            if (!botones.length) return;
            var i = botones.indexOf(document.activeElement);
            if (e.shiftKey && i <= 0) { e.preventDefault(); botones[botones.length - 1].focus(); }
            else if (!e.shiftKey && (i === -1 || i === botones.length - 1)) { e.preventDefault(); botones[0].focus(); }
            return;
        }
        if (e.key === 'Escape') terminar('cerrado');
        else if (e.key === 'ArrowRight') tarjeta.querySelector('[data-asis="siguiente"]').click();
        else if (e.key === 'ArrowLeft' && actual > 0) mostrar(actual - 1);
    }

    function mostrar(i) {
        actual = Math.max(0, Math.min(i, pasos.length - 1));
        var p = pasos[actual];
        var ultimo = actual === pasos.length - 1;
        tarjeta.innerHTML =
            '<p class="asis-contador">Paso ' + (actual + 1) + ' de ' + pasos.length + '</p>' +
            '<h3 id="asis-titulo">' + p.titulo + '</h3><p class="asis-texto">' + p.texto + '</p>' +
            '<div class="asis-botones">' +
            '<button type="button" class="asis-cerrar" data-asis="cerrar">Cerrar recorrido</button>' +
            (actual > 0 ? '<button type="button" class="asis-sec" data-asis="anterior">Anterior</button>' : '') +
            '<button type="button" class="asis-pri" data-asis="siguiente">' + (ultimo ? 'Terminar' : 'Siguiente') + '</button></div>';
        var el = p.sel ? document.querySelector(p.sel) : null;
        if (el && visible(el)) el.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
        reubicar();
        tarjeta.querySelector('[data-asis="siguiente"]').focus();
    }

    function reubicar() {
        if (!tarjeta) return;
        var p = pasos[actual];
        var el = p && p.sel ? document.querySelector(p.sel) : null;
        if (!el || !visible(el)) {
            marco.hidden = true;
            tarjeta.classList.add('asis-centro');
            tarjeta.style.top = tarjeta.style.left = '';
            return;
        }
        var r = el.getBoundingClientRect(), m = 6;
        marco.hidden = false;
        marco.style.top = (r.top - m) + 'px';
        marco.style.left = (r.left - m) + 'px';
        marco.style.width = (r.width + m * 2) + 'px';
        marco.style.height = (r.height + m * 2) + 'px';
        tarjeta.classList.remove('asis-centro');
        var ancho = tarjeta.offsetWidth, alto = tarjeta.offsetHeight, vw = window.innerWidth, vh = window.innerHeight;
        var left = r.right + 16 + ancho <= vw ? r.right + 16 : Math.max(12, Math.min(r.left, vw - ancho - 12));
        var top = r.right + 16 + ancho <= vw ? r.top : (r.bottom + 16 + alto <= vh ? r.bottom + 16 : Math.max(12, r.top - alto - 16));
        tarjeta.style.left = left + 'px';
        tarjeta.style.top = Math.max(12, Math.min(top, vh - alto - 12)) + 'px';
    }

    function terminar(estado) {
        guardar(estado);
        document.removeEventListener('keydown', teclado);
        window.removeEventListener('resize', reubicar);
        window.removeEventListener('scroll', reubicar, true);
        [capa, marco, tarjeta].forEach(function (n) { if (n && n.parentNode) n.parentNode.removeChild(n); });
        capa = marco = tarjeta = null;
        if (focoPrevio && focoPrevio.focus && document.contains(focoPrevio)) { try { focoPrevio.focus(); } catch (e) { /* nada */ } }
        if (/[?&]recorrido=1/.test(window.location.search) && window.history.replaceState) {
            window.history.replaceState(null, '', window.location.pathname);
        }
    }

    function iniciar(rol) {
        if (tarjeta) return;
        var lista = RECORRIDOS[rol] || RECORRIDOS['Técnico'];
        pasos = lista.filter(function (p) { return !p.sel || permitido(document.querySelector(p.sel)); });
        if (pasos.length < 3) return;
        construir();
        mostrar(0);
    }

    // ------------------------------------------------------------ arranque
    window.AppAsistente = { iniciar: iniciar };

    function arrancar() {
        var pedido = /[?&]recorrido=1/.test(window.location.search);
        fetch(API, { credentials: 'same-origin', headers: { Accept: 'application/json' } })
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(function (j) {
                if (!j || !j.exito) return;
                // Se espera a que Inicio pinte su bandeja para poder resaltarla
                if (pedido || j.estado === 'nuevo') setTimeout(function () { iniciar(j.rol); }, 900);
            }).catch(function () {});
    }

    if (window.appOnAuthReady) window.appOnAuthReady(function () { arrancar(); });
    else document.addEventListener('DOMContentLoaded', arrancar);
})();
