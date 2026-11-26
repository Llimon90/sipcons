// Inicio (index.html): centro de operaciones para quien coordina y "Mi día"
// para el técnico. Los datos salen de backend/inicio.php.
(function () {
    'use strict';

    var datos = null;
    var filtro = 'urgentes';
    var MAX_FILAS = 12;

    function $(id) { return document.getElementById(id); }
    function esc(v) {
        return String(v == null ? '' : v).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }
    function numero(n) { return (parseInt(n, 10) || 0).toLocaleString('es-MX'); }
    // N-H11: los minutos los calcula el servidor (la hora guardada es de la zona de la empresa)
    function hace(fecha, minutos) {
        var d = new Date(String(fecha).replace(' ', 'T'));
        if (isNaN(d)) return '';
        var min = minutos != null ? Number(minutos) : Math.round((Date.now() - d.getTime()) / 60000);
        if (min < 1) return 'ahora';
        if (min < 60) return 'hace ' + min + ' min';
        if (min < 1440) return 'hace ' + Math.round(min / 60) + ' h';
        return d.toLocaleDateString('es-MX', { day: 'numeric', month: 'short' });
    }
    function dias(n) { return n === 0 ? 'hoy' : n === 1 ? '1 día' : n + ' días'; }

    var ESTATUS = {
        'Abierto': 'abierto', 'Programado': 'programado', 'Asignado': 'asignado', 'En proceso': 'en-proceso', 'Pendiente': 'pendiente', 'Completado': 'completado'
    };
    function pill(estatus) {
        return '<span class="ini-pill ' + (ESTATUS[estatus] || 'otro') + '">' + esc(estatus || 'Sin estatus') + '</span>';
    }

    // ------------------------------------------------------------ cabecera
    function cabecera(auth) {
        var hora = new Date().getHours();
        var saludo = hora < 12 ? 'Buenos días' : hora < 19 ? 'Buenas tardes' : 'Buenas noches';
        var nombre = ((auth.user && auth.user.nombre) || '').split(' ')[0];
        $('ini-saludo').textContent = saludo + (nombre ? ', ' + nombre : '');
        var hoy = new Date().toLocaleDateString('es-MX', { weekday: 'long', day: 'numeric', month: 'long' });
        $('ini-fecha').textContent = hoy.charAt(0).toUpperCase() + hoy.slice(1) +
            (window.APP_BRAND && APP_BRAND.cliente ? ' · ' + APP_BRAND.empresa : '');
        if (/Mac|iPhone|iPad/.test(navigator.platform || '')) $('ini-atajo').textContent = '⌘K';
        var modulos = auth.modulos || [];
        $('ini-nueva').hidden = modulos.indexOf('incidencias') === -1;
        $('ini-venta').hidden = modulos.indexOf('ventas') === -1 || (auth.user && auth.user.rol === 'Técnico');
        $('ini-cotizacion').hidden = modulos.indexOf('cotizaciones') === -1;  // O-10
        $('ini-articulo').hidden = modulos.indexOf('inventario') === -1;
    }

    // ------------------------------------------------------------ operación
    var FILTROS = {
        urgentes:    { nombre: 'Urgentes', prueba: function (f) { return f.estatus !== 'Completado' && (f.sin_tecnico || f.dias > datos.dias_atraso); }, vacio: 'Nada urgente: todo tiene técnico y va en tiempo.' },
        sin_tecnico: { nombre: 'Sin técnico', prueba: function (f) { return f.estatus !== 'Completado' && f.sin_tecnico; }, vacio: 'Todas las órdenes de servicio tienen técnico.' },
        abiertas:    { nombre: 'Abiertas', prueba: function (f) { return !f.estatus || f.estatus === 'Abierto' || f.estatus === 'Programado'; }, vacio: 'No hay órdenes de servicio abiertas.' },
        asignadas:   { nombre: 'Asignadas', prueba: function (f) { return f.estatus === 'Asignado' || f.estatus === 'En proceso'; }, vacio: 'No hay órdenes de servicio asignadas.' },
        pendientes:  { nombre: 'Pendientes', prueba: function (f) { return f.estatus === 'Pendiente'; }, vacio: 'No hay órdenes de servicio pendientes.' },
        atrasadas:   { nombre: 'Atrasadas', prueba: function (f) { return f.estatus !== 'Completado' && f.dias > datos.dias_atraso; }, vacio: 'Ninguna pasa de la meta de ' + ((window.APP_BRAND && APP_BRAND.sla_dias) || 7) + ' días.' },
        por_cerrar:  { nombre: 'Por cerrar hace más de 5 días', prueba: function (f) { return f.estatus === 'Completado' && f.dias_completada > 5; }, vacio: 'Ningún trabajo completado lleva más de 5 días sin cerrar.' },
        completadas: { nombre: 'Por cerrar', prueba: function (f) { return f.estatus === 'Completado'; }, vacio: 'No hay trabajos completados por cerrar.' },
        todas:       { nombre: 'Todas', prueba: function () { return true; }, vacio: 'No hay órdenes de servicio activas.' }
    };

    function kpi(clave, icono, etiqueta, valor, nota, clase) {
        return '<button type="button" class="ini-kpi ' + (clase || '') + (filtro === clave ? ' activo' : '') + '" data-filtro="' + clave + '">' +
            '<span class="ini-kpi-et"><i class="fas ' + icono + '"></i> ' + etiqueta + '</span>' +
            '<strong>' + numero(valor) + '</strong><small>' + nota + '</small></button>';
    }

    function pintarOperacion() {
        var k = datos.indicadores;
        var html = '<section class="ini-kpis" aria-label="Indicadores">' +
            kpi('abiertas', 'fa-folder-open', 'Abiertas', k.abiertas, k.hoy ? numero(k.hoy) + ' registradas hoy' : 'esperan atención', 'azul') +
            kpi('asignadas', 'fa-user-check', 'Asignadas', k.asignadas, 'con técnico', 'violeta') +
            kpi('pendientes', 'fa-pause-circle', 'Pendientes', k.pendientes, 'en espera', 'ambar') +
            kpi('atrasadas', 'fa-exclamation-triangle', 'Más de ' + datos.dias_atraso + ' días', k.atrasadas, 'sin cerrar', k.atrasadas ? 'rojo' : '') +
            kpi('completadas', 'fa-check-double', 'Por cerrar', k.completadas, 'completadas', 'verde') +
            '</section><div class="ini-columnas"><section class="ini-tarjeta ini-bandeja"><div id="ini-bandeja"></div></section><aside class="ini-lateral">';

        if (datos.avisos.length) {
            html += '<section class="ini-tarjeta"><h2>Requiere atención</h2><ul class="ini-avisos">' + datos.avisos.map(function (a) {
                var destino = a.filtro ? ' data-filtro="' + a.filtro + '"' : '';
                var tag = a.enlace ? 'a href="' + esc(a.enlace) + '"' : 'button type="button"' + destino;
                return '<li><' + tag + ' class="ini-aviso ' + esc(a.tipo) + '"><i></i><span><b>' + esc(a.titulo) + '</b><small>' + esc(a.detalle) + '</small></span>' +
                    '<em class="fas fa-chevron-right"></em></' + (a.enlace ? 'a' : 'button') + '></li>';
            }).join('') + '</ul></section>';
        } else {
            html += '<section class="ini-tarjeta ini-todo-bien"><i class="fas fa-circle-check"></i><div><b>Todo en orden</b><small>Nada atrasado ni sin técnico.</small></div></section>';
        }

        if (datos.carga.length || datos.carga_sin_tecnico) {
            var max = Math.max.apply(null, datos.carga.map(function (c) { return c.total; }).concat([datos.carga_sin_tecnico, 1]));
            html += '<section class="ini-tarjeta"><h2>Carga por técnico <small>órdenes de servicio sin cerrar</small></h2><div class="ini-carga">' +
                datos.carga.slice(0, 8).map(function (c) {
                    return '<div><span title="' + esc(c.tecnico) + '">' + esc(c.tecnico) + '</span><i><b style="width:' + Math.round(c.total * 100 / max) + '%"></b></i><em>' + c.total + '</em></div>';
                }).join('') +
                (datos.carga_sin_tecnico ? '<div class="sin"><span>Sin técnico</span><i><b style="width:' + Math.round(datos.carga_sin_tecnico * 100 / max) + '%"></b></i><em>' + datos.carga_sin_tecnico + '</em></div>' : '') +
                '</div></section>';
        }

        html += tarjetaProgramadas();
        if (datos.cambios && datos.cambios.length) {
            var verbo = { CREATE: 'registró', UPDATE: 'actualizó', DELETE: 'eliminó', ARCHIVO_ELIMINADO: 'quitó un archivo de', INVENTARIO: 'usó refacciones en' };
            var que = { incidencias: 'la orden de servicio', ventas: 'la venta', clientes: 'el cliente' };
            html += '<section class="ini-tarjeta"><h2>Últimos cambios <a href="public/historial.html">Historial</a></h2><ul class="ini-cambios">' +
                datos.cambios.map(function (c) {
                    return '<li><b>' + esc(c.usuario_nombre || 'Sistema') + '</b> ' + esc(verbo[c.accion] || c.accion) + ' ' + esc(que[c.tabla] || c.tabla) + ' ' +
                        esc(c.registro_folio || '#' + (c.registro_id || '')) + '<time>' + hace(c.creado_en, c.hace_min) + '</time></li>';
                }).join('') + '</ul></section>';
        }
        html += '</aside></div>';
        $('ini-contenido').innerHTML = html;
        pintarBandeja();
    }

    // D-42: órdenes con fecha futura; se vuelven activas solas el día indicado
    function tarjetaProgramadas() {
        if (!datos.programadas || !datos.programadas.length) return '';
        return '<section class="ini-tarjeta"><h2>Programadas <small>aparecen en la bandeja el día indicado</small></h2><ul class="ini-cambios">' +
            datos.programadas.map(function (r) {
                var f = String(r.fecha || '').split('-');
                return '<li><a href="public/detalle.html?id=' + r.id + '"><b>' + esc(f.length === 3 ? f[2] + '/' + f[1] : r.fecha) + '</b> · ' +
                    esc(r.numero_incidente || '#' + r.id) + ' · ' + esc(r.cliente || '') + '<time>' + esc(r.tecnico || 'Sin técnico') + '</time></a></li>';
            }).join('') + '</ul></section>';
    }

    function pintarBandeja() {
        var f = FILTROS[filtro] || FILTROS.urgentes;
        var lista = datos.bandeja.filter(f.prueba);
        var recorte = datos.bandeja_recortada ? '<p class="ini-mas">Se muestran ' + numero(datos.bandeja.length) + ' de ' + numero(datos.bandeja_recortada) + ' órdenes activas. <a href="public/reportes.html">Buscar más</a></p>' : '';
        var chips = ['urgentes', 'sin_tecnico', 'todas'];
        if (chips.indexOf(filtro) === -1) chips.push(filtro);
        var html = '<div class="ini-bandeja-cab"><h2>Bandeja de trabajo</h2><div class="ini-chips" role="group" aria-label="Filtro de la bandeja">' +
            chips.map(function (c) {
                var total = datos.bandeja.filter(FILTROS[c].prueba).length;
                return '<button type="button" class="ini-chip' + (c === filtro ? ' activo' : '') + '" data-filtro="' + c + '" aria-pressed="' + (c === filtro) + '">' +
                    FILTROS[c].nombre + ' <span>' + total + '</span></button>';
            }).join('') + '</div><a class="ini-ver" href="public/reportes.html">Buscar más <i class="fas fa-arrow-right"></i></a></div>';
        if (!lista.length) {
            html += '<p class="ini-vacio"><i class="fas fa-circle-check"></i> ' + f.vacio + '</p>';
        } else {
            html += '<div class="ini-tabla-wrap"><table class="ini-tabla"><thead><tr><th>Folio</th><th>Cliente · sucursal</th><th>Motivo del servicio</th><th>Técnico</th><th>Estatus</th><th class="num">Días</th></tr></thead><tbody>' +
                lista.slice(0, MAX_FILAS).map(function (r) {
                    var atrasada = r.estatus !== 'Completado' && r.dias > datos.dias_atraso;
                    return '<tr data-id="' + r.id + '" tabindex="0"><td class="folio">' + esc(r.numero_incidente || '#' + r.id) + '</td>' +
                        '<td><b>' + esc(r.cliente || '—') + '</b>' + (r.sucursal ? '<small>' + esc(r.sucursal) + '</small>' : '') + '</td>' +
                        '<td class="falla"><span>' + esc(r.falla) + '</span></td>' +
                        '<td>' + (r.sin_tecnico ? '<span class="ini-sin">Sin asignar</span>' : esc(r.tecnico)) + '</td>' +
                        '<td>' + pill(r.estatus) + '</td><td class="num' + (atrasada ? ' tarde' : '') + '">' + dias(r.dias) + '</td></tr>';
                }).join('') + '</tbody></table></div>' +
                (lista.length > MAX_FILAS ? '<p class="ini-mas">Mostrando ' + MAX_FILAS + ' de ' + lista.length + '. <a href="public/reportes.html">Ver todas en Buscar órdenes de servicio</a></p>' : '');
        }
        $('ini-bandeja').innerHTML = html + recorte;
        document.querySelectorAll('.ini-kpi').forEach(function (b) { b.classList.toggle('activo', b.getAttribute('data-filtro') === filtro); });
    }

    // ------------------------------------------------------------ técnico
    function pintarTecnico() {
        var k = datos.indicadores;
        var lista = datos.bandeja;
        var html = '<section class="ini-kpis cuatro" aria-label="Indicadores">' +
            kpi('x1', 'fa-user-check', 'Asignadas a mí', k.asignadas, 'por atender', 'violeta') +
            kpi('x2', 'fa-pause-circle', 'Pendientes', k.pendientes, 'en espera', 'ambar') +
            kpi('x3', 'fa-exclamation-triangle', 'Atrasadas', k.atrasadas, 'más de ' + datos.dias_atraso + ' días', k.atrasadas ? 'rojo' : '') +
            kpi('x4', 'fa-check-double', 'Completadas', k.completadas, 'por cerrar', 'verde') +
            '</section><div class="ini-columnas"><section class="ini-tarjeta"><div class="ini-bandeja-cab"><h2>Mi día <small>lo más antiguo primero</small></h2></div>';
        if (!lista.length) {
            html += '<p class="ini-vacio"><i class="fas fa-mug-hot"></i> No tienes órdenes de servicio asignadas por ahora.</p>';
        } else {
            html += '<ul class="ini-tareas">' + lista.map(function (r) {
                var atrasada = r.estatus !== 'Completado' && r.dias > datos.dias_atraso;
                return '<li class="' + (ESTATUS[r.estatus] || 'otro') + (atrasada ? ' tarde' : '') + '"><a href="public/detalle.html?id=' + r.id + '">' +
                    '<span class="ini-tarea-txt"><b>' + esc(r.numero_incidente || '#' + r.id) + ' · ' + esc(r.cliente || '') + '</b>' +
                    '<small>' + esc([r.sucursal, r.falla].filter(Boolean).join(' · ')) + '</small></span>' +
                    '<span class="ini-tarea-meta">' + pill(r.estatus) + '<em>' + dias(r.dias) + '</em></span>' +
                    '<span class="ini-tarea-ir">Abrir <i class="fas fa-chevron-right"></i></span></a></li>';
            }).join('') + '</ul>';
        }
        html += '</section><aside class="ini-lateral">' + tarjetaProgramadas() +
            '<a class="ini-tarjeta ini-atajo" href="public/dashboard-tecnico.html"><i class="fas fa-user-clock"></i><span><b>Mis métricas</b><small>Tus tiempos de respuesta y cierre</small></span></a>' +
            '<a class="ini-tarjeta ini-atajo" href="public/reportes.html"><i class="fas fa-search"></i><span><b>Buscar órdenes de servicio</b><small>Historial por cliente, equipo o serie</small></span></a>' +
            '<a class="ini-tarjeta ini-atajo" href="public/soporte.html"><i class="fas fa-book"></i><span><b>Soporte técnico</b><small>Manuales y preguntas frecuentes</small></span></a>' +
            '</aside></div>';
        $('ini-contenido').innerHTML = html;
        document.querySelectorAll('.ini-kpi').forEach(function (b) { b.disabled = true; b.classList.remove('activo'); });
    }

    // ------------------------------------------------------------ sin incidencias
    function pintarAccesos() {
        var enlaces = [];
        document.querySelectorAll('.sidebar .menu-lateral a[href]').forEach(function (a) {
            var li = a.closest('li');
            if (a.classList.contains('active') || (li && (li.hidden || li.style.display === 'none'))) return;
            enlaces.push('<a class="ini-tarjeta ini-atajo" href="' + esc(a.getAttribute('href')) + '"><i class="' + esc((a.querySelector('i') || {}).className) + '"></i>' +
                '<span><b>' + esc(a.textContent.trim()) + '</b></span></a>');
        });
        $('ini-contenido').innerHTML = '<section class="ini-accesos">' + enlaces.join('') + '</section>';
    }

    // ------------------------------------------------------------ eventos y arranque
    document.addEventListener('click', function (e) {
        var b = e.target.closest('[data-filtro]');
        if (b && datos && datos.vista === 'operacion') {
            filtro = b.getAttribute('data-filtro');
            pintarBandeja();
            if (b.classList.contains('ini-kpi') || b.classList.contains('ini-aviso')) {
                document.querySelector('.ini-bandeja').scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            }
            return;
        }
        var fila = e.target.closest('.ini-tabla tbody tr[data-id]');
        if (fila) window.location.href = 'public/detalle.html?id=' + fila.getAttribute('data-id');
    });
    document.addEventListener('keydown', function (e) {
        var fila = e.target.closest && e.target.closest('.ini-tabla tbody tr[data-id]');
        if (fila && (e.key === 'Enter' || e.key === ' ')) {
            e.preventDefault();
            window.location.href = 'public/detalle.html?id=' + fila.getAttribute('data-id');
        }
    });

    function cargar() {
        fetch('backend/inicio.php?accion=panel', { credentials: 'same-origin', cache: 'no-store', headers: { Accept: 'application/json' } })
            .then(function (r) { return r.json().then(function (j) { if (!r.ok || !j.exito) throw new Error(j.mensaje || 'Error ' + r.status); return j; }); })
            .then(function (j) {
                datos = j;
                if (!j.permisos.incidencias) pintarAccesos();
                else if (j.vista === 'tecnico') pintarTecnico();
                else pintarOperacion();
            })
            .catch(function (e) {
                $('ini-contenido').innerHTML = '<p class="ini-error"><i class="fas fa-exclamation-circle"></i> ' + esc(e.message) +
                    ' <button type="button" id="ini-reintentar">Reintentar</button></p>';
                $('ini-reintentar').addEventListener('click', cargar);
            });
    }

    window.appOnAuthReady(function (auth) {
        cabecera(auth);
        cargar();
    });
})();
