// Panel de plataforma Servidesk (plataforma/index.html).
// Vistas: resumen, empresas, solicitudes de pago, cobranza, pruebas, planes, bitácora, configuración
// y administradores; ficha lateral de cada empresa con su facturación, notas,
// marca, usuarios y bitácora. Habla con plataforma/api.php.
(function () {
    'use strict';

    var API = 'api.php';
    var moneda = 'MXN';
    var graficos = [];
    var yo = null;

    // ================================================================ utilidades
    function $(id) { return document.getElementById(id); }
    function esc(v) {
        return String(v == null ? '' : v).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }
    function dinero(n, decimales) {
        return (parseFloat(n) || 0).toLocaleString('es-MX', {
            style: 'currency', currency: moneda,
            minimumFractionDigits: decimales === 0 ? 0 : 2, maximumFractionDigits: decimales === 0 ? 0 : 2
        });
    }
    function numero(n) { return (parseFloat(n) || 0).toLocaleString('es-MX'); }
    function aFecha(v) {
        if (!v) return null;
        var s = String(v);
        var d = new Date(s.length === 10 ? s + 'T00:00:00' : s.replace(' ', 'T'));
        return isNaN(d) ? null : d;
    }
    function fecha(v) {
        var d = aFecha(v);
        return d ? d.toLocaleDateString('es-MX', { day: 'numeric', month: 'short', year: 'numeric' }) : '—';
    }
    function fechaHora(v) {
        var d = aFecha(v);
        return d ? d.toLocaleString('es-MX', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' }) : '—';
    }
    function relativo(v) {
        var d = aFecha(v);
        if (!d) return 'Nunca';
        var dias = Math.floor((Date.now() - d.getTime()) / 86400000);
        if (dias <= 0) {
            var horas = Math.floor((Date.now() - d.getTime()) / 3600000);
            return horas <= 0 ? 'Hace minutos' : 'Hace ' + horas + ' h';
        }
        if (dias === 1) return 'Ayer';
        if (dias < 30) return 'Hace ' + dias + ' días';
        return fecha(v);
    }
    // Fecha local (toISOString daría la de UTC: mañana, después de las 6 p.m. en México)
    function hoyISO() {
        var d = new Date();
        return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
    }
    function bytes(n) {
        n = parseFloat(n) || 0;
        if (n < 1024) return n + ' B';
        if (n < 1048576) return (n / 1024).toFixed(1) + ' KB';
        if (n < 1073741824) return (n / 1048576).toFixed(1) + ' MB';
        return (n / 1073741824).toFixed(2) + ' GB';
    }
    function toast(texto, tipo) {
        var t = document.createElement('div');
        t.className = 'pf-toast' + (tipo === 'error' ? ' error' : '');
        t.textContent = texto;
        $('pf-toasts').appendChild(t);
        setTimeout(function () { t.remove(); }, tipo === 'error' ? 6000 : 3500);
    }
    function api(accion, params, cuerpo) {
        var url = API + '?accion=' + accion + (params ? '&' + new URLSearchParams(params) : '');
        var op = { credentials: 'same-origin', cache: 'no-store' };
        if (cuerpo) {
            op.method = 'POST';
            op.headers = { 'Content-Type': 'application/json' };
            op.body = JSON.stringify(cuerpo);
        }
        return fetch(url, op).then(function (r) {
            if (r.status === 401 || r.status === 403) {
                window.location.replace('../auth/login.html');
                throw new Error('Sesión expirada');
            }
            return r.json().catch(function () { return {}; }).then(function (j) {
                if (!r.ok || !j.exito) throw new Error(j.mensaje || 'Error ' + r.status);
                return j;
            });
        });
    }
    function datosForm(form) { return Object.fromEntries(new FormData(form).entries()); }
    function avatar(nombre, color) {
        var paleta = ['#5b8cff', '#22c55e', '#f59e0b', '#a78bfa', '#38bdf8', '#f472b6', '#14b8a6', '#fb7185'];
        var h = 0;
        for (var i = 0; i < nombre.length; i++) h = (h * 31 + nombre.charCodeAt(i)) >>> 0;
        var iniciales = nombre.replace(/[^A-Za-zÁÉÍÓÚÑáéíóúñ0-9 ]/g, '').split(' ').filter(Boolean).slice(0, 2)
            .map(function (p) { return p[0]; }).join('').toUpperCase();
        return '<span class="pf-avatar" style="background:' + esc(color || paleta[h % paleta.length]) + '">' + esc(iniciales || '?') + '</span>';
    }

    // ================================================================ catálogos
    var SITUACION = {
        demo:           ['Demo', 'violeta'],
        prueba:         ['En prueba', 'info'],
        prueba_vencida: ['Prueba vencida', 'peligro'],
        activa:         ['Activa', 'ok'],
        gracia:         ['Pago vencido', 'warn'],
        solo_lectura:   ['Solo lectura', 'warn'],
        suspendida:     ['Suspendida', 'peligro'],
        cancelada:      ['Cancelada', 'neutra']
    };
    var SALUD = { alta: 'Uso alto', media: 'Uso medio', baja: 'Uso bajo', sin_uso: 'Sin uso', demo: 'Demo' };

    function etqSituacion(e) {
        var s = SITUACION[e.estado] || [e.estado, 'neutra'];
        var extra = '';
        if (e.estado === 'prueba' && e.dias !== null) extra = ' · ' + e.dias + ' d';
        if (['gracia', 'solo_lectura', 'suspendida'].indexOf(e.estado) !== -1 && e.dias) extra = ' · ' + e.dias + ' d de atraso';
        return '<span class="pf-etq ' + s[1] + '">' + esc(s[0] + extra) + '</span>';
    }
    function etqSalud(e) {
        return '<span class="pf-salud ' + esc(e.salud) + '" title="' + esc(e.eventos_30 + ' acciones en 30 días') + '">' + esc(SALUD[e.salud] || e.salud) + '</span>';
    }
    function proximoHito(e) {
        if (e.estado === 'demo') return '<span class="pf-sub">Se reinicia cada noche</span>';
        if (e.estado === 'prueba' || e.estado === 'prueba_vencida') return 'Prueba hasta ' + fecha(e.prueba_hasta);
        if (e.pagado_hasta) return 'Cobro ' + fecha(e.pagado_hasta);
        return e.estado === 'activa' ? '<span class="pf-sub">Sin vencimiento</span>' : '—';
    }

    // Bitácora: ícono, color y descripción de cada tipo de evento
    var EVENTOS = {
        registro:        ['fa-user-plus', 'ok', 'Se registró por su cuenta'],
        alta_manual:     ['fa-plus', 'ok', 'Alta manual'],
        pago:            ['fa-dollar-sign', 'ok', 'Pago registrado'],
        abono:           ['fa-coins', 'ok', 'Abono (pago parcial)'],
        nota_credito:    ['fa-file-invoice', 'warn', 'Nota de crédito'],
        pago_anulado:    ['fa-undo', 'peligro', 'Pago anulado'],
        estado:          ['fa-exchange-alt', 'warn', 'Cambio de estado'],
        plan:            ['fa-layer-group', '', 'Cambio de plan'],
        marca:           ['fa-palette', '', 'Cambio de marca'],
        datos:           ['fa-id-card', '', 'Datos actualizados'],
        exportacion:     ['fa-file-archive', '', 'Exportación de datos'],
        eliminacion:     ['fa-trash', 'peligro', 'Empresa eliminada'],
        soporte_entrada: ['fa-headset', 'violeta', 'Entró como soporte'],
        soporte_salida:  ['fa-headset', 'violeta', 'Salió del modo soporte'],
        plan_guardado:   ['fa-layer-group', '', 'Plan editado'],
        config:          ['fa-sliders-h', '', 'Configuración actualizada'],
        admin_alta:      ['fa-user-shield', '', 'Nueva cuenta de plataforma'],
        admin_baja:      ['fa-user-shield', 'peligro', 'Cuenta de plataforma eliminada'],
        admin_password:  ['fa-key', '', 'Cambio de contraseña de plataforma'],
        solicitud_pago:       ['fa-file-invoice-dollar', 'info', 'Solicitó contratar'],
        comprobante:          ['fa-paperclip', 'info', 'Subió comprobante de pago'],
        solicitud_confirmada: ['fa-check-circle', 'ok', 'Pago confirmado'],
        solicitud_rechazada:  ['fa-times-circle', 'peligro', 'Solicitud de pago rechazada'],
        solicitud_cancelada:  ['fa-ban', '', 'El cliente canceló su solicitud']
    };
    function describirEvento(ev) {
        var d = ev.detalle || {};
        switch (ev.accion) {
            case 'pago': return dinero(d.monto) + ' · cubre hasta ' + fecha(d.cubre_hasta);
            case 'abono': case 'nota_credito': return dinero(d.monto);
            case 'pago_anulado': return dinero(d.monto) + ' · ' + (d.motivo || '');
            case 'estado': return (SITUACION[d.de] || [d.de])[0] + ' → ' + (SITUACION[d.a] || [d.a])[0] + (d.prueba_hasta ? ' (prueba hasta ' + fecha(d.prueba_hasta) + ')' : '');
            case 'registro': return d.correo || '';
            case 'plan': return 'Ciclo ' + (d.ciclo || '') + (d.precio_pactado != null ? ' · precio pactado ' + dinero(d.precio_pactado) : '');
            case 'eliminacion': return d.nombre || '';
            case 'admin_alta': case 'admin_baja': return d.usuario || '';
            case 'plan_guardado': return (d.nombre || '') + ' · ' + dinero(d.precio_mensual) + '/mes';
            case 'exportacion': return bytes(d.bytes);
            case 'solicitud_pago': return (d.referencia || '') + ' · ' + (d.plan || '') + ' ' + (d.ciclo || '') + ' · ' + dinero(d.monto);
            case 'comprobante': case 'solicitud_cancelada': return d.referencia || '';
            case 'solicitud_confirmada': return (d.referencia || '') + ' · ' + dinero(d.monto) + ' · cubre hasta ' + fecha(d.cubre_hasta);
            case 'solicitud_rechazada': return (d.referencia || '') + ' · ' + (d.motivo || '');
            default: return '';
        }
    }
    function itemEvento(ev, conEmpresa) {
        var m = EVENTOS[ev.accion] || ['fa-circle', '', ev.accion];
        return '<li><span class="pf-punto ' + m[1] + '"><i class="fas ' + m[0] + '"></i></span><div class="pf-texto">' +
            '<strong>' + esc(m[2]) + '</strong>' + (conEmpresa && ev.empresa ? ' · ' + esc(ev.empresa) : '') +
            '<div class="pf-meta">' + esc(describirEvento(ev)) + '</div>' +
            '<div class="pf-meta">' + fechaHora(ev.creado_en) + (ev.usuario_nombre ? ' · ' + esc(ev.usuario_nombre) : '') + '</div></div></li>';
    }

    // ================================================================ capas (diálogos, ficha)
    function cerrarCapas() { $('pf-capas').innerHTML = ''; fichaActual = null; }
    function dialogo(titulo, cuerpoHTML) {
        $('pf-capas').innerHTML = '<div class="pf-fondo"></div>' +
            '<div class="pf-modal" role="dialog" aria-modal="true"><div class="pf-modal-cab"><h3>' + esc(titulo) + '</h3>' +
            '<button type="button" class="pf-cerrar" data-cerrar-capa aria-label="Cerrar">&times;</button></div>' +
            '<div class="pf-modal-cuerpo">' + cuerpoHTML + '</div></div>';
        var primero = $('pf-capas').querySelector('input, select, textarea');
        if (primero) primero.focus();
        return $('pf-capas').querySelector('.pf-modal');
    }
    function confirmar(titulo, texto, textoBoton, peligro) {
        return new Promise(function (resolver) {
            var m = dialogo(titulo, '<p style="margin-top:0;color:var(--pf-text-2)">' + esc(texto) + '</p>' +
                '<div class="pf-form-acciones"><button type="button" class="pf-btn sec" data-r="0">Cancelar</button>' +
                '<button type="button" class="pf-btn ' + (peligro ? 'peligro' : '') + '" data-r="1">' + esc(textoBoton) + '</button></div>');
            m.addEventListener('click', function (e) {
                var b = e.target.closest('[data-r]');
                if (!b) return;
                var r = b.getAttribute('data-r') === '1';
                if (fichaActual) { var id = fichaActual; cerrarCapas(); if (!r) abrirFicha(id, pestanaActual); }
                else cerrarCapas();
                resolver(r);
            });
        });
    }
    document.addEventListener('click', function (e) {
        if (e.target.closest('[data-cerrar-capa]')) {
            // Un diálogo abierto encima de una ficha vuelve a la ficha
            if (fichaActual && $('pf-capas').querySelector('.pf-modal')) { abrirFicha(fichaActual, pestanaActual); }
            else cerrarCapas();
        }
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && $('pf-capas').innerHTML) {
            if (fichaActual && $('pf-capas').querySelector('.pf-modal')) abrirFicha(fichaActual, pestanaActual);
            else cerrarCapas();
        }
    });

    // ================================================================ gráficos
    function prepararGraficos() {
        if (typeof Chart === 'undefined') return false;
        Chart.defaults.color = '#8a9bb8';
        Chart.defaults.borderColor = 'rgba(255,255,255,0.06)';
        Chart.defaults.font.family = 'Inter, "Segoe UI", sans-serif';
        Chart.defaults.plugins.legend.labels.boxWidth = 10;
        return true;
    }
    function limpiarGraficos() { graficos.forEach(function (g) { g.destroy(); }); graficos = []; }
    function grafico(idCanvas, config) {
        var c = $(idCanvas);
        if (!c || !prepararGraficos()) return;
        config.options = Object.assign({ responsive: true, maintainAspectRatio: false }, config.options || {});
        graficos.push(new Chart(c, config));
    }
    function etiquetaMes(m) {
        var p = m.split('-');
        return new Date(p[0], p[1] - 1, 1).toLocaleDateString('es-MX', { month: 'short', year: '2-digit' });
    }
    var COLORES_SITUACION = { activa: '#22c55e', prueba: '#38bdf8', gracia: '#f59e0b', solo_lectura: '#fb923c',
        suspendida: '#ef4444', prueba_vencida: '#f87171', cancelada: '#64748b' };

    // ================================================================ cabecera y navegación
    function cabecera(titulo, subtitulo, accionesHTML) {
        $('pf-titulo').textContent = titulo;
        $('pf-subtitulo').textContent = subtitulo || '';
        $('pf-acciones').innerHTML = accionesHTML || '';
        document.title = titulo + ' · Plataforma';
    }
    function cargando() { $('pf-vista').innerHTML = '<div class="pf-cargando">Cargando…</div>'; }
    function error(e) { $('pf-vista').innerHTML = '<div class="pf-aviso peligro">' + esc(e.message) + '</div>'; }

    var VISTAS = {};
    function navegar() {
        var vista = (location.hash || '#resumen').slice(1).split('?')[0];
        if (!VISTAS[vista]) vista = 'resumen';
        document.querySelectorAll('#pf-nav a').forEach(function (a) { a.classList.toggle('activo', a.getAttribute('data-vista') === vista); });
        $('pf-lateral').classList.remove('abierta');
        limpiarGraficos();
        cerrarCapas();
        cargando();
        VISTAS[vista]();
    }
    window.addEventListener('hashchange', navegar);
    $('pf-menu').addEventListener('click', function () { $('pf-lateral').classList.toggle('abierta'); });

    function insignias(r) {
        var c = $('ins-cobranza'), p = $('ins-pruebas'), s = $('ins-solicitudes');
        s.textContent = r.solicitudes_pendientes || 0; s.classList.toggle('pf-oculto', !r.solicitudes_pendientes);
        c.textContent = r.con_atraso; c.classList.toggle('pf-oculto', !r.con_atraso);
        p.textContent = r.pruebas_por_vencer; p.classList.toggle('pf-oculto', !r.pruebas_por_vencer);
    }

    // ================================================================ RESUMEN
    VISTAS.resumen = function () {
        cabecera('Resumen', 'Salud del negocio de un vistazo',
            '<a class="pf-btn sec" href="#empresas"><i class="fas fa-building"></i> Ver empresas</a>');
        api('resumen').then(function (j) {
            var r = j.resumen;
            moneda = r.moneda || 'MXN';
            insignias(r);
            var variacion = r.ingresos_mes_anterior > 0 ? Math.round((r.ingresos_mes - r.ingresos_mes_anterior) * 100 / r.ingresos_mes_anterior) : null;
            var kpi = function (clase, icono, etiqueta, valor, nota, destino) {
                return '<div class="pf-kpi ' + clase + (destino ? ' clic" data-ir="' + destino : '') + '">' +
                    '<div class="pf-kpi-etiqueta"><i class="fas ' + icono + '"></i> ' + etiqueta + '</div>' +
                    '<div class="pf-kpi-valor">' + valor + '</div><div class="pf-kpi-nota">' + nota + '</div></div>';
            };
            $('pf-vista').innerHTML =
                '<div class="pf-rejilla kpis">' +
                kpi('ok', 'fa-sync-alt', 'Ingreso mensual recurrente (MRR)', dinero(r.mrr, 0), 'ARR ' + dinero(r.arr, 0) + ' al año') +
                kpi('', 'fa-users', 'Clientes que pagan', numero(r.clientes_pagando), 'Ingreso promedio ' + dinero(r.arpa, 0) + ' / cuenta') +
                kpi('ok', 'fa-wallet', 'Cobrado este mes', dinero(r.ingresos_mes, 0),
                    variacion === null ? 'Mes anterior: ' + dinero(r.ingresos_mes_anterior, 0)
                        : '<span class="' + (variacion >= 0 ? 'pf-sube' : 'pf-baja') + '">' + (variacion >= 0 ? '▲ ' : '▼ ') + Math.abs(variacion) + '%</span> vs mes anterior') +
                kpi('info', 'fa-funnel-dollar', 'Conversión de pruebas', r.conversion === null ? '—' : r.conversion + '%',
                    r.pruebas_convertidas + ' de ' + r.pruebas_iniciadas + ' pruebas pagaron', 'pruebas') +
                kpi('info', 'fa-hourglass-half', 'Pruebas activas', numero(r.pruebas_activas),
                    r.pruebas_por_vencer ? '<span class="pf-baja">' + r.pruebas_por_vencer + ' vencen en ≤ 3 días</span>' : 'Ninguna por vencer', 'pruebas') +
                kpi(r.con_atraso ? 'warn' : '', 'fa-exclamation-circle', 'Por cobrar', dinero(r.por_cobrar, 0), r.con_atraso + ' cuentas con pago vencido', 'cobranza') +
                kpi(r.suspendidas ? 'peligro' : '', 'fa-ban', 'Suspendidas o vencidas', numero(r.suspendidas), 'Sin acceso; datos conservados', 'empresas') +
                kpi('violeta', 'fa-user-plus', 'Altas (30 días)', numero(r.registros_30), r.cancelaciones_30 + ' cancelaciones en 30 días') +
                '</div>' +
                '<div class="pf-rejilla dos">' +
                '<div class="pf-tarjeta"><h3>Ingresos cobrados <small>últimos 12 meses</small></h3><div class="pf-grafico"><canvas id="g-ingresos"></canvas></div></div>' +
                '<div class="pf-tarjeta"><h3>Cartera por situación <small>' + r.total_empresas + ' empresas</small></h3><div class="pf-grafico"><canvas id="g-cartera"></canvas></div></div>' +
                '</div><div class="pf-rejilla dos pf-espacio">' +
                '<div class="pf-tarjeta"><h3>Nuevas empresas <small>por mes</small></h3><div class="pf-grafico chico"><canvas id="g-altas"></canvas></div></div>' +
                '<div class="pf-tarjeta"><h3>MRR por plan</h3><div class="pf-grafico chico"><canvas id="g-planes"></canvas></div></div>' +
                '</div><div class="pf-tarjeta pf-espacio"><h3>Actividad reciente <a class="pf-btn sec chico" href="#bitacora">Ver bitácora</a></h3>' +
                (j.actividad.length ? '<ul class="pf-linea">' + j.actividad.map(function (ev) { return itemEvento(ev, true); }).join('') + '</ul>'
                    : '<p class="pf-ayuda">Aún no hay actividad.</p>') + '</div>';

            $('pf-vista').querySelectorAll('[data-ir]').forEach(function (k) {
                k.addEventListener('click', function () { location.hash = k.getAttribute('data-ir'); });
            });
            grafico('g-ingresos', {
                type: 'bar',
                data: { labels: j.series.ingresos.map(function (s) { return etiquetaMes(s.mes); }),
                        datasets: [{ label: 'Cobrado', data: j.series.ingresos.map(function (s) { return s.valor; }), backgroundColor: '#5b8cff', borderRadius: 6 }] },
                options: { plugins: { legend: { display: false }, tooltip: { callbacks: { label: function (c) { return dinero(c.parsed.y); } } } },
                           scales: { y: { beginAtZero: true, ticks: { callback: function (v) { return dinero(v, 0); } } } } }
            });
            var estados = Object.keys(r.por_estado);
            grafico('g-cartera', {
                type: 'doughnut',
                data: { labels: estados.map(function (e) { return (SITUACION[e] || [e])[0]; }),
                        datasets: [{ data: estados.map(function (e) { return r.por_estado[e]; }), backgroundColor: estados.map(function (e) { return COLORES_SITUACION[e] || '#94a3b8'; }), borderWidth: 0 }] },
                options: { cutout: '62%', plugins: { legend: { position: 'right' } } }
            });
            grafico('g-altas', {
                type: 'line',
                data: { labels: j.series.registros.map(function (s) { return etiquetaMes(s.mes); }),
                        datasets: [{ label: 'Altas', data: j.series.registros.map(function (s) { return s.valor; }), borderColor: '#a78bfa',
                                     backgroundColor: 'rgba(167,139,250,0.15)', fill: true, tension: .35, pointRadius: 3 }] },
                options: { plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true, ticks: { precision: 0 } } } }
            });
            var planes = Object.keys(r.mrr_por_plan);
            grafico('g-planes', {
                type: 'doughnut',
                data: { labels: planes.length ? planes : ['Sin ingresos'],
                        datasets: [{ data: planes.length ? planes.map(function (p) { return r.mrr_por_plan[p]; }) : [1],
                                     backgroundColor: planes.length ? ['#5b8cff', '#22c55e', '#f59e0b', '#a78bfa', '#38bdf8'] : ['#243049'], borderWidth: 0 }] },
                options: { cutout: '62%', plugins: { legend: { position: 'right' },
                           tooltip: { callbacks: { label: function (c) { return planes.length ? c.label + ': ' + dinero(c.parsed) + '/mes' : 'Sin ingresos'; } } } } }
            });
        }).catch(error);
    };

    // ================================================================ EMPRESAS
    var cacheEmpresas = { empresas: [], planes: [] };
    VISTAS.empresas = function () {
        cabecera('Empresas', 'Tu cartera de clientes, pruebas y cuentas',
            '<a class="pf-btn sec" href="' + API + '?accion=csv_empresas"><i class="fas fa-file-csv"></i> Exportar CSV</a>' +
            '<button type="button" class="pf-btn" id="btn-nueva"><i class="fas fa-plus"></i> Nueva empresa</button>');
        $('btn-nueva').addEventListener('click', dialogoNuevaEmpresa);
        api('empresas').then(function (j) {
            cacheEmpresas = j;
            $('pf-vista').innerHTML =
                '<div class="pf-filtros">' +
                '<input type="search" id="f-q" placeholder="Buscar por empresa, contacto, correo, teléfono o RFC…">' +
                '<select id="f-sit"><option value="">Toda situación</option>' + Object.keys(SITUACION).map(function (k) {
                    return '<option value="' + k + '">' + SITUACION[k][0] + '</option>'; }).join('') + '</select>' +
                '<select id="f-plan"><option value="">Todo plan</option>' + j.planes.map(function (p) {
                    return '<option value="' + p.id + '">' + esc(p.nombre) + '</option>'; }).join('') + '</select>' +
                '<select id="f-salud"><option value="">Todo uso</option><option value="alta">Uso alto</option><option value="media">Uso medio</option>' +
                '<option value="baja">Uso bajo</option><option value="sin_uso">Sin uso</option></select>' +
                '</div><div class="pf-tabla-wrap"><table class="pf-tabla"><thead><tr>' +
                '<th>Empresa</th><th>Plan</th><th class="num">MRR</th><th>Situación</th><th>Próximo hito</th><th>Uso</th><th class="num">Usuarios</th><th>Alta</th>' +
                '</tr></thead><tbody id="t-empresas"></tbody></table></div>';
            ['f-q', 'f-sit', 'f-plan', 'f-salud'].forEach(function (id) { $(id).addEventListener('input', pintarEmpresas); });
            var sit = new URLSearchParams(location.hash.split('?')[1] || '').get('sit');
            if (sit) $('f-sit').value = sit;
            pintarEmpresas();
        }).catch(error);
    };
    function pintarEmpresas() {
        var q = $('f-q').value.trim().toLowerCase(), sit = $('f-sit').value, plan = $('f-plan').value, salud = $('f-salud').value;
        var lista = cacheEmpresas.empresas.filter(function (e) {
            if (sit && e.estado !== sit) return false;
            if (plan && String(e.plan_id) !== plan) return false;
            if (salud && e.salud !== salud) return false;
            return !q || [e.nombre, e.slug, e.contacto_nombre, e.contacto_correo, e.contacto_telefono, e.rfc, e.razon_social]
                .join(' ').toLowerCase().indexOf(q) !== -1;
        });
        $('t-empresas').innerHTML = lista.length ? lista.map(function (e) {
            return '<tr class="clic" tabindex="0" data-id="' + e.id + '">' +
                '<td><div class="pf-empresa-celda">' + avatar(e.nombre, e.color) + '<div><strong>' + esc(e.nombre) + '</strong>' +
                '<span class="pf-sub">' + esc(e.contacto_correo || e.slug) + '</span></div></div></td>' +
                '<td>' + esc(e.plan) + '<span class="pf-sub">' + (e.ciclo === 'anual' ? 'Anual' : 'Mensual') + (e.precio_pactado ? ' · precio pactado' : '') + '</span></td>' +
                '<td class="num">' + (e.mrr ? dinero(e.mrr, 0) : '<span class="pf-sub">—</span>') + '</td>' +
                '<td>' + etqSituacion(e) + '</td>' +
                '<td>' + proximoHito(e) + '</td>' +
                '<td>' + etqSalud(e) + '<span class="pf-sub">' + relativo(e.ultima_actividad) + '</span></td>' +
                '<td class="num">' + e.usuarios + '</td>' +
                '<td>' + fecha(e.creado_en) + '<span class="pf-sub">' + (e.autoservicio ? 'Autoservicio' : 'Manual') + '</span></td></tr>';
        }).join('') : '<tr><td colspan="8" class="pf-vacio">No hay empresas que coincidan con los filtros.</td></tr>';
    }
    document.addEventListener('click', function (e) {
        var tr = e.target.closest('tr.clic[data-id]');
        if (tr && !e.target.closest('button, a')) abrirFicha(parseInt(tr.getAttribute('data-id'), 10), tr.getAttribute('data-pestana') || 'resumen');
    });
    document.addEventListener('keydown', function (e) {
        var tr = e.target.closest && e.target.closest('tr.clic[data-id]');
        if (tr && e.key === 'Enter') abrirFicha(parseInt(tr.getAttribute('data-id'), 10));
    });

    function opcionesPlanes(planes, seleccionado) {
        return planes.filter(function (p) { return +p.activo || String(p.id) === String(seleccionado); }).map(function (p) {
            return '<option value="' + p.id + '"' + (String(p.id) === String(seleccionado) ? ' selected' : '') + '>' + esc(p.nombre) +
                ' · ' + dinero(p.precio_mensual, 0) + '/mes · ' + (p.max_usuarios ? p.max_usuarios + ' usuarios' : 'usuarios ilimitados') + '</option>';
        }).join('');
    }

    function dialogoNuevaEmpresa() {
        var planes = cacheEmpresas.planes && cacheEmpresas.planes.length ? Promise.resolve(cacheEmpresas.planes) : api('planes').then(function (j) { return j.planes; });
        planes.then(function (lista) {
            var m = dialogo('Nueva empresa',
                '<form class="pf-form" id="form-nueva" autocomplete="off">' +
                '<label class="ancho">Nombre de la empresa<input name="nombre" required maxlength="150"></label>' +
                '<label>Administrador<input name="admin_nombre" maxlength="100" placeholder="Nombre y apellido"></label>' +
                '<label>Teléfono<input name="telefono" maxlength="20"></label>' +
                '<label class="ancho">Correo del administrador <small>(será su usuario)</small><input name="correo" type="email" required maxlength="100"></label>' +
                '<label>Contraseña temporal<input name="password" required minlength="8"></label>' +
                '<label>Inicia como<select name="estado"><option value="prueba">Prueba gratuita</option><option value="activa">Cliente (ya pagó)</option></select></label>' +
                '<label>Plan<select name="plan_id">' + opcionesPlanes(lista, 2) + '</select></label>' +
                '<label>Ciclo<select name="ciclo"><option value="mensual">Mensual</option><option value="anual">Anual</option></select></label>' +
                '<p class="pf-ayuda ancho">Si inicia como cliente, registra su primer pago en la pestaña Facturación de su ficha.</p>' +
                '<div class="pf-form-acciones"><button type="button" class="pf-btn sec" data-cerrar-capa>Cancelar</button>' +
                '<button type="submit" class="pf-btn"><i class="fas fa-check"></i> Crear empresa</button></div></form>');
            m.querySelector('form').addEventListener('submit', function (ev) {
                ev.preventDefault();
                var boton = this.querySelector('[type=submit]');
                boton.disabled = true;
                api('crear', null, datosForm(this)).then(function (r) {
                    toast('Empresa creada. Comparte al administrador su correo y la contraseña temporal.');
                    cerrarCapas();
                    if (location.hash.indexOf('empresas') === -1) location.hash = 'empresas'; else VISTAS.empresas();
                    setTimeout(function () { abrirFicha(r.id, 'facturacion'); }, 400);
                }).catch(function (e) { toast(e.message, 'error'); boton.disabled = false; });
            });
        }).catch(function (e) { toast(e.message, 'error'); });
    }

    // ================================================================ FICHA DE EMPRESA
    var fichaActual = null, pestanaActual = 'resumen';
    function abrirFicha(id, pestana) {
        fichaActual = id;
        pestanaActual = pestana || 'resumen';
        $('pf-capas').innerHTML = '<div class="pf-fondo"></div><aside class="pf-cajon" role="dialog" aria-modal="true">' +
            '<div class="pf-cajon-cuerpo"><div class="pf-cargando">Cargando ficha…</div></div></aside>';
        api('empresa', { id: id }).then(function (j) { if (fichaActual === id) pintarFicha(j); })
            .catch(function (e) { toast(e.message, 'error'); cerrarCapas(); });
    }
    function recargarTodo(id, pestana) {
        if (VISTAS[(location.hash || '#resumen').slice(1).split('?')[0]]) {
            var vista = (location.hash || '#resumen').slice(1).split('?')[0];
            limpiarGraficos();
            VISTAS[vista]();
        }
        if (id) abrirFicha(id, pestana || pestanaActual);
    }

    function pintarFicha(j) {
        var e = j.empresa, p = e.precio, esDemo = e.estado_guardado === 'demo';
        var acciones = '<button class="pf-btn" data-a="soporte"><i class="fas fa-headset"></i> Entrar como soporte</button>';
        if (!esDemo) {
            acciones += '<button class="pf-btn sec" data-a="pestana" data-p="facturacion"><i class="fas fa-dollar-sign"></i> Registrar pago</button>' +
                '<button class="pf-btn sec" data-a="extender"><i class="fas fa-hourglass-half"></i> Extender prueba</button>' +
                (e.estado_guardado === 'suspendida' || e.estado_guardado === 'cancelada'
                    ? '<button class="pf-btn sec" data-a="estado" data-e="activa"><i class="fas fa-play"></i> Reactivar</button>'
                    : '<button class="pf-btn warn" data-a="estado" data-e="suspendida"><i class="fas fa-pause"></i> Suspender</button>') +
                (e.estado_guardado !== 'cancelada'
                    ? '<button class="pf-btn peligro" data-a="estado" data-e="cancelada"><i class="fas fa-ban"></i> Cancelar cuenta</button>'
                    : '<button class="pf-btn peligro" data-a="eliminar"><i class="fas fa-trash"></i> Eliminar definitivamente</button>');
        }
        acciones += '<a class="pf-btn sec" href="' + API + '?accion=exportar&id=' + e.id + '"><i class="fas fa-file-archive"></i> Exportar datos</a>';

        var pestanas = [['resumen', 'Resumen'], ['facturacion', 'Facturación'], ['datos', 'Datos y contacto'],
            ['notas', 'Notas (' + j.notas.length + ')'], ['marca', 'Marca'], ['usuarios', 'Usuarios (' + j.usuarios.length + ')'], ['bitacora', 'Bitácora']];

        var cajon = $('pf-capas').querySelector('.pf-cajon');
        cajon.innerHTML =
            '<div class="pf-cajon-cab"><div class="pf-cajon-cab-fila"><div class="pf-empresa-celda">' + avatar(e.nombre, e.color) +
            '<div><h2>' + esc(e.nombre) + '</h2><span class="pf-sub">' + esc(e.slug) + ' · alta ' + fecha(e.creado_en) +
            (e.autoservicio ? ' · autoservicio' : '') + '</span></div></div>' +
            '<button type="button" class="pf-cerrar" data-cerrar-ficha aria-label="Cerrar">&times;</button></div>' +
            '<div class="pf-etiquetas">' + etqSituacion(e) + '<span class="pf-etq neutra">' + esc(e.plan) + ' · ' + (e.ciclo === 'anual' ? 'anual' : 'mensual') + '</span>' + etqSalud(e) + '</div>' +
            '<div class="pf-acciones-rapidas">' + acciones + '</div></div>' +
            '<div class="pf-cajon-cuerpo"><nav class="pf-pestanas">' + pestanas.map(function (t) {
                return '<button type="button" data-p="' + t[0] + '"' + (t[0] === pestanaActual ? ' class="activa"' : '') + '>' + t[1] + '</button>';
            }).join('') + '</nav><div id="ficha-panel"></div></div>';

        cajon.querySelector('[data-cerrar-ficha]').addEventListener('click', cerrarCapas);
        cajon.querySelector('.pf-pestanas').addEventListener('click', function (ev) {
            var b = ev.target.closest('[data-p]');
            if (!b) return;
            pestanaActual = b.getAttribute('data-p');
            cajon.querySelectorAll('.pf-pestanas button').forEach(function (x) { x.classList.toggle('activa', x === b); });
            pintarPestana(j);
        });
        cajon.querySelector('.pf-acciones-rapidas').addEventListener('click', function (ev) {
            var b = ev.target.closest('[data-a]');
            if (b) accionFicha(j, b);
        });
        pintarPestana(j);
    }

    function pintarPestana(j) {
        var e = j.empresa, p = e.precio, panel = $('ficha-panel'), esDemo = e.estado_guardado === 'demo';
        switch (pestanaActual) {
        case 'resumen':
            var limite = j.limite_usuarios;
            panel.innerHTML = (e.solicitud_pendiente ? '<div class="pf-aviso info"><i class="fas fa-file-invoice-dollar"></i> Tiene una solicitud de pago por verificar. ' +
                    '<a href="#solicitudes" data-cerrar-capa>Ver solicitudes</a></div>' : '') +
                (e.mensaje && !esDemo ? '<div class="pf-aviso ' + (e.acceso === 'total' ? 'info' : 'warn') + '">' + esc(e.mensaje) + '</div>' : '') +
                '<div class="pf-datos">' +
                '<div class="pf-dato"><span>Ingreso mensual</span><strong>' + (e.mrr ? dinero(e.mrr) : '—') + '</strong><small>' +
                    dinero(p.por_ciclo) + ' por ' + (p.ciclo === 'anual' ? 'año' : 'mes') + (p.pactado ? ' (pactado)' : '') + '</small></div>' +
                '<div class="pf-dato"><span>' + (e.estado === 'prueba' || e.estado === 'prueba_vencida' ? 'Fin de la prueba' : 'Pagado hasta') + '</span><strong>' +
                    fecha(e.estado === 'prueba' || e.estado === 'prueba_vencida' ? e.prueba_hasta : e.pagado_hasta) + '</strong><small>' + esc(SITUACION[e.estado] ? SITUACION[e.estado][0] : e.estado) + '</small></div>' +
                '<div class="pf-dato"><span>Total cobrado</span><strong>' + dinero(e.total_pagado) + '</strong><small>' + e.pagos + ' pagos</small></div>' +
                '<div class="pf-dato"><span>Usuarios</span><strong>' + e.usuarios + (limite ? ' / ' + limite : '') + '</strong><small>' + (limite ? 'Límite del plan' : 'Sin límite') + '</small></div>' +
                '<div class="pf-dato"><span>Uso (30 días)</span><strong>' + numero(e.eventos_30) + '</strong><small>' + esc(SALUD[e.salud] || '') + ' · ' + relativo(e.ultima_actividad) + '</small></div>' +
                '<div class="pf-dato"><span>Almacenamiento</span><strong>' + bytes(j.almacenamiento) + '</strong><small>Archivos y manuales</small></div>' +
                '</div><div class="pf-seccion-titulo" style="margin-top:22px">Datos capturados</div><div class="pf-datos">' +
                [['incidencias', 'Órdenes de servicio'], ['clientes', 'Clientes'], ['inventario_articulos', 'Artículos'], ['ventas', 'Ventas'], ['padron_equipos', 'Equipos en padrón']]
                    .map(function (c) { return '<div class="pf-dato"><span>' + c[1] + '</span><strong>' + numero(j.conteos[c[0]]) + '</strong></div>'; }).join('') +
                '</div><div class="pf-seccion-titulo" style="margin-top:22px">Contacto</div><div class="pf-datos">' +
                '<div class="pf-dato"><span>Contacto</span><strong style="font-size:14px">' + esc(e.contacto_nombre || '—') + '</strong><small>' + esc(e.contacto_correo || '') + '</small><small>' + esc(e.contacto_telefono || '') + '</small></div>' +
                '<div class="pf-dato"><span>Datos fiscales</span><strong style="font-size:14px">' + esc(e.razon_social || '—') + '</strong><small>' + esc(e.rfc || 'Sin RFC') + '</small></div></div>';
            break;

        case 'facturacion':
            if (esDemo) { panel.innerHTML = '<div class="pf-aviso info">La empresa demo no lleva facturación.</div>'; break; }
            panel.innerHTML =
                '<div class="pf-seccion-titulo">Plan contratado</div>' +
                '<form class="pf-form tres" id="f-plan">' +
                '<label class="ancho">Plan<select name="plan_id">' + opcionesPlanes(j.planes, e.plan_id) + '</select></label>' +
                '<label>Ciclo<select name="ciclo"><option value="mensual"' + (p.ciclo === 'mensual' ? ' selected' : '') + '>Mensual</option>' +
                '<option value="anual"' + (p.ciclo === 'anual' ? ' selected' : '') + '>Anual</option></select></label>' +
                '<label>Precio pactado por ciclo <small>vacío = precio de lista</small><input name="precio" type="number" min="0" step="0.01" value="' + (p.pactado ? p.por_ciclo : '') + '" placeholder="' + p.de_lista + '"></label>' +
                '<div class="pf-form-acciones" style="grid-column:auto;align-items:end"><button type="submit" class="pf-btn sec"><i class="fas fa-save"></i> Guardar plan</button></div></form>' +
                '<div class="pf-seccion-titulo" style="margin-top:24px">Registrar pago</div>' +
                '<form class="pf-form tres" id="f-pago">' +
                '<label class="ancho">Tipo de movimiento<select name="tipo">' +
                    '<option value="pago">Pago de periodo: extiende la vigencia y activa la cuenta</option>' +
                    '<option value="abono">Abono (pago parcial): suma lo cobrado, no extiende la vigencia</option>' +
                    '<option value="nota_credito">Nota de crédito: descuento o devolución, se resta de lo cobrado</option></select>' +
                    '<small id="pago-ayuda">Si el cliente paga en partes, registra cada parte como abono y la última como pago de periodo.</small></label>' +
                '<label>Monto<input name="monto" type="number" min="0" step="0.01" placeholder="' + p.por_ciclo + '"></label>' +
                '<label>Fecha del pago<input name="fecha_pago" type="date" value="' + hoyISO() + '"></label>' +
                '<label data-solo-pago>Cubre hasta <small>vacío = un ' + (p.ciclo === 'anual' ? 'año' : 'mes') + ' más</small><input name="cubre_hasta" type="date"></label>' +
                '<label>Método<select name="metodo"><option>Transferencia</option><option>Depósito</option><option>Tarjeta</option><option>Efectivo</option><option>Otro</option></select></label>' +
                '<label>Referencia<input name="referencia" maxlength="100" placeholder="Folio SPEI, autorización…"></label>' +
                '<label>Factura (CFDI)<input name="factura" maxlength="60" placeholder="Folio o UUID"></label>' +
                '<label class="ancho">Notas<input name="notas" maxlength="255"></label>' +
                '<div class="pf-form-acciones"><button type="submit" class="pf-btn ok"><i class="fas fa-check"></i> Registrar pago y activar</button></div></form>' +
                '<div class="pf-seccion-titulo" style="margin-top:24px">Historial de pagos</div>' +
                (j.pagos.length ? '<div class="pf-tabla-wrap"><table class="pf-tabla"><thead><tr><th>Fecha</th><th>Tipo</th><th class="num">Monto</th><th>Cubre hasta</th><th>Método</th><th>Factura</th><th></th></tr></thead><tbody>' +
                    j.pagos.map(function (pg) {
                        var anulado = +pg.anulado, tipo = pg.tipo || 'pago';
                        return '<tr class="' + (anulado ? 'anulado' : '') + '"><td>' + fecha(pg.fecha_pago) + '<span class="pf-sub">' + esc(pg.registrado_por || '') + '</span></td>' +
                            '<td>' + (tipo === 'abono' ? '<span class="pf-etq info">Abono</span>' : tipo === 'nota_credito' ? '<span class="pf-etq warn">Nota de crédito</span>' : 'Pago') + '</td>' +
                            '<td class="num">' + dinero(pg.monto) + '</td><td>' + (tipo === 'pago' ? fecha(pg.cubre_hasta) : '—') + '</td>' +
                            '<td>' + esc(pg.metodo || '—') + '<span class="pf-sub">' + esc(pg.referencia || '') + '</span></td>' +
                            '<td>' + (pg.factura ? esc(pg.factura) : '<span class="pf-etq warn">Sin factura</span>') + '</td>' +
                            '<td class="num">' + (anulado ? '<span class="pf-etq neutra" title="' + esc(pg.anulado_motivo) + '">Anulado</span>'
                                : '<button class="pf-btn peligro chico" data-anular="' + pg.id + '">Anular</button>') + '</td></tr>';
                    }).join('') + '</tbody></table></div>' : '<p class="pf-ayuda">Sin pagos registrados.</p>');
            // Precio de lista del plan y ciclo elegidos (el pactado se puede escribir encima)
            var fPlan = $('f-plan');
            var precioLista = function () {
                var plan = j.planes.filter(function (x) { return String(x.id) === String(fPlan.plan_id.value); })[0];
                if (!plan) return;
                var lista = fPlan.ciclo.value === 'anual' ? plan.precio_anual : plan.precio_mensual;
                fPlan.precio.placeholder = lista;
                var ayuda = fPlan.precio.parentNode.querySelector('small');
                if (ayuda) ayuda.textContent = 'vacío = precio de lista (' + dinero(lista) + (fPlan.ciclo.value === 'anual' ? '/año' : '/mes') + ')';
            };
            fPlan.plan_id.addEventListener('change', precioLista);
            fPlan.ciclo.addEventListener('change', precioLista);
            precioLista();
            var fPago = $('f-pago');
            fPago.tipo.addEventListener('change', function () {
                var esPago = this.value === 'pago';
                fPago.querySelector('[data-solo-pago]').hidden = !esPago;
                fPago.monto.placeholder = esPago ? p.por_ciclo : 'Monto';
                fPago.monto.required = !esPago;
                fPago.querySelector('[type=submit]').innerHTML = esPago ? '<i class="fas fa-check"></i> Registrar pago y activar'
                    : '<i class="fas fa-check"></i> Registrar ' + (this.value === 'abono' ? 'abono' : 'nota de crédito');
            });
            $('f-plan').addEventListener('submit', function (ev) {
                ev.preventDefault();
                var d = datosForm(this);
                enviar(this, api('plan', null, { id: e.id, plan_id: d.plan_id, ciclo: d.ciclo, precio: d.precio }), 'Plan actualizado', e.id);
            });
            $('f-pago').addEventListener('submit', function (ev) {
                ev.preventDefault();
                var d = Object.assign({ id: e.id }, datosForm(this));
                enviar(this, api('pago', null, d).then(function (r) {
                    toast(r.tipo === 'abono' ? 'Abono de ' + dinero(r.monto) + ' registrado'
                        : r.tipo === 'nota_credito' ? 'Nota de crédito por ' + dinero(-r.monto) + ' registrada'
                        : 'Pago de ' + dinero(r.monto) + ' registrado: al corriente hasta ' + fecha(r.cubre_hasta));
                }), null, e.id);
            });
            panel.querySelectorAll('[data-anular]').forEach(function (b) {
                b.addEventListener('click', function () { dialogoAnular(parseInt(b.getAttribute('data-anular'), 10), e.id); });
            });
            break;

        case 'datos':
            panel.innerHTML = '<form class="pf-form" id="f-datos">' +
                '<label class="ancho">Nombre de la empresa<input name="nombre" required maxlength="150" value="' + esc(e.nombre) + '"></label>' +
                '<label>Persona de contacto<input name="contacto_nombre" maxlength="150" value="' + esc(e.contacto_nombre || '') + '"></label>' +
                '<label>Teléfono<input name="contacto_telefono" maxlength="50" value="' + esc(e.contacto_telefono || '') + '"></label>' +
                '<label class="ancho">Correo de contacto y cobranza<input name="contacto_correo" type="email" maxlength="150" value="' + esc(e.contacto_correo || '') + '"></label>' +
                '<label>Razón social <small>para facturar</small><input name="razon_social" maxlength="200" value="' + esc(e.razon_social || '') + '"></label>' +
                '<label>RFC<input name="rfc" maxlength="20" value="' + esc(e.rfc || '') + '" style="text-transform:uppercase"></label>' +
                '<div class="pf-form-acciones"><button type="submit" class="pf-btn"><i class="fas fa-save"></i> Guardar datos</button></div></form>';
            $('f-datos').addEventListener('submit', function (ev) {
                ev.preventDefault();
                enviar(this, api('datos', null, Object.assign({ id: e.id }, datosForm(this))), 'Datos actualizados', e.id);
            });
            break;

        case 'notas':
            panel.innerHTML = '<form class="pf-form una" id="f-nota"><label>Nueva nota interna <small>solo la ve tu equipo de plataforma</small>' +
                '<textarea name="nota" rows="3" required placeholder="Llamada de seguimiento, acuerdos, próximos pasos…"></textarea></label>' +
                '<div class="pf-form-acciones"><button type="submit" class="pf-btn"><i class="fas fa-plus"></i> Agregar nota</button></div></form>' +
                (j.notas.length ? '<ul class="pf-linea" style="margin-top:12px">' + j.notas.map(function (n) {
                    return '<li><span class="pf-punto"><i class="fas fa-sticky-note"></i></span><div class="pf-texto">' +
                        '<div class="pf-meta">' + fechaHora(n.creado_en) + ' · ' + esc(n.autor || '') + '</div><div class="pf-nota-texto">' + esc(n.nota) + '</div></div></li>';
                }).join('') + '</ul>' : '<p class="pf-ayuda" style="margin-top:12px">Sin notas todavía.</p>');
            $('f-nota').addEventListener('submit', function (ev) {
                ev.preventDefault();
                enviar(this, api('nota', null, { id: e.id, nota: this.nota.value }), 'Nota agregada', e.id);
            });
            break;

        case 'marca':
            var m = e.marca || {}, col = m.colores || {};
            panel.innerHTML = '<form class="pf-form" id="f-marca">' +
                '<label>Color principal<input name="primario" type="color" value="' + esc(col.primario || '#3498db') + '"></label>' +
                '<label>Color secundario<input name="secundario" type="color" value="' + esc(col.secundario || '#1a4de4') + '"></label>' +
                '<label>Barra lateral<input name="sidebar" type="color" value="' + esc(col.sidebar || '#2c3e50') + '"></label>' +
                '<label>Prefijo de folio<input name="prefijo_folio" maxlength="6" value="' + esc(m.prefijo_folio || 'SD') + '"></label>' +
                '<label class="ancho">Zona horaria<select name="zona_horaria">' + (window.AppZonas ? AppZonas.opciones(m.zona_horaria || 'America/Mexico_City')
                    : '<option>' + esc(m.zona_horaria || 'America/Mexico_City') + '</option>') + '</select></label>' +
                '<label class="ancho">Categorías de orden de servicio <small>una por línea</small><textarea name="categorias" rows="6">' + esc((m.categorias || []).join('\n')) + '</textarea></label>' +
                '<div class="pf-form-acciones"><button type="submit" class="pf-btn"><i class="fas fa-save"></i> Guardar marca</button></div></form>';
            $('f-marca').addEventListener('submit', function (ev) {
                ev.preventDefault();
                var d = datosForm(this);
                enviar(this, api('marca', null, {
                    id: e.id, prefijo_folio: d.prefijo_folio, zona_horaria: d.zona_horaria,
                    colores: { primario: d.primario, secundario: d.secundario, sidebar: d.sidebar }, categorias: d.categorias.split('\n')
                }), 'Marca actualizada', e.id);
            });
            break;

        case 'usuarios':
            panel.innerHTML = (j.limite_usuarios ? '<p class="pf-ayuda" style="margin-bottom:10px">Su plan permite hasta ' + j.limite_usuarios + ' usuarios.</p>' : '') +
                '<div class="pf-tabla-wrap"><table class="pf-tabla"><thead><tr><th>Nombre</th><th>Usuario</th><th>Rol</th><th>Alta</th></tr></thead><tbody>' +
                (j.usuarios.map(function (u) {
                    return '<tr><td>' + esc(u.nombre) + '</td><td>' + esc(u.usuario) + '</td><td>' + esc(u.rol) + '</td><td>' + fecha(u.fecha_creacion) + '</td></tr>';
                }).join('') || '<tr><td colspan="4" class="pf-vacio">Sin usuarios.</td></tr>') + '</tbody></table></div>';
            break;

        case 'bitacora':
            panel.innerHTML = j.eventos.length ? '<ul class="pf-linea">' + j.eventos.map(function (ev) { return itemEvento(ev, false); }).join('') + '</ul>'
                : '<p class="pf-ayuda">Sin eventos.</p>';
            break;
        }
    }

    function enviar(form, promesa, mensaje, idFicha) {
        var boton = form.querySelector('[type=submit]');
        if (boton) boton.disabled = true;
        promesa.then(function () {
            if (mensaje) toast(mensaje);
            recargarTodo(idFicha);
        }).catch(function (e) {
            toast(e.message, 'error');
            if (boton) boton.disabled = false;
        });
    }

    function dialogoAnular(pagoId, idEmpresa) {
        var m = dialogo('Anular pago',
            '<form class="pf-form una"><p class="pf-ayuda">El pago queda en el historial marcado como anulado y el vencimiento de la cuenta se recalcula con sus pagos vigentes.</p>' +
            '<label>Motivo<input name="motivo" required maxlength="255" placeholder="Error de captura, pago devuelto…"></label>' +
            '<div class="pf-form-acciones"><button type="button" class="pf-btn sec" data-cerrar-capa>Cancelar</button>' +
            '<button type="submit" class="pf-btn peligro">Anular pago</button></div></form>');
        m.querySelector('form').addEventListener('submit', function (ev) {
            ev.preventDefault();
            enviar(this, api('anular_pago', null, { pago_id: pagoId, motivo: this.motivo.value }), 'Pago anulado', idEmpresa);
        });
    }

    function accionFicha(j, b) {
        var e = j.empresa, a = b.getAttribute('data-a');
        if (a === 'pestana') {
            pestanaActual = b.getAttribute('data-p');
            pintarFicha(j);
            return;
        }
        if (a === 'soporte') {
            confirmar('Entrar como soporte', 'Entrarás al sistema de ' + e.nombre + ' con acceso total para ayudarle. Quedará registrado en la bitácora y verás un botón para volver a este panel.', 'Entrar', false)
                .then(function (si) {
                    if (!si) return;
                    api('soporte', null, { id: e.id }).then(function (r) { window.location.href = r.redirect; })
                        .catch(function (err) { toast(err.message, 'error'); });
                });
            return;
        }
        if (a === 'extender') {
            var m = dialogo('Extender prueba de ' + e.nombre,
                '<form class="pf-form una"><label>Días adicionales<select name="dias"><option>7</option><option selected>15</option><option>30</option></select></label>' +
                '<p class="pf-ayuda">Se suman a la fecha límite vigente (o a hoy, si ya venció). La cuenta queda en prueba.</p>' +
                '<div class="pf-form-acciones"><button type="button" class="pf-btn sec" data-cerrar-capa>Cancelar</button>' +
                '<button type="submit" class="pf-btn">Extender</button></div></form>');
            m.querySelector('form').addEventListener('submit', function (ev) {
                ev.preventDefault();
                enviar(this, api('extender_prueba', null, { id: e.id, dias: parseInt(this.dias.value, 10) })
                    .then(function (r) { toast('Prueba extendida hasta ' + fecha(r.prueba_hasta)); }), null, e.id);
            });
            return;
        }
        if (a === 'estado') {
            var nuevo = b.getAttribute('data-e');
            var textos = {
                suspendida: ['Suspender cuenta', 'Nadie de ' + e.nombre + ' podrá entrar hasta que la reactives. Sus datos se conservan.', 'Suspender', true],
                cancelada: ['Cancelar cuenta', 'Se bloquea el acceso de ' + e.nombre + '. Antes de eliminarla, entrégale su exportación de datos (lo pide el contrato).', 'Cancelar cuenta', true],
                activa: ['Reactivar cuenta', e.nombre + ' recupera el acceso con todos sus datos.', 'Reactivar', false]
            }[nuevo];
            confirmar(textos[0], textos[1], textos[2], textos[3]).then(function (si) {
                if (!si) return;
                api('estado', null, { id: e.id, estado: nuevo }).then(function () { toast('Estado actualizado'); recargarTodo(e.id); })
                    .catch(function (err) { toast(err.message, 'error'); });
            });
            return;
        }
        if (a === 'eliminar') {
            var md = dialogo('Eliminar definitivamente',
                '<form class="pf-form una"><div class="pf-aviso peligro">Se borran para siempre sus datos, usuarios, pagos y archivos. No se puede deshacer.</div>' +
                '<label>Para confirmar escribe <code>' + esc(e.slug) + '</code><input name="confirmacion" required autocomplete="off"></label>' +
                '<div class="pf-form-acciones"><button type="button" class="pf-btn sec" data-cerrar-capa>Cancelar</button>' +
                '<button type="submit" class="pf-btn peligro"><i class="fas fa-trash"></i> Eliminar</button></div></form>');
            md.querySelector('form').addEventListener('submit', function (ev) {
                ev.preventDefault();
                var boton = this.querySelector('[type=submit]');
                boton.disabled = true;
                api('eliminar', null, { id: e.id, confirmacion: this.confirmacion.value }).then(function () {
                    toast('Empresa eliminada');
                    cerrarCapas();
                    recargarTodo(null);
                }).catch(function (err) { toast(err.message, 'error'); boton.disabled = false; });
            });
        }
    }

    // ================================================================ SOLICITUDES DE PAGO
    var ESTADO_SOLICITUD = { pendiente: ['Por verificar', 'warn'], confirmada: ['Confirmada', 'ok'], rechazada: ['Rechazada', 'peligro'], cancelada: ['Cancelada por el cliente', 'neutra'] };

    VISTAS.solicitudes = function () {
        var todas = (location.hash.split('?')[1] || '') === 'todas';
        cabecera('Solicitudes de pago', 'Clientes que eligieron plan y transfirieron: confirma cuando veas el depósito en tu banco',
            '<a class="pf-btn sec" href="#solicitudes' + (todas ? '' : '?todas') + '">' + (todas ? 'Solo por verificar' : 'Ver historial') + '</a>');
        api('solicitudes', { estado: todas ? 'todas' : '' }).then(function (j) {
            var aviso = j.con_datos_pago ? '' : '<div class="pf-aviso warn"><i class="fas fa-exclamation-triangle"></i> Aún no capturas tus datos bancarios: los clientes no sabrán a dónde transferir. ' +
                '<a href="#configuracion">Ir a Configuración</a></div>';
            if (!j.solicitudes.length) {
                $('pf-vista').innerHTML = aviso + '<div class="pf-tarjeta"><p class="pf-ayuda">' +
                    (todas ? 'Todavía no hay solicitudes de contratación.' : 'No hay pagos por verificar. 🎉') + '</p></div>';
                return;
            }
            $('pf-vista').innerHTML = aviso + '<div class="pf-tarjeta"><div class="pf-tabla-wrap"><table class="pf-tabla"><thead><tr>' +
                '<th>Empresa</th><th>Referencia</th><th>Plan</th><th class="num">Monto</th><th>Factura</th><th>Comprobante</th><th>Estado</th><th></th></tr></thead><tbody>' +
                j.solicitudes.map(function (s) {
                    var c = s.cuenta || {}, est = ESTADO_SOLICITUD[s.estado] || [s.estado, 'neutra'];
                    return '<tr><td><div class="pf-empresa-celda" data-ficha="' + s.empresa_id + '" title="Abrir ficha">' + avatar(s.empresa || '?', c.color) + '<div><strong>' + esc(s.empresa || '(eliminada)') + '</strong>' +
                        '<span class="pf-sub">' + (c.estado ? (SITUACION[c.estado] || [c.estado])[0] + ' · ' : '') + esc(s.solicitado_por || '') + '</span></div></div></td>' +
                        '<td><strong>' + esc(s.referencia) + '</strong><span class="pf-sub">' + fechaHora(s.creado_en) + '</span></td>' +
                        '<td>' + esc(s.plan || '—') + '<span class="pf-sub">' + (s.ciclo === 'anual' ? 'Anual' : 'Mensual') + '</span></td>' +
                        '<td class="num">' + dinero(s.monto) + '</td>' +
                        '<td>' + (+s.requiere_factura ? esc(s.rfc) + '<span class="pf-sub">' + esc(s.razon_social) + '</span>' : '<span class="pf-sub">No requiere</span>') + '</td>' +
                        '<td>' + (s.tiene_comprobante ? '<a class="pf-btn sec chico" href="' + API + '?accion=comprobante&id=' + s.id + '" target="_blank" rel="noopener"><i class="fas fa-paperclip"></i> Ver</a>' : '<span class="pf-sub">Sin comprobante</span>') + '</td>' +
                        '<td><span class="pf-etq ' + est[1] + '">' + est[0] + '</span>' +
                        (s.estado === 'pendiente' && s.acceso_hasta ? '<span class="pf-sub">Acceso provisional al ' + fecha(s.acceso_hasta) + '</span>' : '') +
                        (s.motivo ? '<span class="pf-sub">' + esc(s.motivo) + '</span>' : '') +
                        (s.resuelto_en && s.estado !== 'pendiente' ? '<span class="pf-sub">' + fechaHora(s.resuelto_en) + ' · ' + esc(s.resuelto_por || '') + '</span>' : '') + '</td>' +
                        '<td class="num">' + (s.estado === 'pendiente'
                            ? '<button class="pf-btn ok chico" data-confirmar="' + s.id + '"><i class="fas fa-check"></i> Confirmar</button> ' +
                              '<button class="pf-btn peligro chico" data-rechazar="' + s.id + '">Rechazar</button>' : '') + '</td></tr>';
                }).join('') + '</tbody></table></div></div>' +
                '<p class="pf-ayuda pf-espacio">Confirma solo cuando el depósito ya aparezca en tu banco con su referencia. Al confirmar se registra el pago, se aplica el plan elegido y la cuenta queda activa.</p>';
            var buscar = function (id) { return j.solicitudes.filter(function (s) { return String(s.id) === String(id); })[0]; };
            $('pf-vista').querySelectorAll('[data-ficha]').forEach(function (d) {
                d.addEventListener('click', function () { abrirFicha(parseInt(d.getAttribute('data-ficha'), 10), 'facturacion'); });
            });
            $('pf-vista').querySelectorAll('[data-confirmar]').forEach(function (b) {
                b.addEventListener('click', function () { dialogoConfirmarSolicitud(buscar(b.getAttribute('data-confirmar')), j); });
            });
            $('pf-vista').querySelectorAll('[data-rechazar]').forEach(function (b) {
                b.addEventListener('click', function () { dialogoRechazarSolicitud(buscar(b.getAttribute('data-rechazar'))); });
            });
        }).catch(error);
    };

    function refrescarInsignias() {
        api('resumen').then(function (j) { insignias(j.resumen); }).catch(function () {});
    }

    function dialogoConfirmarSolicitud(s, j) {
        var c = s.cuenta || {};
        var fiscal = +s.requiere_factura
            ? '<div class="pf-datos" style="margin-bottom:14px">' +
              '<div class="pf-dato"><span>Facturar a</span><strong style="font-size:14px">' + esc(s.razon_social) + '</strong><small>' + esc(s.rfc) + ' · C.P. ' + esc(s.codigo_postal) + '</small></div>' +
              '<div class="pf-dato"><span>Régimen y uso</span><strong style="font-size:14px">' + esc(s.regimen_fiscal + ' ' + (j.regimenes[s.regimen_fiscal] || '')) + '</strong><small>' +
              esc(s.uso_cfdi + ' ' + (j.usos_cfdi[s.uso_cfdi] || '')) + ' · ' + esc(s.correo_factura) + '</small></div></div>'
            : '<p class="pf-ayuda">El cliente no pidió factura.</p>';
        var periodo = c.estado === 'prueba' && c.prueba_hasta
            ? 'Su prueba sigue vigente: el periodo pagado empezará al terminar la prueba (' + fecha(c.prueba_hasta) + ').'
            : 'Se suma un ' + (s.ciclo === 'anual' ? 'año' : 'mes') + ' a su vencimiento vigente (o a hoy, si ya venció).';
        var m = dialogo('Confirmar pago ' + s.referencia,
            '<form class="pf-form">' +
            '<div class="pf-aviso info ancho">' + esc(s.empresa) + ' contrata <strong>' + esc(s.plan) + '</strong> ' + (s.ciclo === 'anual' ? 'anual' : 'mensual') + '. ' + periodo + '</div>' +
            '<div class="ancho">' + fiscal + '</div>' +
            '<label>Monto recibido<input name="monto" type="number" min="0" step="0.01" value="' + esc(s.monto) + '" required></label>' +
            '<label>Fecha del depósito<input name="fecha_pago" type="date" value="' + hoyISO() + '" required></label>' +
            '<label>Factura (CFDI) <small>puedes agregarla después</small><input name="factura" maxlength="60" placeholder="Folio o UUID"></label>' +
            '<label>Cubre hasta <small>vacío = automático</small><input name="cubre_hasta" type="date"></label>' +
            '<label class="ancho">Notas<input name="notas" maxlength="200" placeholder="Banco, hora del depósito…"></label>' +
            '<div class="pf-form-acciones"><button type="button" class="pf-btn sec" data-cerrar-capa>Cancelar</button>' +
            '<button type="submit" class="pf-btn ok"><i class="fas fa-check"></i> Confirmar pago y activar</button></div></form>');
        m.querySelector('form').addEventListener('submit', function (ev) {
            ev.preventDefault();
            var boton = this.querySelector('[type=submit]');
            boton.disabled = true;
            api('confirmar_solicitud', null, Object.assign({ id: s.id }, datosForm(this))).then(function (r) {
                toast('Pago confirmado: ' + s.empresa + ' activa hasta ' + fecha(r.cubre_hasta));
                cerrarCapas();
                refrescarInsignias();
                VISTAS.solicitudes();
            }).catch(function (err) { toast(err.message, 'error'); boton.disabled = false; });
        });
    }

    function dialogoRechazarSolicitud(s) {
        var m = dialogo('Rechazar solicitud ' + s.referencia,
            '<form class="pf-form una"><p class="pf-ayuda">El cliente verá el motivo al entrar a Contratar plan y podrá enviar una nueva solicitud. Se retira su acceso provisional.</p>' +
            '<label>Motivo<input name="motivo" required maxlength="255" placeholder="No encontramos el depósito, el monto no coincide…"></label>' +
            '<div class="pf-form-acciones"><button type="button" class="pf-btn sec" data-cerrar-capa>Cancelar</button>' +
            '<button type="submit" class="pf-btn peligro">Rechazar</button></div></form>');
        m.querySelector('form').addEventListener('submit', function (ev) {
            ev.preventDefault();
            var boton = this.querySelector('[type=submit]');
            boton.disabled = true;
            api('rechazar_solicitud', null, { id: s.id, motivo: this.motivo.value }).then(function () {
                toast('Solicitud rechazada');
                cerrarCapas();
                refrescarInsignias();
                VISTAS.solicitudes();
            }).catch(function (err) { toast(err.message, 'error'); boton.disabled = false; });
        });
    }

    // ================================================================ COBRANZA
    VISTAS.cobranza = function () {
        var mes = new URLSearchParams(location.hash.split('?')[1] || '').get('mes') || hoyISO().slice(0, 7);
        cabecera('Cobranza', 'Lo cobrado, lo pendiente y lo que viene',
            '<input type="month" class="pf-campo" id="c-mes" value="' + mes + '" style="width:auto">' +
            '<a class="pf-btn sec" id="c-csv"><i class="fas fa-file-csv"></i> Pagos del mes (CSV)</a>');
        var actualizarCsv = function (m) {
            var ultimo = new Date(m.split('-')[0], m.split('-')[1], 0).getDate();
            $('c-csv').href = API + '?accion=csv_pagos&desde=' + m + '-01&hasta=' + m + '-' + String(ultimo).padStart(2, '0');
        };
        actualizarCsv(mes);
        $('c-mes').addEventListener('change', function () { location.hash = 'cobranza?mes=' + this.value; });
        api('cobranza', { mes: mes }).then(function (j) {
            var k = j.kpis;
            var tablaCuentas = function (lista, tipo) {
                if (!lista.length) return '<p class="pf-ayuda">' + (tipo === 'cobrar' ? 'Ninguna cuenta con pago vencido. 🎉' : 'Sin renovaciones en los próximos 30 días.') + '</p>';
                return '<div class="pf-tabla-wrap"><table class="pf-tabla"><thead><tr><th>Empresa</th><th>' + (tipo === 'cobrar' ? 'Situación' : 'Vence') +
                    '</th><th class="num">Monto</th><th>Contacto</th><th></th></tr></thead><tbody>' + lista.map(function (e) {
                    return '<tr class="clic" data-id="' + e.id + '" data-pestana="facturacion"><td><div class="pf-empresa-celda">' + avatar(e.nombre, e.color) + '<div><strong>' + esc(e.nombre) + '</strong>' +
                        '<span class="pf-sub">' + esc(e.plan) + ' · ' + (e.ciclo === 'anual' ? 'anual' : 'mensual') + '</span></div></div></td>' +
                        '<td>' + (tipo === 'cobrar' ? etqSituacion(e) + '<span class="pf-sub">Venció ' + fecha(e.pagado_hasta) + '</span>' : fecha(e.pagado_hasta)) + '</td>' +
                        '<td class="num">' + dinero(e.precio_ciclo) + '</td>' +
                        '<td>' + esc(e.contacto_nombre || '—') + '<span class="pf-sub">' + esc(e.contacto_telefono || e.contacto_correo || '') + '</span></td>' +
                        '<td class="num"><span class="pf-btn chico ' + (tipo === 'cobrar' ? 'ok' : 'sec') + '">Registrar pago</span></td></tr>';
                }).join('') + '</tbody></table></div>';
            };
            $('pf-vista').innerHTML =
                '<div class="pf-rejilla kpis">' +
                '<div class="pf-kpi ok"><div class="pf-kpi-etiqueta"><i class="fas fa-wallet"></i> Cobrado en el mes</div><div class="pf-kpi-valor">' + dinero(k.cobrado, 0) + '</div><div class="pf-kpi-nota">' + k.pagos + ' pagos' + (k.anulados ? ' · ' + k.anulados + ' anulados' : '') + '</div></div>' +
                '<div class="pf-kpi ' + (k.cuentas_por_cobrar ? 'warn' : '') + '"><div class="pf-kpi-etiqueta"><i class="fas fa-exclamation-circle"></i> Por cobrar (vencido)</div><div class="pf-kpi-valor">' + dinero(k.por_cobrar, 0) + '</div><div class="pf-kpi-nota">' + k.cuentas_por_cobrar + ' cuentas</div></div>' +
                '<div class="pf-kpi info"><div class="pf-kpi-etiqueta"><i class="fas fa-calendar-alt"></i> Renovaciones (30 días)</div><div class="pf-kpi-valor">' + dinero(k.renovaciones_30, 0) + '</div><div class="pf-kpi-nota">' + k.cuentas_renovacion + ' cuentas</div></div>' +
                '<div class="pf-kpi ' + (k.sin_factura ? 'warn' : '') + '"><div class="pf-kpi-etiqueta"><i class="fas fa-file-invoice"></i> Pagos sin factura</div><div class="pf-kpi-valor">' + k.sin_factura + '</div><div class="pf-kpi-nota">Del mes seleccionado</div></div>' +
                '</div>' +
                '<div class="pf-tarjeta"><h3>Cuentas con pago vencido <small>de mayor a menor atraso</small></h3>' + tablaCuentas(j.por_cobrar, 'cobrar') + '</div>' +
                '<div class="pf-tarjeta pf-espacio"><h3>Próximas renovaciones <small>vencen en 30 días</small></h3>' + tablaCuentas(j.proximos, 'proximos') + '</div>' +
                '<div class="pf-tarjeta pf-espacio"><h3>Pagos de ' + etiquetaMes(j.mes) + '</h3>' +
                (j.pagos.length ? '<div class="pf-tabla-wrap"><table class="pf-tabla"><thead><tr><th>Fecha</th><th>Empresa</th><th class="num">Monto</th><th>Cubre hasta</th><th>Método</th><th>Factura</th><th></th></tr></thead><tbody>' +
                    j.pagos.map(function (pg) {
                        var anulado = +pg.anulado;
                        return '<tr class="' + (anulado ? 'anulado' : '') + '"><td>' + fecha(pg.fecha_pago) + '</td><td>' + esc(pg.empresa || '(eliminada)') +
                            '<span class="pf-sub">' + esc(pg.rfc || '') + '</span></td><td class="num">' + dinero(pg.monto) + '</td><td>' + fecha(pg.cubre_hasta) + '</td>' +
                            '<td>' + esc(pg.metodo || '—') + '<span class="pf-sub">' + esc(pg.referencia || '') + '</span></td>' +
                            '<td>' + (pg.factura ? esc(pg.factura) : (anulado ? '—' : '<span class="pf-etq warn">Sin factura</span>')) + '</td>' +
                            '<td class="num">' + (anulado ? '<span class="pf-etq neutra" title="' + esc(pg.anulado_motivo) + '">Anulado</span>'
                                : '<button class="pf-btn peligro chico" data-anular="' + pg.id + '">Anular</button>') + '</td></tr>';
                    }).join('') + '</tbody></table></div>' : '<p class="pf-ayuda">Sin pagos en este mes.</p>') + '</div>';
            $('pf-vista').querySelectorAll('[data-anular]').forEach(function (b) {
                b.addEventListener('click', function () { dialogoAnular(parseInt(b.getAttribute('data-anular'), 10), null); });
            });
        }).catch(error);
    };

    // ================================================================ PRUEBAS
    VISTAS.pruebas = function () {
        cabecera('Pruebas', 'Embudo de conversión: quién está probando y a quién dar seguimiento',
            '<button type="button" class="pf-btn" id="btn-nueva"><i class="fas fa-plus"></i> Nueva prueba</button>');
        $('btn-nueva').addEventListener('click', dialogoNuevaEmpresa);
        api('pruebas').then(function (j) {
            var f = j.embudo, tasa = f.registros ? Math.round(f.convertidas * 100 / f.registros) : 0;
            var diasPrueba = function (e) {
                var total = 15, restan = Math.max(0, e.dias || 0);
                return '<strong>' + restan + ' días</strong><div class="pf-barra-prog"><i style="width:' + Math.min(100, Math.round(restan * 100 / total)) + '%;' +
                    (restan <= 3 ? 'background:var(--pf-warn)' : '') + '"></i></div>';
            };
            var tabla = function (lista, vencidas) {
                if (!lista.length) return '<p class="pf-ayuda">' + (vencidas ? 'Ninguna prueba vencida sin convertir.' : 'No hay pruebas en curso.') + '</p>';
                return '<div class="pf-tabla-wrap"><table class="pf-tabla"><thead><tr><th>Empresa</th><th>' + (vencidas ? 'Venció' : 'Quedan') + '</th><th>Uso</th><th class="num">Usuarios</th><th>Contacto</th><th></th></tr></thead><tbody>' +
                    lista.map(function (e) {
                        return '<tr class="clic" data-id="' + e.id + '"><td><div class="pf-empresa-celda">' + avatar(e.nombre, e.color) + '<div><strong>' + esc(e.nombre) + '</strong><span class="pf-sub">Alta ' + fecha(e.creado_en) + '</span></div></div></td>' +
                            '<td style="min-width:120px">' + (vencidas ? fecha(e.prueba_hasta) : diasPrueba(e)) + '</td>' +
                            '<td>' + etqSalud(e) + '<span class="pf-sub">' + relativo(e.ultima_actividad) + '</span></td>' +
                            '<td class="num">' + e.usuarios + '</td>' +
                            '<td>' + esc(e.contacto_nombre || '—') + '<span class="pf-sub">' + esc(e.contacto_telefono || e.contacto_correo || '') + '</span></td>' +
                            '<td class="num"><button class="pf-btn sec chico" data-extender="' + e.id + '">' + (vencidas ? 'Reabrir +7 días' : '+7 días') + '</button></td></tr>';
                    }).join('') + '</tbody></table></div>';
            };
            $('pf-vista').innerHTML =
                '<div class="pf-tarjeta"><h3>Embudo <small>' + tasa + '% de los registros terminó pagando</small></h3><div class="pf-embudo">' +
                '<div><strong>' + f.registros + '</strong><span>Registros</span></div>' +
                '<div><strong>' + f.con_uso + '</strong><span>Usaron el sistema</span></div>' +
                '<div><strong style="color:var(--pf-ok)">' + f.convertidas + '</strong><span>Pagaron</span></div>' +
                '<div><strong style="color:var(--pf-danger)">' + f.perdidas + '</strong><span>Perdidas</span></div></div></div>' +
                '<div class="pf-tarjeta pf-espacio"><h3>Pruebas en curso <small>' + f.en_curso + ' · las que vencen antes, primero</small></h3>' + tabla(j.activas, false) + '</div>' +
                '<div class="pf-tarjeta pf-espacio"><h3>Vencidas sin convertir <small>oportunidades de recuperación</small></h3>' + tabla(j.vencidas, true) + '</div>';
            $('pf-vista').querySelectorAll('[data-extender]').forEach(function (b) {
                b.addEventListener('click', function () {
                    b.disabled = true;
                    api('extender_prueba', null, { id: parseInt(b.getAttribute('data-extender'), 10), dias: 7 })
                        .then(function (r) { toast('Prueba extendida hasta ' + fecha(r.prueba_hasta)); VISTAS.pruebas(); })
                        .catch(function (err) { toast(err.message, 'error'); b.disabled = false; });
                });
            });
        }).catch(error);
    };

    // ================================================================ PLANES
    VISTAS.planes = function () {
        cabecera('Planes y precios', 'Catálogo comercial: precios y límites que se aplican a cada empresa',
            '<button type="button" class="pf-btn" id="btn-plan"><i class="fas fa-plus"></i> Nuevo plan</button>');
        $('btn-plan').addEventListener('click', function () { dialogoPlan(null); });
        api('planes').then(function (j) {
            $('pf-vista').innerHTML = '<div class="pf-planes">' + j.planes.map(function (p) {
                var ahorro = p.precio_mensual > 0 ? Math.round((1 - p.precio_anual / (p.precio_mensual * 12)) * 100) : 0;
                return '<div class="pf-plan' + (+p.activo ? '' : ' inactivo') + '"><h3>' + esc(p.nombre) + (+p.activo ? '' : ' <span class="pf-etq neutra">Inactivo</span>') + '</h3>' +
                    '<div class="pf-precio">' + dinero(p.precio_mensual, 0) + ' <small>/ mes</small></div>' +
                    '<div class="pf-ayuda">' + dinero(p.precio_anual, 0) + ' / año' + (ahorro > 0 ? ' · ahorra ' + ahorro + '%' : '') + '</div>' +
                    '<ul><li><i class="fas fa-check"></i> ' + (p.max_usuarios ? 'Hasta ' + p.max_usuarios + ' usuarios' : 'Usuarios ilimitados') + '</li>' +
                    (p.permite_marca === undefined ? '' : +p.permite_marca
                        ? '<li><i class="fas fa-check"></i> Personaliza logo y colores</li>'
                        : '<li><i class="fas fa-times" style="color:var(--pf-text-2)"></i> Sin personalización de marca</li>') +
                    (p.descripcion ? '<li><i class="fas fa-check"></i> ' + esc(p.descripcion) + '</li>' : '') +
                    '<li><i class="fas fa-building" style="color:var(--pf-accent)"></i> ' + p.empresas + ' empresas en este plan</li></ul>' +
                    '<div style="margin-top:auto"><button class="pf-btn sec" data-plan="' + p.id + '"><i class="fas fa-pen"></i> Editar</button></div></div>';
            }).join('') + '</div><p class="pf-ayuda pf-espacio">Cambiar un precio de lista no modifica a las empresas con precio pactado. El límite de usuarios se aplica al dar de alta usuarios.</p>';
            $('pf-vista').querySelectorAll('[data-plan]').forEach(function (b) {
                b.addEventListener('click', function () {
                    dialogoPlan(j.planes.find(function (p) { return String(p.id) === b.getAttribute('data-plan'); }));
                });
            });
        }).catch(error);
    };
    function dialogoPlan(p) {
        p = p || { activo: 1, orden: 9 };
        var m = dialogo(p.id ? 'Editar plan ' + p.nombre : 'Nuevo plan',
            '<form class="pf-form">' +
            '<label>Nombre<input name="nombre" required maxlength="80" value="' + esc(p.nombre || '') + '"></label>' +
            '<label>Clave <small>identificador interno</small><input name="clave" maxlength="30" value="' + esc(p.clave || '') + '"></label>' +
            '<label class="ancho">Descripción<input name="descripcion" maxlength="255" value="' + esc(p.descripcion || '') + '"></label>' +
            '<label>Precio mensual<input name="precio_mensual" type="number" min="0" step="0.01" required value="' + esc(p.precio_mensual || '') + '"></label>' +
            '<label>Precio anual<input name="precio_anual" type="number" min="0" step="0.01" required value="' + esc(p.precio_anual || '') + '"></label>' +
            '<label>Máximo de usuarios <small>vacío = ilimitados</small><input name="max_usuarios" type="number" min="1" value="' + esc(p.max_usuarios || '') + '"></label>' +
            '<label>Orden<input name="orden" type="number" value="' + esc(p.orden) + '"></label>' +
            '<label class="pf-check ancho"><input type="checkbox" name="activo"' + (+p.activo ? ' checked' : '') + '> Disponible para nuevas contrataciones</label>' +
            (p.id && p.permite_marca === undefined ? '' : '<label class="pf-check ancho"><input type="checkbox" name="permite_marca"' +
                (p.permite_marca === undefined || +p.permite_marca ? ' checked' : '') + '> El cliente puede personalizar su logo, colores y textos</label>') +
            '<div class="pf-form-acciones"><button type="button" class="pf-btn sec" data-cerrar-capa>Cancelar</button>' +
            '<button type="submit" class="pf-btn"><i class="fas fa-save"></i> Guardar plan</button></div></form>');
        m.querySelector('form').addEventListener('submit', function (ev) {
            ev.preventDefault();
            var d = datosForm(this);
            d.activo = this.activo.checked ? 1 : 0;
            if (this.permite_marca) d.permite_marca = this.permite_marca.checked ? 1 : 0;
            if (p.id) d.id = p.id;
            var boton = this.querySelector('[type=submit]');
            boton.disabled = true;
            api('guardar_plan', null, d).then(function () { toast('Plan guardado'); cerrarCapas(); VISTAS.planes(); })
                .catch(function (err) { toast(err.message, 'error'); boton.disabled = false; });
        });
    }

    // ================================================================ BITÁCORA
    VISTAS.bitacora = function () {
        cabecera('Bitácora', 'Todo lo que pasa en la plataforma: altas, pagos, cambios y accesos de soporte');
        var filtros = new URLSearchParams(location.hash.split('?')[1] || '');
        api('bitacora', { tipo: filtros.get('tipo') || '', empresa_id: filtros.get('empresa') || '', desde: filtros.get('desde') || '' }).then(function (j) {
            $('pf-vista').innerHTML = '<div class="pf-filtros">' +
                '<select id="b-tipo"><option value="">Toda acción</option>' + j.tipos.map(function (t) {
                    return '<option value="' + esc(t) + '"' + (t === filtros.get('tipo') ? ' selected' : '') + '>' + esc((EVENTOS[t] || [0, 0, t])[2]) + '</option>'; }).join('') + '</select>' +
                '<select id="b-empresa"><option value="">Toda empresa</option>' + j.empresas.map(function (e) {
                    return '<option value="' + e.id + '"' + (String(e.id) === filtros.get('empresa') ? ' selected' : '') + '>' + esc(e.nombre) + '</option>'; }).join('') + '</select>' +
                '<input type="date" id="b-desde" value="' + esc(filtros.get('desde') || '') + '" style="flex:0 1 170px;width:auto" title="Desde">' +
                '</div><div class="pf-tarjeta">' + (j.eventos.length ? '<ul class="pf-linea">' + j.eventos.map(function (ev) { return itemEvento(ev, true); }).join('') + '</ul>'
                    : '<p class="pf-ayuda">Sin eventos con estos filtros.</p>') + '</div>';
            ['b-tipo', 'b-empresa', 'b-desde'].forEach(function (id) {
                $(id).addEventListener('change', function () {
                    location.hash = 'bitacora?' + new URLSearchParams({ tipo: $('b-tipo').value, empresa: $('b-empresa').value, desde: $('b-desde').value });
                });
            });
        }).catch(error);
    };

    // ================================================================ CONFIGURACIÓN
    VISTAS.configuracion = function () {
        cabecera('Configuración', 'Reglas del negocio: se aplican a todas las empresas sin tocar código');
        api('config').then(function (j) {
            var c = j.config;
            var sel = function (valor, opciones) {
                return opciones.map(function (o) { return '<option value="' + o[0] + '"' + (String(valor) === String(o[0]) ? ' selected' : '') + '>' + o[1] + '</option>'; }).join('');
            };
            $('pf-vista').innerHTML = '<form id="f-config"><div class="pf-rejilla mitad">' +
                '<div class="pf-tarjeta"><h3>Prueba gratuita</h3><div class="pf-form">' +
                '<label>Duración (días)<input name="dias_prueba" type="number" min="1" max="90" value="' + esc(c.dias_prueba) + '"></label>' +
                '<label>Plan durante la prueba <small>sus límites aplican</small><select name="plan_prueba">' + j.planes.map(function (p) {
                    return '<option value="' + p.id + '"' + (String(p.id) === String(c.plan_prueba) ? ' selected' : '') + '>' + esc(p.nombre) + '</option>'; }).join('') + '</select></label></div></div>' +
                '<div class="pf-tarjeta"><h3>Cobranza <small>días de atraso tras el vencimiento</small></h3><div class="pf-form">' +
                '<label>Gracia (acceso total con aviso)<input name="dias_gracia" type="number" min="0" max="60" value="' + esc(c.dias_gracia) + '"></label>' +
                '<label>Hasta este día: solo lectura<input name="dias_solo_lectura" type="number" min="0" max="90" value="' + esc(c.dias_solo_lectura) + '"></label>' +
                '<p class="pf-ayuda ancho" id="cfg-ciclo"></p></div></div>' +
                '<div class="pf-tarjeta"><h3>Registro autoservicio</h3><div class="pf-form">' +
                '<label>Registro de pruebas<select name="registro_abierto">' + sel(c.registro_abierto, [['', 'Según .env (' + (c.registro_abierto_efectivo ? 'abierto' : 'cerrado') + ')'], ['true', 'Abierto'], ['false', 'Cerrado']]) + '</select></label>' +
                '<label>Máximo de registros por IP al día<input name="registro_max_por_ip" type="number" min="1" max="50" value="' + esc(c.registro_max_por_ip) + '" placeholder="' + c.registro_max_por_ip_efectivo + '"></label></div></div>' +
                '<div class="pf-tarjeta"><h3>Contacto comercial <small>aparece en los avisos de cuentas vencidas</small></h3><div class="pf-form">' +
                '<label>Correo<input name="contacto_correo" type="email" value="' + esc(c.contacto_correo) + '" placeholder="ventas@tuempresa.com"></label>' +
                '<label>WhatsApp<input name="contacto_whatsapp" value="' + esc(c.contacto_whatsapp) + '" placeholder="55 1234 5678"></label></div></div>' +
                '<div class="pf-tarjeta"><h3>Pagos por transferencia <small>los ve el cliente al contratar</small></h3><div class="pf-form">' +
                '<label>Banco<input name="pago_banco" maxlength="150" value="' + esc(c.pago_banco) + '" placeholder="BBVA, Santander…"></label>' +
                '<label>Beneficiario<input name="pago_beneficiario" maxlength="150" value="' + esc(c.pago_beneficiario) + '" placeholder="Nombre o razón social"></label>' +
                '<label class="ancho">CLABE <small>18 dígitos</small><input name="pago_clabe" inputmode="numeric" maxlength="22" value="' + esc(c.pago_clabe) + '"></label>' +
                '<label class="ancho">Instrucciones adicionales <small>opcional: otra forma de pago, enlace de cobro…</small><textarea name="pago_instrucciones" rows="3" maxlength="1000">' + esc(c.pago_instrucciones) + '</textarea></label>' +
                '<label class="ancho">Días de acceso mientras verificas un pago <small>0 = sin acceso provisional; máximo una vez cada 30 días por cuenta</small>' +
                '<input name="dias_verificacion" type="number" min="0" max="15" value="' + esc(c.dias_verificacion) + '"></label></div></div>' +
                '<div class="pf-tarjeta"><h3>General</h3><div class="pf-form">' +
                '<label>Moneda<select name="moneda">' + sel(c.moneda, [['MXN', 'Peso mexicano (MXN)'], ['USD', 'Dólar (USD)']]) + '</select></label></div></div>' +
                '</div><div class="pf-form-acciones pf-espacio"><button type="submit" class="pf-btn"><i class="fas fa-save"></i> Guardar configuración</button></div></form>';
            var ciclo = function () {
                var g = parseInt($('f-config').dias_gracia.value, 10) || 0, l = parseInt($('f-config').dias_solo_lectura.value, 10) || 0;
                $('cfg-ciclo').textContent = 'Al vencer el pago: días 1–' + g + ' acceso total con aviso; días ' + (g + 1) + '–' + Math.max(l, g) +
                    ' solo lectura; desde el día ' + (Math.max(l, g) + 1) + ' cuenta suspendida. Los datos nunca se borran.';
            };
            ciclo();
            $('f-config').dias_gracia.addEventListener('input', ciclo);
            $('f-config').dias_solo_lectura.addEventListener('input', ciclo);
            $('f-config').addEventListener('submit', function (ev) {
                ev.preventDefault();
                var boton = this.querySelector('[type=submit]');
                boton.disabled = true;
                api('guardar_config', null, datosForm(this)).then(function () { toast('Configuración guardada'); VISTAS.configuracion(); })
                    .catch(function (err) { toast(err.message, 'error'); boton.disabled = false; });
            });
        }).catch(error);
    };

    // ================================================================ ADMINISTRADORES
    VISTAS.administradores = function () {
        cabecera('Administradores', 'Cuentas con acceso a este panel');
        api('admins').then(function (j) {
            $('pf-vista').innerHTML = '<div class="pf-rejilla dos"><div class="pf-tarjeta"><h3>Cuentas de plataforma</h3>' +
                '<div class="pf-tabla-wrap"><table class="pf-tabla"><thead><tr><th>Nombre</th><th>Usuario</th><th>Alta</th><th></th></tr></thead><tbody>' +
                j.admins.map(function (a) {
                    return '<tr><td>' + esc(a.nombre) + (String(a.id) === String(j.yo) ? ' <span class="pf-etq info">Tú</span>' : '') + '</td><td>' + esc(a.usuario) + '</td><td>' + fecha(a.fecha_creacion) + '</td>' +
                        '<td class="num">' + (String(a.id) === String(j.yo) ? '' : '<button class="pf-btn peligro chico" data-baja="' + a.id + '">Quitar acceso</button>') + '</td></tr>';
                }).join('') + '</tbody></table></div>' +
                '<div class="pf-seccion-titulo" style="margin-top:22px">Agregar cuenta</div>' +
                '<form class="pf-form" id="f-admin" autocomplete="off">' +
                '<label>Nombre<input name="nombre" maxlength="100"></label>' +
                '<label>Correo<input name="correo" type="email" required></label>' +
                '<label class="ancho">Contraseña temporal <small>10+ caracteres</small><input name="password" required minlength="10"></label>' +
                '<div class="pf-form-acciones"><button type="submit" class="pf-btn"><i class="fas fa-user-plus"></i> Agregar</button></div></form></div>' +
                '<div class="pf-tarjeta"><h3>Mi contraseña</h3><form class="pf-form una" id="f-mipass" autocomplete="off">' +
                '<label>Contraseña actual<input name="actual" type="password" required autocomplete="current-password"></label>' +
                '<label>Nueva contraseña <small>10+ caracteres</small><input name="nueva" type="password" required minlength="10" autocomplete="new-password"></label>' +
                '<label>Repite la nueva<input name="repite" type="password" required minlength="10" autocomplete="new-password"></label>' +
                '<div class="pf-form-acciones"><button type="submit" class="pf-btn"><i class="fas fa-key"></i> Cambiar contraseña</button></div></form></div></div>';
            $('f-admin').addEventListener('submit', function (ev) {
                ev.preventDefault();
                var boton = this.querySelector('[type=submit]');
                boton.disabled = true;
                api('admin_crear', null, datosForm(this)).then(function () { toast('Cuenta creada'); VISTAS.administradores(); })
                    .catch(function (err) { toast(err.message, 'error'); boton.disabled = false; });
            });
            $('f-mipass').addEventListener('submit', function (ev) {
                ev.preventDefault();
                var d = datosForm(this);
                if (d.nueva !== d.repite) { toast('Las contraseñas nuevas no coinciden', 'error'); return; }
                var form = this, boton = this.querySelector('[type=submit]');
                boton.disabled = true;
                api('mi_password', null, { actual: d.actual, nueva: d.nueva }).then(function () { toast('Contraseña actualizada'); form.reset(); })
                    .catch(function (err) { toast(err.message, 'error'); })
                    .then(function () { boton.disabled = false; });
            });
            $('pf-vista').querySelectorAll('[data-baja]').forEach(function (b) {
                b.addEventListener('click', function () {
                    confirmar('Quitar acceso', 'Esa cuenta ya no podrá entrar al panel de plataforma.', 'Quitar acceso', true).then(function (si) {
                        if (!si) return;
                        api('admin_eliminar', null, { id: parseInt(b.getAttribute('data-baja'), 10) })
                            .then(function () { toast('Acceso retirado'); VISTAS.administradores(); })
                            .catch(function (err) { toast(err.message, 'error'); });
                    });
                });
            });
        }).catch(error);
    };

    // ================================================================ inicio
    fetch('../auth/session_check.php', { credentials: 'same-origin', cache: 'no-store' })
        .then(function (r) { return r.ok ? r.json() : null; })
        .then(function (d) {
            if (!d || !d.plataforma) { window.location.replace('../auth/login.html'); return; }
            yo = d.user;
            $('pf-yo-nombre').textContent = yo.nombre || 'Administrador';
            $('pf-yo-usuario').textContent = yo.usuario || '';
            // Insignias del menú y moneda antes de la primera vista
            api('resumen').then(function (j) { moneda = j.resumen.moneda || 'MXN'; insignias(j.resumen); }).catch(function () {});
            navegar();
        })
        .catch(function () { window.location.replace('../auth/login.html'); });
})();
