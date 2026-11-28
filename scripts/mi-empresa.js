// Mi empresa (public/mi-empresa.html): plan y facturación, uso del sistema y
// personalización de la marca. Habla con backend/mi_empresa.php.
(function () {
    'use strict';

    var API = '../backend/mi_empresa.php';
    var moneda = 'MXN';
    var graficos = [];
    var cache = {};

    // ================================================================ utilidades
    function $(id) { return document.getElementById(id); }
    function esc(v) {
        return String(v == null ? '' : v).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }
    function dinero(n) {
        return (parseFloat(n) || 0).toLocaleString('es-MX', { style: 'currency', currency: moneda });
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
    function relativo(v) {
        var d = aFecha(v);
        if (!d) return 'Sin actividad';
        var dias = Math.floor((Date.now() - d.getTime()) / 86400000);
        if (dias <= 0) return 'Hoy';
        if (dias === 1) return 'Ayer';
        if (dias < 30) return 'Hace ' + dias + ' días';
        return fecha(v);
    }
    function bytes(n) {
        n = parseFloat(n) || 0;
        if (n < 1048576) return (n / 1024).toFixed(0) + ' KB';
        if (n < 1073741824) return (n / 1048576).toFixed(1) + ' MB';
        return (n / 1073741824).toFixed(2) + ' GB';
    }
    function etiquetaMes(m) {
        var p = m.split('-');
        return new Date(p[0], p[1] - 1, 1).toLocaleDateString('es-MX', { month: 'short' });
    }
    function aviso(texto, tipo) {
        var a = $('me-aviso');
        a.hidden = !texto;
        a.className = 'me-aviso ' + (tipo || 'error');
        a.textContent = texto || '';
        if (texto) a.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
    }
    function api(accion, cuerpo) {
        var op = { credentials: 'same-origin', cache: 'no-store', headers: { Accept: 'application/json' } };
        if (cuerpo !== undefined) {
            op.method = 'POST';
            if (cuerpo instanceof FormData) op.body = cuerpo;
            else { op.headers['Content-Type'] = 'application/json'; op.body = JSON.stringify(cuerpo || {}); }
        }
        return fetch(API + '?accion=' + accion, op).then(function (r) {
            return r.json().catch(function () { return {}; }).then(function (j) {
                if (!r.ok || !j.exito) throw new Error(j.mensaje || j.message || 'Error ' + r.status);
                return j;
            });
        });
    }
    function cssVar(nombre, defecto) {
        var v = getComputedStyle(document.documentElement).getPropertyValue(nombre).trim();
        return v || defecto;
    }
    function oscuro() { return document.documentElement.getAttribute('data-theme') === 'dark'; }

    var TABLAS = {
        incidencias: 'Órdenes de servicio', usuarios: 'Usuarios', clientes: 'Clientes', ventas: 'Ventas', padron_equipos: 'Padrón de equipos',
        permisos_rol: 'Privilegios', inventario_articulos: 'Inventario', empresa_marca: 'Marca de la empresa', cotizaciones: 'Cotizaciones'
    };

    function chip(par) { return '<span class="me-chip ' + par[1] + '">' + esc(par[0]) + '</span>'; }
    function barra(pct, clase) {
        return '<div class="me-barra ' + (clase || '') + '"><i style="width:' + Math.max(0, Math.min(100, pct)) + '%"></i></div>';
    }

    // ================================================================ pestañas
    function limpiarGraficos() { graficos.forEach(function (g) { g.destroy(); }); graficos = []; }

    function mostrar(tab) {
        if (['uso', 'marca'].indexOf(tab) === -1) tab = 'uso';
        document.querySelectorAll('.me-pestanas [data-tab]').forEach(function (b) {
            b.setAttribute('aria-selected', b.getAttribute('data-tab') === tab ? 'true' : 'false');
        });
        limpiarGraficos();
        aviso('');
        $('me-contenido').innerHTML = '<p class="me-cargando">Cargando…</p>';
        ({ uso: pintarUso, marca: pintarMarca })[tab]();
    }
    document.querySelectorAll('.me-pestanas [data-tab]').forEach(function (b) {
        b.addEventListener('click', function () {
            var tab = b.getAttribute('data-tab');
            if (location.hash !== '#' + tab) history.replaceState(null, '', '#' + tab);
            mostrar(tab);
        });
    });

    function error(e) {
        $('me-contenido').innerHTML = '';
        aviso(e.message);
    }

    // ================================================================ USO
    function pintarUso() {
        api('uso').then(function (j) {
            var t = j.totales;
            var kpi = function (icono, etiqueta, valor, nota) {
                return '<div class="me-kpi"><span class="me-kpi-icono"><i class="fas ' + icono + '"></i></span><div><span>' + etiqueta + '</span>' +
                    '<strong>' + valor + '</strong>' + (nota ? '<small>' + nota + '</small>' : '') + '</div></div>';
            };
            $('me-contenido').innerHTML =
                '<div class="me-kpis">' +
                kpi('fa-tasks', 'Órdenes de servicio', numero(t.incidencias), numero(t.abiertas) + ' abiertas · ' + numero(t.completadas || 0) + ' por cerrar · ' + numero(t.cerradas) + ' cerradas' + (t.canceladas ? ' · ' + numero(t.canceladas) + ' canceladas' : '')) +
                kpi('fa-users', 'Clientes', numero(t.clientes), numero(t.equipos) + ' equipos en padrón') +
                kpi('fa-chart-line', 'Ventas', numero(t.ventas), numero(t.articulos) + ' artículos en inventario') +
                kpi('fa-user-check', 'Usuarios activos', t.activos_30 + ' de ' + t.usuarios, 'en los últimos 30 días') +
                kpi('fa-hdd', 'Almacenamiento', bytes(j.almacenamiento), 'archivos y manuales') +
                '</div>' +
                '<div class="me-rejilla">' +
                '<section class="me-tarjeta"><h3 class="me-tarjeta-titulo">Órdenes de servicio por mes <small>últimos 6 meses</small></h3><div class="me-grafico"><canvas id="g-incidencias"></canvas></div></section>' +
                '<section class="me-tarjeta"><h3 class="me-tarjeta-titulo">Ventas por mes <small>últimos 6 meses</small></h3><div class="me-grafico"><canvas id="g-ventas"></canvas></div></section>' +
                '</div>' +
                '<section class="me-tarjeta"><h3 class="me-tarjeta-titulo">Actividad del equipo <small>acciones por día, últimos 30 días</small></h3><div class="me-grafico bajo"><canvas id="g-actividad"></canvas></div></section>' +
                '<div class="me-rejilla">' +
                '<section class="me-tarjeta"><h3 class="me-tarjeta-titulo">Tu equipo <small>últimos 30 días</small></h3><div class="me-tabla-wrap"><table class="me-tabla">' +
                '<thead><tr><th>Usuario</th><th class="num">Acciones</th><th class="num">Días activos</th><th>Último acceso</th></tr></thead><tbody>' +
                (j.equipo.map(function (u) {
                    return '<tr><td><strong>' + esc(u.nombre) + '</strong><small>' + esc(u.rol) + '</small></td><td class="num">' + numero(u.acciones) + '</td>' +
                        '<td class="num">' + numero(u.dias_activos) + '</td><td>' + relativo(u.ultima) + '</td></tr>';
                }).join('') || '<tr><td colspan="4">Sin usuarios.</td></tr>') + '</tbody></table></div></section>' +
                '<section class="me-tarjeta"><h3 class="me-tarjeta-titulo">Últimos movimientos <a href="historial.html">Ver historial</a></h3>' +
                (j.movimientos.length ? '<ul class="me-movimientos">' + j.movimientos.map(function (m) {
                    var accion = { CREATE: 'creó', UPDATE: 'editó', DELETE: 'eliminó', ARCHIVO_ELIMINADO: 'eliminó un archivo de' }[m.accion] || m.accion;
                    var tabla = TABLAS[m.tabla] || m.tabla;
                    return '<li><i class="fas fa-circle"></i><div><strong>' + esc(m.usuario_nombre || 'Sistema') + '</strong> ' + esc(accion) + ' ' +
                        esc(m.registro_folio && m.tabla !== 'empresa_marca' ? m.registro_folio : tabla.toLowerCase()) +
                        '<small>' + relativo(m.creado_en) + ' · ' + esc(tabla) + '</small></div></li>';
                }).join('') + '</ul>' : '<p class="me-nota">Sin movimientos registrados.</p>') + '</section></div>' +

                // Tus datos son tuyos: descarga en CSV (se abre en Excel) de todo lo capturado
                '<section class="me-tarjeta"><h3 class="me-tarjeta-titulo">Tus datos</h3>' +
                '<p class="me-nota">Descarga toda la información de tu empresa: un archivo CSV por módulo (clientes, órdenes, inventario, cotizaciones, finanzas…), en un ZIP. Los archivos adjuntos se descargan desde cada registro.</p>' +
                '<a class="btn-secundario" href="../backend/mi_empresa.php?accion=exportar"><i class="fas fa-file-archive" aria-hidden="true"></i> Exportar mis datos</a></section>';
            graficarUso(j);
        }).catch(error);
    }

    function graficarUso(j) {
        if (typeof Chart === 'undefined') return;
        var texto = oscuro() ? '#9fb0c1' : '#5f6f82';
        var rejilla = oscuro() ? 'rgba(255,255,255,0.06)' : 'rgba(20,40,70,0.08)';
        var primario = oscuro() ? cssVar('--brand-dm-accent', '#4aa8ee') : cssVar('--brand-primary', '#3498db');
        var secundario = '#22c55e';
        Chart.defaults.color = texto;
        Chart.defaults.font.family = 'Inter, "Segoe UI", sans-serif';
        var ejes = { x: { grid: { display: false } }, y: { beginAtZero: true, ticks: { precision: 0 }, grid: { color: rejilla } } };
        var crear = function (id, config) {
            var c = $(id);
            if (!c) return;
            config.options = Object.assign({ responsive: true, maintainAspectRatio: false }, config.options);
            graficos.push(new Chart(c, config));
        };
        crear('g-incidencias', {
            type: 'bar',
            data: { labels: j.series.incidencias.map(function (s) { return etiquetaMes(s.mes); }), datasets: [
                { label: 'Registradas', data: j.series.incidencias.map(function (s) { return s.valor; }), backgroundColor: primario, borderRadius: 4 },
                { label: 'Cerradas', data: j.series.cerradas.map(function (s) { return s.valor; }), backgroundColor: secundario, borderRadius: 4 }] },
            options: { scales: ejes, plugins: { legend: { labels: { boxWidth: 10 } } } }
        });
        crear('g-ventas', {
            type: 'bar',
            data: { labels: j.series.ventas.map(function (s) { return etiquetaMes(s.mes); }), datasets: [
                { label: 'Ventas', data: j.series.ventas.map(function (s) { return s.valor; }), backgroundColor: primario, borderRadius: 4 }] },
            options: { scales: ejes, plugins: { legend: { display: false } } }
        });
        crear('g-actividad', {
            type: 'line',
            data: { labels: j.actividad.map(function (a) { return aFecha(a.dia).toLocaleDateString('es-MX', { day: 'numeric', month: 'short' }); }), datasets: [
                { label: 'Acciones', data: j.actividad.map(function (a) { return a.valor; }), borderColor: primario, backgroundColor: primario + '22',
                  fill: true, tension: 0.35, pointRadius: 0, pointHoverRadius: 4 }] },
            options: { scales: { x: { grid: { display: false }, ticks: { maxTicksLimit: 8 } }, y: ejes.y }, plugins: { legend: { display: false } },
                       interaction: { mode: 'index', intersect: false } }
        });
    }

    // ================================================================ MARCA
    // Contraste contra texto blanco (WCAG): menos de 3 se lee mal en botones y barra lateral
    function contrasteBlanco(hex) {
        var c = hex.replace('#', '');
        var canal = function (i) {
            var v = parseInt(c.substr(i, 2), 16) / 255;
            return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4);
        };
        var l = 0.2126 * canal(0) + 0.7152 * canal(2) + 0.0722 * canal(4);
        return 1.05 / (l + 0.05);
    }

    function urlLogo(ruta) {
        var raiz = (window.APP_BRAND && APP_BRAND.raiz) || '../';
        return /^(https?:)?\/\//.test(ruta) || ruta.charAt(0) === '/' ? ruta : raiz + ruta;
    }

    function pintarMarca() {
        api('marca').then(function (j) {
            cache.marca = j;
            var m = j.marca, bloqueada = !j.personalizable;
            var bloqueo = '';
            if (bloqueada) {
                bloqueo = '<div class="me-bloqueo"><i class="fas fa-lock"></i><div><strong>' +
                    (j.demo ? 'No disponible en la cuenta de demostración' : 'Tu plan no incluye la personalización de marca') + '</strong><p>' +
                    (j.demo ? 'En tu propia cuenta podrás poner tu logo, tus colores y el nombre de tu empresa.'
                        : 'Pon tu logo y tus colores en todo el sistema' + (j.planes_con_marca.length ? ' con el plan ' + esc(j.planes_con_marca.join(' o ')) : '') + '.') +
                    '</p>' + (!j.demo && j.puede_contratar ? '<a class="me-btn" href="../auth/contratar.html">Cambiar de plan</a>' : '') + '</div></div>';
            }
            var dis = bloqueada ? ' disabled' : '';
            var color = function (clave, etiqueta, ayuda) {
                return '<div class="me-color"><label for="c-' + clave + '">' + etiqueta + '</label><div class="me-color-campos">' +
                    '<input type="color" id="c-' + clave + '" data-color="' + clave + '" value="' + esc(m.colores[clave]) + '"' + dis + '>' +
                    '<input type="text" class="me-hex" data-hex="' + clave + '" value="' + esc(m.colores[clave]) + '" maxlength="7"' + dis + '></div>' +
                    '<small>' + ayuda + '</small><small class="me-contraste" id="ct-' + clave + '"></small></div>';
            };
            $('me-contenido').innerHTML = bloqueo +
                '<div class="me-rejilla me-rejilla-marca">' +
                '<form id="f-marca" class="me-tarjeta me-form" novalidate>' +
                '<h3 class="me-tarjeta-titulo">Logo</h3>' +
                '<div class="me-logo-zona"><div class="me-logo-actual"><img id="logo-previo" src="' + esc(urlLogo(m.logo)) + '" alt="Logo actual"></div>' +
                '<div><p class="me-nota">PNG, JPG o WEBP de hasta 1 MB. Funciona mejor horizontal y con fondo transparente.</p>' +
                '<label class="me-btn sec' + (bloqueada ? ' deshabilitado' : '') + '"><i class="fas fa-upload"></i> Subir logo<input type="file" id="logo-archivo" accept=".png,.jpg,.jpeg,.webp,image/png,image/jpeg,image/webp" hidden' + dis + '></label> ' +
                (m.logo_propio && !bloqueada ? '<button type="button" class="me-btn texto" id="logo-quitar">Quitar logo</button>' : '') + '</div></div>' +

                '<h3 class="me-tarjeta-titulo">Colores</h3><div class="me-colores">' +
                color('primario', 'Principal', 'Botones y enlaces') + color('secundario', 'Secundario', 'Encabezados de tablas') +
                color('sidebar', 'Barra lateral', 'Menú de navegación') + '</div>' +

                '<h3 class="me-tarjeta-titulo">Operación</h3>' +
                '<label>Prefijo de folio<input name="prefijo_folio" maxlength="6" value="' + esc(m.prefijo_folio) + '" style="text-transform:uppercase"' + dis + '>' +
                '<small>Solo cambia los folios nuevos (por ejemplo <span id="folio-ejemplo">' + esc(m.prefijo_folio) + '-0001</span>); los anteriores conservan el suyo.</small></label>' +
                '<label>Categorías de orden de servicio <small>una por línea</small><textarea name="categorias" rows="6"' + dis + '>' + esc(m.categorias.join('\n')) + '</textarea></label>' +

                (bloqueada ? '' : '<div class="me-acciones"><button type="button" class="me-btn texto peligro" id="btn-restablecer">Restablecer apariencia original</button>' +
                    '<button type="submit" class="me-btn"><i class="fas fa-save"></i> Guardar cambios</button></div>') +
                '</form>' +

                '<aside class="me-tarjeta me-previa-tarjeta"><h3 class="me-tarjeta-titulo">Vista previa</h3>' +
                '<div class="me-previa" id="previa"><div class="me-previa-lateral"><div class="me-previa-logo"><img id="previa-logo" src="' + esc(urlLogo(m.logo)) + '" alt=""></div>' +
                '<span class="activo"><i class="fas fa-home"></i> Inicio</span><span><i class="fas fa-tasks"></i> Órdenes de servicio</span><span><i class="fas fa-users"></i> Clientes</span>' +
                '<span><i class="fas fa-chart-line"></i> Ventas</span></div>' +
                '<div class="me-previa-cuerpo"><strong id="previa-nombre"></strong>' +
                '<div class="me-previa-tabla"><div class="cab">Folio · Cliente · Estatus</div><div>' + '<span id="previa-folio"></span> · Cliente de ejemplo · Abierto</div></div>' +
                '<div class="me-previa-botones"><span class="btn1">Guardar</span><span class="enlace">Ver detalle</span></div></div></div>' +
                '<p class="me-nota">Así se verá el sistema para todo tu equipo. Los cambios se aplican al guardar.</p></aside></div>' +
                tarjetaOperacion(j.operacion || {}) +
                '<form id="f-tarifas" class="me-tarjeta me-form me-cotizaciones" novalidate><h3 class="me-tarjeta-titulo"><i class="fas fa-tags"></i> Tarifas de servicio</h3><p class="me-nota">Cargando…</p></form>' +
                tarjetaCotizaciones(j.cotizaciones || {});

            enlazarMarca(bloqueada);
            enlazarOperacion();
            cargarTarifas();
            enlazarCotizaciones();
        }).catch(error);
    }

    // Operación: meta de cumplimiento y zona horaria (todos los planes)
    function tarjetaOperacion(o) {
        return '<form id="f-operacion" class="me-tarjeta me-form me-cotizaciones" novalidate>' +
            '<h3 class="me-tarjeta-titulo"><i class="fas fa-stopwatch"></i> Cumplimiento y horario</h3>' +
            '<p class="me-nota">Tu meta de atención y la hora de tu operación. Disponible en todos los planes.</p>' +
            '<div class="me-cot-campos">' +
            '<label>Meta de cumplimiento (días)<input name="sla_dias" type="number" min="1" max="90" required value="' + esc(o.sla_dias || 7) + '">' +
            '<small>Días para cerrar una orden de servicio. Después se marca como atrasada en Inicio, Informes y el panel del técnico.</small></label>' +
            '<label>Zona horaria<select name="zona_horaria">' + (window.AppZonas ? AppZonas.opciones(o.zona_horaria) : '<option>' + esc(o.zona_horaria) + '</option>') + '</select>' +
            '<small>Fechas y horas de órdenes de servicio, cotizaciones e historial. Hora actual ahí: <b id="op-hora"></b></small></label>' +
            '</div>' +
            '<div class="me-acciones"><button type="submit" class="me-btn"><i class="fas fa-save"></i> Guardar</button></div>' +
            '</form>';
    }

    function enlazarOperacion() {
        var form = $('f-operacion');
        if (!form) return;
        var hora = function () {
            try {
                $('op-hora').textContent = new Intl.DateTimeFormat('es-MX', { timeZone: form.zona_horaria.value, hour: '2-digit', minute: '2-digit', weekday: 'short' }).format(new Date());
            } catch (e) { $('op-hora').textContent = ''; }
        };
        hora();
        form.zona_horaria.addEventListener('change', hora);
        form.addEventListener('submit', function (ev) {
            ev.preventDefault();
            aviso('');
            var boton = form.querySelector('[type=submit]');
            boton.disabled = true;
            api('guardar_operacion', { sla_dias: Number(form.sla_dias.value), zona_horaria: form.zona_horaria.value })
                .then(function (j) {
                    aviso(j.mensaje || 'Configuración guardada.', 'ok');
                    if (window.APP_BRAND) APP_BRAND.sla_dias = Number(form.sla_dias.value);
                })
                .catch(function (e) { aviso(e.message); })
                .then(function () { boton.disabled = false; });
        });
    }

    // D-43: mano de obra por hora y servicios de precio cerrado con lo que incluyen
    function filaServicio(sv) {
        sv = sv || {};
        return '<div class="me-servicio" data-id="' + esc(sv.id || '') + '">' +
            '<label>Servicio<input name="nombre" maxlength="150" value="' + esc(sv.nombre || '') + '" placeholder="Ej. Mantenimiento preventivo"></label>' +
            '<label>Precio<input name="precio" type="number" min="0" step="0.01" value="' + esc(sv.precio != null ? sv.precio : '') + '"></label>' +
            '<label class="ancho">Incluye<textarea name="incluye" rows="2" maxlength="2000" placeholder="Un concepto por línea: limpieza, ajuste, calibración…">' + esc(sv.incluye || '') + '</textarea></label>' +
            '<button type="button" class="me-btn sec" data-quitar-servicio title="Quitar servicio" aria-label="Quitar servicio"><i class="fas fa-trash-alt"></i></button></div>';
    }

    function cargarTarifas() {
        var form = $('f-tarifas');
        if (!form) return;
        api('tarifas').then(function (t) {
            form.innerHTML = '<h3 class="me-tarjeta-titulo"><i class="fas fa-tags"></i> Tarifas de servicio</h3>' +
                '<p class="me-nota"><b>Mano de obra</b> es tiempo: se cobra por hora y admite fracciones (1.5 h). <b>Servicio</b> es un precio cerrado; lo que incluye aparece en la cotización.</p>' +
                '<div class="me-cot-campos"><label>Mano de obra por hora<input name="tarifa_hora" type="number" min="0" step="0.01" required value="' + esc(t.tarifa_hora || 0) + '">' +
                '<small>Precio del concepto «Mano de obra (por hora)» en órdenes y cotizaciones.</small></label></div>' +
                '<div id="me-servicios">' + (t.servicios || []).map(filaServicio).join('') + '</div>' +
                '<div class="me-acciones"><button type="button" class="me-btn sec" id="me-agregar-servicio"><i class="fas fa-plus"></i> Agregar servicio</button>' +
                '<button type="submit" class="me-btn"><i class="fas fa-save"></i> Guardar</button></div>';
            $('me-agregar-servicio').addEventListener('click', function () {
                $('me-servicios').insertAdjacentHTML('beforeend', filaServicio());
                $('me-servicios').lastElementChild.querySelector('[name=nombre]').focus();
            });
            form.addEventListener('click', function (e) {
                var b = e.target.closest('[data-quitar-servicio]');
                if (b) b.closest('.me-servicio').remove();
            });
            form.addEventListener('submit', function (ev) {
                ev.preventDefault();
                aviso('');
                var boton = form.querySelector('[type=submit]');
                boton.disabled = true;
                var servicios = Array.prototype.map.call(form.querySelectorAll('.me-servicio'), function (f) {
                    return { id: Number(f.getAttribute('data-id')) || null, nombre: f.querySelector('[name=nombre]').value,
                             precio: Number(f.querySelector('[name=precio]').value), incluye: f.querySelector('[name=incluye]').value };
                }).filter(function (sv) { return sv.nombre.trim() !== ''; });
                api('guardar_tarifas', { tarifa_hora: Number(form.tarifa_hora.value), servicios: servicios })
                    .then(function (j) {
                        aviso(j.mensaje || 'Tarifas guardadas.', 'ok');
                        $('me-servicios').innerHTML = (j.servicios || []).map(filaServicio).join('');
                    })
                    .catch(function (e) { aviso(e.message); })
                    .then(function () { boton.disabled = false; });
            });
        }).catch(function (e) { form.querySelector('.me-nota').textContent = e.message; });
    }

    // Configuración de cotizaciones: operativa, disponible en todos los planes
    function tarjetaCotizaciones(c) {
        var opcionIva = function (v, texto) { return '<option value="' + v + '"' + (Number(c.iva) === v ? ' selected' : '') + '>' + texto + '</option>'; };
        return '<form id="f-cotizaciones" class="me-tarjeta me-form me-cotizaciones" novalidate>' +
            '<h3 class="me-tarjeta-titulo"><i class="fas fa-file-invoice-dollar"></i> Cotizaciones</h3>' +
            '<p class="me-nota">Valores por defecto de las cotizaciones nuevas. Disponible en todos los planes.</p>' +
            '<div class="me-cot-campos">' +
            '<label>Prefijo de folio<input name="prefijo" maxlength="6" value="' + esc(c.prefijo || 'COT') + '" style="text-transform:uppercase">' +
            '<small>Ejemplo: <span id="cot-folio-ejemplo">' + esc(c.prefijo || 'COT') + '-0001</span></small></label>' +
            '<label>Vigencia por defecto (días)<input name="vigencia_dias" type="number" min="1" max="365" value="' + esc(c.vigencia_dias || 15) + '"></label>' +
            '<label>IVA por defecto<select name="iva">' + opcionIva(0.16, '16% (general)') + opcionIva(0.08, '8% (región fronteriza)') + '</select></label>' +
            '<label>Tipo de cambio (MXN por USD)<input name="tipo_cambio" type="number" min="0" max="100000" step="0.0001" value="' + esc(c.tipo_cambio ? c.tipo_cambio : '') + '">' +
            '<small>Referencia para cotizaciones en USD y para el valor del inventario en pesos. Déjalo vacío si no manejas dólares.</small></label>' +
            '<label>Descuento máximo sin autorización (%)<input name="descuento_max" type="number" min="0" max="100" step="0.5" value="' + esc(c.descuento_max != null ? c.descuento_max : 10) + '">' +
            '<small>Más descuento lo autoriza el Administrador o quien tenga «Puede autorizar descuentos» en Usuarios.</small></label>' +
            '</div>' +
            '<label class="me-check"><input type="checkbox" name="precios_incluyen_iva"' + (Number(c.precios_incluyen_iva) === 1 ? ' checked' : '') + '> Los precios del catálogo ya incluyen IVA' +
            '<small>Marca el check «Precios incluyen IVA» por defecto en las cotizaciones nuevas (se puede apagar en cada una).</small></label>' +
            '<label>Términos y condiciones por defecto<textarea name="terminos" rows="5" maxlength="3000">' + esc(c.terminos || '') + '</textarea></label>' +
            '<div class="me-acciones"><button type="submit" class="me-btn"><i class="fas fa-save"></i> Guardar configuración</button></div>' +
            '</form>';
    }

    function enlazarCotizaciones() {
        var form = $('f-cotizaciones');
        if (!form) return;
        form.prefijo.addEventListener('input', function () {
            $('cot-folio-ejemplo').textContent = (this.value.toUpperCase().replace(/[^A-Z0-9]/g, '') || 'COT') + '-0001';
        });
        form.addEventListener('submit', function (ev) {
            ev.preventDefault();
            aviso('');
            var boton = form.querySelector('[type=submit]');
            boton.disabled = true;
            api('guardar_cotizaciones', {
                prefijo: form.prefijo.value, vigencia_dias: Number(form.vigencia_dias.value), iva: Number(form.iva.value),
                tipo_cambio: Number(form.tipo_cambio.value) || 0,
                descuento_max: Number(form.descuento_max.value), terminos: form.terminos.value,
                precios_incluyen_iva: form.precios_incluyen_iva.checked ? 1 : 0
            }).then(function (j) { aviso(j.mensaje || 'Configuración guardada.', 'ok'); })
              .catch(function (e) { aviso(e.message); })
              .then(function () { boton.disabled = false; });
        });
    }

    function enlazarMarca(bloqueada) {
        var form = $('f-marca');
        var colores = function () {
            var c = {};
            form.querySelectorAll('[data-color]').forEach(function (i) { c[i.getAttribute('data-color')] = i.value; });
            return c;
        };
        var actualizar = function () {
            var c = colores(), p = $('previa');
            p.style.setProperty('--pv-primario', c.primario);
            p.style.setProperty('--pv-secundario', c.secundario);
            p.style.setProperty('--pv-lateral', c.sidebar);
            $('previa-nombre').textContent = (window.APP_BRAND && APP_BRAND.empresa) || 'Tu empresa';
            var prefijo = (form.prefijo_folio.value || '').toUpperCase().replace(/[^A-Z0-9]/g, '') || 'SD';
            $('previa-folio').textContent = prefijo + '-0001';
            $('folio-ejemplo').textContent = prefijo + '-0001';
            ['primario', 'secundario', 'sidebar'].forEach(function (k) {
                var r = contrasteBlanco(c[k]);
                $('ct-' + k).innerHTML = r < 3 ? '<i class="fas fa-exclamation-triangle"></i> Muy claro: el texto blanco se leerá mal' : '';
            });
        };
        form.querySelectorAll('[data-color]').forEach(function (i) {
            i.addEventListener('input', function () {
                form.querySelector('[data-hex="' + i.getAttribute('data-color') + '"]').value = i.value;
                actualizar();
            });
        });
        form.querySelectorAll('[data-hex]').forEach(function (t) {
            t.addEventListener('input', function () {
                var v = t.value.trim();
                if (/^#?[0-9a-f]{6}$/i.test(v)) {
                    v = (v.charAt(0) === '#' ? v : '#' + v).toLowerCase();
                    form.querySelector('[data-color="' + t.getAttribute('data-hex') + '"]').value = v;
                    actualizar();
                }
            });
        });
        form.prefijo_folio.addEventListener('input', actualizar);
        actualizar();
        if (bloqueada) return;

        form.addEventListener('submit', function (ev) {
            ev.preventDefault();
            aviso('');
            var boton = form.querySelector('[type=submit]');
            boton.disabled = true;
            api('guardar_marca', {
                prefijo_folio: form.prefijo_folio.value,
                colores: colores(), categorias: form.categorias.value.split('\n')
            }).then(function () {
                // Los colores salen de branding/tema.css.php: se recarga para aplicarlos en todo
                location.hash = 'marca';
                location.reload();
            }).catch(function (e) { aviso(e.message); boton.disabled = false; });
        });

        $('logo-archivo').addEventListener('change', function () {
            var archivo = this.files[0];
            if (!archivo) return;
            if (archivo.size > 1024 * 1024) { aviso('La imagen pesa más de 1 MB.'); this.value = ''; return; }
            var datos = new FormData();
            datos.append('logo', archivo);
            aviso('Subiendo logo…', 'info');
            api('subir_logo', datos).then(function () { location.hash = 'marca'; location.reload(); })
                .catch(function (e) { aviso(e.message); });
        });
        if ($('logo-quitar')) {
            $('logo-quitar').addEventListener('click', function () {
                AppAviso.confirmar('Se usará de nuevo el logo del sistema.', { titulo: '¿Quitar tu logo?', boton: 'Quitar logo', peligro: true }).then(function (ok) {
                    if (ok) api('quitar_logo', {}).then(function () { location.reload(); }).catch(function (e) { aviso(e.message); });
                });
            });
        }
        $('btn-restablecer').addEventListener('click', function () {
            AppAviso.confirmar('Se restablecen el logo y los colores originales. El prefijo de folio y las categorías se conservan.',
                { titulo: '¿Restablecer la apariencia?', boton: 'Restablecer', peligro: true }).then(function (ok) {
                if (ok) api('restablecer', {}).then(function () { location.reload(); }).catch(function (e) { aviso(e.message); });
            });
        });
    }

    // ================================================================ inicio
    function iniciar() {
        if (window.appOnAuthReady) {
            window.appOnAuthReady(function (auth) {
                if (!auth.empresa || !auth.empresa.administra) {
                    window.location.replace('../index.html');
                    return;
                }
                mostrar((location.hash || '#uso').slice(1));
            });
        } else {
            mostrar((location.hash || '#uso').slice(1));
        }
    }
    iniciar();
})();
