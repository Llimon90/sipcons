// Pantalla de Finanzas (public/finanzas.html): resumen, cuentas por cobrar con
// sus cobros, gastos y rentabilidad por orden de servicio. Toda la lógica y
// las validaciones viven en backend/finanzas.php (lib/finanzas_helpers.php);
// aquí solo se captura y se muestra.
//
// finanzas.html?nueva=cotizacion&id=N (u orden / venta) abre directamente la
// cuenta por cobrar de ese origen.
(function () {
    'use strict';
    var API = '../backend/finanzas.php';
    var FORMAS = { transferencia: 'Transferencia', efectivo: 'Efectivo', tarjeta: 'Tarjeta', cheque: 'Cheque', otro: 'Otro' };
    var ORIGEN_URL = { cotizacion: 'cotizacion.html?id=', incidencia: 'detalle.html?id=', venta: 'detalles-venta.html?id=' };
    var ORIGEN_NOMBRE = { cotizacion: 'Cotización', incidencia: 'Orden de servicio', venta: 'Venta' };

    var clientes = [];          // catálogo: { id, nombre, dias_credito }
    var origenActual = null;    // origen de la cuenta que se está capturando
    var cuentaActual = null;    // cuenta abierta en el detalle
    var gastosPuedeCancelar = false;
    var grafico = null;
    var venceManual = false;

    function $(id) { return document.getElementById(id); }
    function esc(v) {
        return String(v == null ? '' : v).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }
    function num(v) { return parseFloat(v) || 0; }
    function r2(v) { return Math.round(num(v) * 100) / 100; }
    function dinero(v) { return num(v).toLocaleString('es-MX', { style: 'currency', currency: 'MXN' }); }
    function fecha(v) {
        if (!v) return '';
        var p = String(v).substr(0, 10).split('-');
        return p.length === 3 ? p[2] + '/' + p[1] + '/' + p[0] : v;
    }
    function hoy() {
        var d = new Date();
        return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
    }
    function inicioMes() { return hoy().substr(0, 8) + '01'; }
    function sumarDias(f, dias) {
        var d = new Date(f + 'T12:00:00');
        d.setDate(d.getDate() + (parseInt(dias, 10) || 0));
        return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
    }
    function avisar(texto, tipo) { AppAviso.mostrar(texto, tipo || 'ok'); }
    function urlArchivo(ruta) { return '../' + String(ruta).replace(/^\/+/, ''); }

    function api(accion, params, cuerpo) {
        var url = API + '?accion=' + accion + (params ? '&' + new URLSearchParams(params).toString() : '');
        var op = { credentials: 'same-origin', cache: 'no-store' };
        if (cuerpo instanceof FormData) { op.method = 'POST'; op.body = cuerpo; }
        else if (cuerpo) { op.method = 'POST'; op.headers = { 'Content-Type': 'application/json' }; op.body = JSON.stringify(cuerpo); }
        return fetch(url, op).then(function (r) {
            return r.json().catch(function () { return {}; }).then(function (j) {
                if (!r.ok || !j.exito) { var e = new Error(j.mensaje || 'Error ' + r.status); e.codigo = j.codigo || null; throw e; }
                return j;
            });
        });
    }

    /** Datos JSON + archivo opcional (comprobante) en un solo envío. */
    function conArchivo(datos, input) {
        var f = new FormData();
        f.append('datos', JSON.stringify(datos));
        if (input && input.files && input.files[0]) f.append('comprobante', input.files[0]);
        return f;
    }
    function limpiarArchivo(input) { if (input._selector) input._selector.limpiar(); else input.value = ''; }

    function etiquetaEstatus(c) {
        if (c.estatus === 'cancelada') return '<span class="etiqueta etiqueta-neutral">Cancelada</span>';
        if (c.estatus === 'pagada') return '<span class="etiqueta etiqueta-ok">Pagada</span>';
        if (c.dias_vencida > 0) return '<span class="etiqueta etiqueta-error">Vencida · ' + c.dias_vencida + ' d</span>';
        return c.estatus === 'parcial' ? '<span class="etiqueta etiqueta-alerta">Parcial</span>' : '<span class="etiqueta etiqueta-info">Pendiente</span>';
    }
    function enlaceOrigen(tipo, id, folio) {
        if (!tipo || !folio) return '';
        if (!id) return esc(ORIGEN_NOMBRE[tipo] + ' ' + folio + ' (eliminada)');
        return '<a href="' + ORIGEN_URL[tipo] + encodeURIComponent(id) + '">' + esc(ORIGEN_NOMBRE[tipo] + ' ' + folio) + '</a>';
    }

    /** Pide el motivo de una cancelación. Resuelve con el texto o null. */
    function pedirMotivo(titulo, mensaje) {
        var nodo = document.createElement('div');
        nodo.className = 'fin-dialogo';
        nodo.innerHTML = '<p>' + esc(mensaje) + '</p><label for="fin-motivo">Motivo</label><textarea id="fin-motivo" rows="3" maxlength="255"></textarea>';
        return AppDialogo.mostrar({
            tipo: 'aviso', titulo: titulo, contenido: nodo,
            botones: [{ texto: 'Volver', valor: null, estilo: 'secundario' }, { texto: 'Cancelar registro', valor: 'si', estilo: 'peligro' }]
        }).then(function (v) {
            if (v !== 'si') return null;
            var motivo = nodo.querySelector('textarea').value.trim();
            if (!motivo) { avisar('Escribe el motivo de la cancelación.', 'error'); return null; }
            return motivo;
        });
    }

    // ---------------------------------------------------------- modales
    function abrir(id) { $(id).style.display = 'block'; document.body.classList.add('dialogo-abierto'); }
    function cerrar(modal) { modal.style.display = 'none'; document.body.classList.remove('dialogo-abierto'); }
    document.addEventListener('click', function (e) {
        if (e.target.matches('[data-cerrar]')) cerrar(e.target.closest('.modal'));
    });
    // Como todos los modales de captura: Esc o Cancelar, nunca un clic fuera
    document.addEventListener('keydown', function (e) {
        if (e.key !== 'Escape' || document.querySelector('.dialogo')) return;
        document.querySelectorAll('.inv-modal').forEach(function (m) { if (m.style.display === 'block') cerrar(m); });
    });

    // ---------------------------------------------------------- pestañas
    var cargadas = {};
    function mostrarPestana(nombre) {
        if (!document.querySelector('[data-panel="' + nombre + '"]')) nombre = 'resumen';
        document.querySelectorAll('.fin-tabs [data-tab]').forEach(function (b) {
            var activa = b.dataset.tab === nombre;
            b.classList.toggle('activo', activa);
            b.setAttribute('aria-selected', activa ? 'true' : 'false');
        });
        document.querySelectorAll('.fin-panel').forEach(function (p) { p.hidden = p.dataset.panel !== nombre; });
        if (history.replaceState) history.replaceState(null, '', '#' + nombre);
        if (!cargadas[nombre]) { cargadas[nombre] = true; recargar(nombre); }
    }
    function recargar(nombre) {
        if (nombre === 'resumen') return cargarResumen();
        if (nombre === 'cuentas') return cargarCuentas();
        if (nombre === 'gastos') return cargarGastos();
        if (nombre === 'rentabilidad') return cargarRentabilidad();
    }
    /** Después de guardar algo: recarga lo visible y marca lo demás para recargar al abrirlo. */
    function refrescar() {
        var visible = (document.querySelector('.fin-tabs .activo') || {}).dataset.tab || 'resumen';
        cargadas = {};
        cargadas[visible] = true;
        recargar(visible);
    }
    document.querySelectorAll('.fin-tabs [data-tab]').forEach(function (b) {
        b.addEventListener('click', function () { mostrarPestana(b.dataset.tab); });
    });

    // ---------------------------------------------------------- resumen
    function cargarResumen() {
        return api('resumen').then(function (j) {
            var r = j.resumen;
            $('k-por-cobrar').textContent = dinero(r.por_cobrar);
            $('k-vencido').textContent = dinero(r.vencido);
            $('k-cobrado').textContent = dinero(r.cobrado_mes);
            $('k-gastos').textContent = dinero(r.gastos_mes);
            $('k-flujo').textContent = dinero(r.flujo_mes);
            $('k-flujo').classList.toggle('fin-negativo', r.flujo_mes < 0);

            var a = r.antiguedad;
            var tramos = [['al_corriente', 'Al corriente', 'ok'], ['d1_30', '1 a 30 días vencido', 'alerta'], ['d31_60', '31 a 60 días', 'alerta'],
                ['d61_90', '61 a 90 días', 'error'], ['mas_90', 'Más de 90 días', 'error']];
            var maximo = Math.max.apply(null, tramos.map(function (t) { return num(a[t[0]]); })) || 1;
            $('antiguedad').innerHTML = tramos.map(function (t) {
                return '<li><span class="fin-ant-etq">' + t[1] + '</span><span class="fin-ant-barra"><i class="fin-ant-' + t[2] + '" style="width:' +
                    Math.round(num(a[t[0]]) / maximo * 100) + '%"></i></span><b>' + dinero(a[t[0]]) + '</b></li>';
            }).join('');
            $('por-vencer').textContent = r.por_vencer_7 > 0 ? 'Vence en los próximos 7 días: ' + dinero(r.por_vencer_7) + '.' : '';

            $('pend-total').textContent = r.pendientes_total ? r.pendientes_total : '';
            $('pendientes').innerHTML = r.pendientes.length ? r.pendientes.map(function (p) {
                return '<li><div><b>' + enlaceOrigen(p.tipo, p.id, p.folio) + '</b><small>' + esc(p.cliente) + ' · ' + esc(p.detalle) +
                    (p.importe ? ' · ' + dinero(p.importe) : '') + '</small></div>' +
                    '<button type="button" class="btn-secundario" data-crear-tipo="' + p.tipo + '" data-crear-id="' + p.id + '"><i class="fas fa-plus"></i> Crear cuenta</button></li>';
            }).join('') + (r.pendientes_total > r.pendientes.length ? '<li class="fin-mas">Y ' + (r.pendientes_total - r.pendientes.length) + ' más: aquí se muestran los más recientes; al registrar estos aparecen los siguientes.</li>' : '')
                : '<li class="inv-vacio">Todo el trabajo cerrado ya tiene su cuenta por cobrar.</li>';

            $('deudores').innerHTML = r.deudores.length ? r.deudores.map(function (d) {
                return '<li><div><b>' + esc(d.cliente_nombre) + '</b><small>' + d.cuentas + (d.cuentas == 1 ? ' cuenta' : ' cuentas') +
                    (num(d.vencido) > 0 ? ' · <span class="fin-rojo">vencido ' + dinero(d.vencido) + '</span>' : '') + '</small></div>' +
                    '<span class="fin-monto">' + dinero(d.saldo) + '</span>' +
                    (d.cliente_id ? '<a class="btn-secundario" href="estado-cuenta.html?cliente_id=' + encodeURIComponent(d.cliente_id) + '" target="_blank" rel="noopener" title="Estado de cuenta"><i class="fas fa-file-alt"></i></a>' : '') + '</li>';
            }).join('') : '<li class="inv-vacio">Ningún cliente tiene saldo pendiente.</li>';

            pintarGrafico(r.meses);
        }).catch(function (e) { avisar(e.message, 'error'); });
    }

    function pintarGrafico(meses) {
        if (!window.Chart) return;
        var nombres = ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];
        var claves = Object.keys(meses);
        var datos = {
            labels: claves.map(function (k) { return nombres[parseInt(k.substr(5), 10) - 1] + ' ' + k.substr(2, 2); }),
            datasets: [
                { label: 'Cobrado', data: claves.map(function (k) { return meses[k].cobrado; }), backgroundColor: '#16a34a' },
                { label: 'Gastos', data: claves.map(function (k) { return meses[k].gastos; }), backgroundColor: '#f59e0b' }
            ]
        };
        if (grafico) { grafico.data = datos; grafico.update(); return; }
        grafico = new Chart($('g-flujo'), {
            type: 'bar', data: datos,
            options: { responsive: true, maintainAspectRatio: false,
                plugins: { tooltip: { callbacks: { label: function (c) { return c.dataset.label + ': ' + dinero(c.parsed.y); } } } },
                scales: { y: { beginAtZero: true, ticks: { callback: function (v) { return dinero(v).replace('.00', ''); } } } } }
        });
    }

    $('pendientes').addEventListener('click', function (e) {
        var b = e.target.closest('[data-crear-tipo]');
        if (b) abrirCuenta({ tipo: b.dataset.crearTipo, id: b.dataset.crearId });
    });

    // ---------------------------------------------------------- clientes
    function cargarClientes() {
        return api('clientes').then(function (j) {
            clientes = j.clientes;
            $('lista-clientes').innerHTML = clientes.map(function (c) { return '<option value="' + esc(c.nombre) + '">'; }).join('');
        });
    }
    function clientePorNombre(nombre) {
        var n = String(nombre || '').trim().toUpperCase();
        return clientes.find(function (c) { return c.nombre.trim().toUpperCase() === n; }) || null;
    }
    function clientePorId(id) { return clientes.find(function (c) { return String(c.id) === String(id); }) || null; }

    // ---------------------------------------------------------- nueva cuenta
    function totalCuenta() {
        var t = r2(num($('cu-subtotal').value) + num($('cu-iva').value) - num($('cu-ret').value));
        $('cu-total').textContent = dinero(t);
        return t;
    }
    function recalcularVence() {
        if (!venceManual && $('cu-fecha').value) $('cu-vence').value = sumarDias($('cu-fecha').value, $('cu-dias').value);
    }

    // O-16: en una cuenta de cotización el IVA sigue la proporción aprobada (puede mezclar
    // tasas y exentos); los atajos 16 %/8 %/0 se ocultan y el IVA no se edita a mano.
    function ivaDeCotizacion(o, subtotal) {
        if (!o || o.limite === null || !(o.limite > 0)) return null;
        var falta = r2(o.limite - o.registrado);
        var base = r2(falta * o.subtotal / o.limite);
        if (Math.abs(subtotal - base) < 0.005) return r2(falta - base);
        return o.subtotal > 0 ? r2(subtotal * o.iva / o.subtotal) : 0;
    }
    function modoIvaCotizacion(o) {
        var atajos = document.querySelector('#modal-cuenta .fin-atajos');
        var fijo = !!(o && o.limite !== null);
        if (atajos) atajos.hidden = fijo;
        $('cu-iva').readOnly = fijo;
        $('cu-iva').title = fijo ? 'IVA según la cotización ' + o.folio + ' (se calcula con su proporción)' : '';
    }
    $('cu-subtotal').addEventListener('input', function () {
        var iva = ivaDeCotizacion(origenActual, num(this.value));
        if (iva !== null) $('cu-iva').value = iva.toFixed(2);
    });

    /** Abre la captura; con origen ({tipo, id}) la llena con sus datos. */
    function abrirCuenta(origen) {
        var f = $('form-cuenta');
        f.reset();
        origenActual = null;
        modoIvaCotizacion(null);
        venceManual = false;
        $('cu-origen').hidden = true;
        $('cu-fecha').value = hoy();
        $('cu-dias').value = 0;
        recalcularVence();
        totalCuenta();
        var listo = clientes.length ? Promise.resolve() : cargarClientes();
        listo.then(function () {
            if (!origen) { abrir('modal-cuenta'); $('cu-cliente').focus(); return; }
            return api('origen', { tipo: origen.tipo, id: origen.id }).then(function (j) {
                var o = origenActual = j.origen;
                var cli = o.cliente_id ? clientePorId(o.cliente_id) : clientePorNombre(o.cliente_nombre);
                $('cu-cliente').value = cli ? cli.nombre : '';
                if (cli) $('cu-dias').value = cli.dias_credito || 0;
                $('cu-concepto').value = o.concepto;
                if (o.limite !== null) {
                    // Lo que falta de lo aprobado, con la misma proporción de IVA
                    var falta = r2(o.limite - o.registrado);
                    var base = o.limite > 0 ? r2(falta * o.subtotal / o.limite) : 0;
                    $('cu-subtotal').value = base.toFixed(2);
                    $('cu-iva').value = r2(falta - base).toFixed(2);
                } else if (o.subtotal != null) {
                    // D-49: orden sin cotización: el importe de sus conceptos aplicados (se puede ajustar)
                    $('cu-subtotal').value = num(o.subtotal).toFixed(2);
                    $('cu-iva').value = num(o.iva).toFixed(2);
                    if (o.detalle && o.detalle.length && !$('cu-notas').value.trim()) {
                        $('cu-notas').value = o.detalle.map(function (d) { return d.cantidad + ' × ' + d.descripcion + ': ' + dinero(d.importe); }).join('\n');
                    }
                }
                modoIvaCotizacion(o);
                $('cu-origen').innerHTML = (o.desde_orden ? '<b>Esta orden se cobra con su cotización aprobada</b> (para no cobrar dos veces). ' : '') +
                    (o.tipo === 'incidencia' && o.subtotal != null ? 'Importe de los conceptos aplicados en la orden. ' : '') +
                    '<i class="fas fa-link"></i> ' + enlaceOrigen(o.tipo, o.id, o.folio) +
                    (o.limite !== null ? ' · aprobado ' + dinero(o.limite) + (o.registrado > 0 ? ' · ya registrado ' + dinero(o.registrado) : '') : '') +
                    (!cli ? ' · <span class="fin-rojo">el cliente «' + esc(o.cliente_nombre) + '» no está en tu catálogo: elígelo o créalo con «Nuevo cliente»</span>' : '');
                $('cu-origen').hidden = false;
                recalcularVence();
                totalCuenta();
                abrir('modal-cuenta');
                $('cu-concepto').focus();
            });
        }).catch(function (e) { avisar(e.message, 'error'); });
    }

    $('btn-nueva-cuenta').addEventListener('click', function () { abrirCuenta(null); });
    $('cu-cliente').addEventListener('change', function () {
        var c = clientePorNombre(this.value);
        if (c) { $('cu-dias').value = c.dias_credito || 0; recalcularVence(); }
    });
    // Alta rápida del cliente sin salir de la cuenta (queda seleccionado)
    if (window.AppAltaRapida) AppAltaRapida.cliente($('cu-cliente'), {
        existe: function (n) { return !!clientePorNombre(n); },
        alCrear: function (c) {
            clientes.push({ id: c.id, nombre: c.nombre, dias_credito: 0 });
            $('lista-clientes').insertAdjacentHTML('beforeend', '<option value="' + esc(c.nombre) + '">');
        }
    });
    ['cu-fecha', 'cu-dias'].forEach(function (id) { $(id).addEventListener('input', recalcularVence); });
    $('cu-vence').addEventListener('input', function () { venceManual = true; });
    ['cu-subtotal', 'cu-iva', 'cu-ret'].forEach(function (id) { $(id).addEventListener('input', totalCuenta); });

    // Atajos de IVA: sobre el subtotal (cuentas) o incluido en el total (gastos)
    document.addEventListener('click', function (e) {
        var b = e.target.closest('.fin-atajos button');
        if (!b) return;
        var cont = b.parentNode, tasa = parseFloat(b.dataset.tasa);
        var destino = $(cont.dataset.ivaEn);
        if (cont.dataset.ivaDe) destino.value = r2(num($(cont.dataset.ivaDe).value) * tasa).toFixed(2);
        else destino.value = r2(num($(cont.dataset.ivaIncluido).value) - num($(cont.dataset.ivaIncluido).value) / (1 + tasa)).toFixed(2);
        destino.dispatchEvent(new Event('input', { bubbles: true }));
    });

    $('form-cuenta').addEventListener('submit', function (e) {
        e.preventDefault();
        var cli = clientePorNombre($('cu-cliente').value);
        if (!cli) { avisar('Elige un cliente de tu catálogo (si es nuevo, usa «Nuevo cliente»).', 'error'); $('cu-cliente').focus(); return; }
        if (!$('cu-concepto').value.trim()) { avisar('Escribe el concepto.', 'error'); $('cu-concepto').focus(); return; }
        if (totalCuenta() <= 0) { avisar('El total a cobrar debe ser mayor a cero.', 'error'); $('cu-subtotal').focus(); return; }
        var boton = this.querySelector('[type=submit]');
        boton.disabled = true;
        var datosCuenta = {
            cliente_id: cli.id, concepto: $('cu-concepto').value, fecha: $('cu-fecha').value, vencimiento: $('cu-vence').value,
            dias_credito: $('cu-dias').value, guardar_credito: $('cu-guardar-credito').checked ? 1 : 0,
            subtotal: $('cu-subtotal').value, iva: $('cu-iva').value, retenciones: $('cu-ret').value,
            factura: $('cu-factura').value, notas: $('cu-notas').value,
            origen_tipo: origenActual ? origenActual.tipo : null, origen_id: origenActual ? origenActual.id : null
        };
        // L-1: si el origen ya tiene cuenta, el servidor pide confirmar (p. ej. un cargo adicional)
        api('crear_cuenta', null, datosCuenta).catch(function (err) {
            if (err.codigo !== 'confirmar') throw err;
            return AppAviso.confirmar(err.message, { titulo: 'Ya tiene cuenta por cobrar', boton: 'Registrar otra cuenta', peligro: true })
                .then(function (si) {
                    if (!si) { var c = new Error('No se registró la cuenta.'); c.cancelado = true; throw c; }
                    datosCuenta.forzar = 1;
                    return api('crear_cuenta', null, datosCuenta);
                });
        }).then(function (j) {
            if ($('cu-guardar-credito').checked) cli.dias_credito = parseInt($('cu-dias').value, 10) || 0;
            cerrar($('modal-cuenta'));
            avisar('Cuenta ' + j.cuenta.folio + ' registrada por ' + dinero(j.cuenta.total) + '.');
            // D-48: avisa (no bloquea) si el cliente rebasa su límite de crédito
            if (j.cuenta.aviso_credito) AppDialogo.mostrar({ tipo: 'aviso', titulo: 'Límite de crédito rebasado', mensaje: j.cuenta.aviso_credito });
            refrescar();
        }).catch(function (err) { if (!err.cancelado) avisar(err.message, 'error'); })
            .then(function () { boton.disabled = false; });
    });

    // ---------------------------------------------------------- cuentas
    function filtrosCuentas() {
        return { buscar: $('c-buscar').value.trim(), estado: $('c-estado').value, desde: $('c-desde').value, hasta: $('c-hasta').value };
    }
    function cargarCuentas() {
        return api('cuentas', filtrosCuentas()).then(function (j) {
            var tb = $('tabla-cuentas');
            if (!j.cuentas.length) { tb.innerHTML = '<tr><td colspan="9" class="inv-vacio">Sin cuentas con estos filtros.</td></tr>'; $('c-suma').textContent = ''; return; }
            tb.innerHTML = j.cuentas.map(function (c) {
                var abierta = c.estatus === 'pendiente' || c.estatus === 'parcial';
                return '<tr data-cuenta="' + c.id + '">' +
                    '<td><b>' + esc(c.folio) + '</b></td>' +
                    '<td>' + esc(c.cliente_nombre) + '</td>' +
                    '<td>' + esc(c.concepto) + (c.origen_folio ? '<span class="inv-sub">' + esc(ORIGEN_NOMBRE[c.origen_tipo] + ' ' + c.origen_folio) + '</span>' : '') + '</td>' +
                    '<td>' + (c.factura ? esc(c.factura) : '<span class="inv-sub">Sin anotar</span>') + '</td>' +
                    '<td>' + fecha(c.vencimiento) + '</td>' +
                    '<td class="inv-num">' + dinero(c.total) + '</td>' +
                    '<td class="inv-num"><b>' + dinero(c.saldo) + '</b></td>' +
                    '<td>' + etiquetaEstatus(c) + '</td>' +
                    '<td class="inv-acciones">' + (abierta ? '<button type="button" class="btn-primary" data-cobrar="' + c.id + '"><i class="fas fa-hand-holding-usd"></i> Cobrar</button>' : '') +
                    '<button type="button" class="btn-secundario" data-ver="' + c.id + '" title="Ver detalle"><i class="fas fa-eye"></i></button></td></tr>';
            }).join('');
            var saldo = j.cuentas.reduce(function (s, c) { return s + num(c.saldo); }, 0);
            $('c-suma').textContent = j.cuentas.length + (j.cuentas.length === 1 ? ' cuenta' : ' cuentas') + ' · saldo ' + dinero(saldo) + (j.cuentas.length >= 500 ? ' (se muestran las primeras 500)' : '');
        }).catch(function (e) { avisar(e.message, 'error'); });
    }
    var espera = null;
    function conPausa(fn) { return function () { clearTimeout(espera); espera = setTimeout(fn, 300); }; }
    $('c-buscar').addEventListener('input', conPausa(cargarCuentas));
    ['c-estado', 'c-desde', 'c-hasta'].forEach(function (id) { $(id).addEventListener('change', cargarCuentas); });
    $('tabla-cuentas').addEventListener('click', function (e) {
        var b = e.target.closest('[data-cobrar], [data-ver]');
        var fila = e.target.closest('[data-cuenta]');
        if (b) abrirDetalle(b.dataset.cobrar || b.dataset.ver, !!b.dataset.cobrar);
        else if (fila) abrirDetalle(fila.dataset.cuenta, false);
    });

    // ---------------------------------------------------------- detalle y cobros
    function abrirDetalle(id, enfocarCobro) {
        return api('cuenta', { id: id }).then(function (j) {
            pintarDetalle(j.cuenta);
            abrir('modal-detalle');
            if (enfocarCobro && !$('form-cobro').hidden) $('co-monto').focus();
        }).catch(function (e) { avisar(e.message, 'error'); });
    }

    function pintarDetalle(c) {
        cuentaActual = c;
        $('d-titulo').innerHTML = esc(c.folio) + ' ' + etiquetaEstatus(c);
        var filas = [
            ['Cliente', esc(c.cliente_nombre)], ['Concepto', esc(c.concepto)],
            c.origen_folio ? ['Origen', enlaceOrigen(c.origen_tipo, c.origen_id, c.origen_folio)] : null,
            ['Fecha', fecha(c.fecha)], ['Vence', fecha(c.vencimiento) + (c.dias_vencida > 0 ? ' <span class="fin-rojo">(' + c.dias_vencida + ' días vencida)</span>' : '')],
            ['Subtotal', dinero(c.subtotal)], ['IVA', dinero(c.iva)], num(c.retenciones) > 0 ? ['Retenciones', '−' + dinero(c.retenciones)] : null,
            ['Total', '<b>' + dinero(c.total) + '</b>'], ['Cobrado', dinero(c.cobrado)], ['Saldo', '<b class="fin-saldo">' + dinero(c.saldo) + '</b>'],
            c.estatus === 'cancelada' ? ['Motivo de cancelación', esc(c.motivo_cancelacion)] : null,
            ['Registró', esc((c.creado_por_nombre || '') + ' · ' + fecha(c.creado_en))]
        ].filter(Boolean);
        $('d-resumen').innerHTML = '<dl>' + filas.map(function (f) { return '<dt>' + f[0] + '</dt><dd>' + f[1] + '</dd>'; }).join('') + '</dl>';

        $('d-cobros').innerHTML = c.cobros.length ? c.cobros.map(function (b) {
            var cancelado = String(b.cancelado) === '1';
            var esNota = b.tipo === 'nota_credito';  // D-48: reduce el saldo, no es dinero recibido
            return '<tr' + (cancelado ? ' class="fin-cancelado"' : '') + '><td>' + fecha(b.fecha) + '</td><td class="inv-num">' + dinero(b.monto) + '</td>' +
                '<td>' + (esNota ? '<b>Nota de crédito</b><span class="inv-sub">' + esc(b.notas || '') + '</span>' : esc(FORMAS[b.forma] || b.forma)) + '</td>' +
                '<td>' + esc(b.referencia || '') + (cancelado ? '<span class="inv-sub">Cancelado: ' + esc(b.motivo_cancelacion) + '</span>' : '') + '</td>' +
                '<td>' + (b.comprobante ? '<a href="' + esc(urlArchivo(b.comprobante)) + '" target="_blank" rel="noopener"><i class="fas fa-paperclip"></i> Ver</a>' : '') +
                // O-1: adjuntar (o reemplazar) el comprobante de un cobro ya registrado
                (!cancelado && !esNota ? ' <button type="button" class="fin-link" data-comprobante-cobro="' + b.id + '" title="' + (b.comprobante ? 'Reemplazar el comprobante' : 'Adjuntar el comprobante') + '">' +
                    '<i class="fas fa-' + (b.comprobante ? 'rotate' : 'upload') + '"></i> ' + (b.comprobante ? 'Reemplazar' : 'Adjuntar') + '</button>' : '') + '</td>' +
                '<td class="fin-acciones-cobro">' + (!cancelado && c.puede_corregir_cobro
                    // O-2 (D-22): corregir = cancelar con motivo y registrar uno nuevo con los datos ya llenos
                    ? (esNota ? '' : '<button type="button" class="fin-link" data-corregir-cobro="' + b.id + '" title="Corregir: cancela este cobro y abre uno nuevo con sus datos"><i class="fas fa-pen"></i> Corregir</button>') +
                      '<button type="button" class="btn-delete" data-cancelar-cobro="' + b.id + '" title="Cancelar cobro"><i class="fas fa-ban"></i></button>' : '') + '</td></tr>';
        }).join('') : '<tr><td colspan="6" class="inv-vacio">Aún no hay cobros.</td></tr>';

        var abierta = c.estatus === 'pendiente' || c.estatus === 'parcial';
        $('form-cobro').hidden = !abierta;
        $('form-cobro').reset();
        limpiarArchivo($('co-comprobante'));
        $('co-fecha').value = hoy();
        $('co-fecha').max = hoy();
        $('co-monto').value = num(c.saldo).toFixed(2);
        $('co-monto').max = num(c.saldo).toFixed(2);
        $('form-editar').hidden = c.estatus === 'cancelada';
        $('ed-factura').value = c.factura || '';
        $('ed-vence').value = c.vencimiento;
        $('ed-notas').value = c.notas || '';
        $('d-cancelar').hidden = !(c.puede_cancelar && c.estatus !== 'cancelada' && num(c.cobrado) === 0);
        $('d-nota-credito').hidden = !(abierta && c.puede_corregir_cobro);  // D-48
        $('d-estado-cuenta').hidden = !c.cliente_id;
    }

    $('form-cobro').addEventListener('submit', function (e) {
        e.preventDefault();
        var monto = r2($('co-monto').value);
        if (monto <= 0) { avisar('Escribe el monto cobrado.', 'error'); $('co-monto').focus(); return; }
        if (monto > num(cuentaActual.saldo) + 0.005) { avisar('El cobro es mayor al saldo (' + dinero(cuentaActual.saldo) + ').', 'error'); return; }
        var boton = this.querySelector('[type=submit]');
        boton.disabled = true;
        api('cobro', null, conArchivo({
            cuenta_id: cuentaActual.id, fecha: $('co-fecha').value, monto: monto, forma: $('co-forma').value, referencia: $('co-ref').value
        }, $('co-comprobante'))).then(function (j) {
            pintarDetalle(j.cuenta);
            avisar(j.cuenta.estatus === 'pagada' ? 'Cobro registrado: la cuenta ' + j.cuenta.folio + ' quedó pagada.' : 'Cobro registrado. Saldo: ' + dinero(j.cuenta.saldo) + '.');
            refrescar();
        }).catch(function (err) { avisar(err.message, 'error'); })
            .then(function () { boton.disabled = false; });
    });

    $('d-cobros').addEventListener('click', function (e) {
        var adj = e.target.closest('[data-comprobante-cobro]');
        if (adj) {
            var input = document.createElement('input');
            input.type = 'file';
            input.accept = '.pdf,.jpg,.jpeg,.png,.webp,application/pdf,image/*';
            input.addEventListener('change', function () {
                if (!input.files || !input.files[0]) return;
                adj.disabled = true;
                api('comprobante_cobro', null, conArchivo({ id: adj.dataset.comprobanteCobro }, input)).then(function (j) {
                    pintarDetalle(j.cuenta);
                    avisar('Comprobante guardado. El anterior, si había, queda en el historial.');
                }).catch(function (err) { avisar(err.message, 'error'); adj.disabled = false; });
            });
            input.click();
            return;
        }
        var cor = e.target.closest('[data-corregir-cobro]');
        if (cor) {
            var previo = (cuentaActual.cobros || []).filter(function (x) { return String(x.id) === cor.dataset.corregirCobro; })[0];
            if (!previo) return;
            pedirMotivo('¿Corregir este cobro?', 'Se cancela este cobro (queda en el historial) y se abre el formulario con sus datos para registrarlo bien.').then(function (motivo) {
                if (!motivo) return;
                return api('cancelar_cobro', null, { id: previo.id, motivo: 'Corrección: ' + motivo }).then(function (j) {
                    pintarDetalle(j.cuenta);
                    refrescar();
                    $('co-fecha').value = previo.fecha;
                    $('co-monto').value = num(previo.monto).toFixed(2);
                    $('co-forma').value = previo.forma;
                    $('co-ref').value = previo.referencia || '';
                    $('form-cobro').scrollIntoView({ behavior: 'smooth', block: 'center' });
                    $('co-monto').focus();
                    avisar('Cobro cancelado. Ajusta los datos y registra el cobro correcto.');
                });
            }).catch(function (err) { avisar(err.message, 'error'); });
            return;
        }
        var b = e.target.closest('[data-cancelar-cobro]');
        if (!b) return;
        pedirMotivo('¿Cancelar este cobro?', 'El monto vuelve al saldo de la cuenta. Queda constancia en el historial.').then(function (motivo) {
            if (!motivo) return;
            return api('cancelar_cobro', null, { id: b.dataset.cancelarCobro, motivo: motivo }).then(function (j) {
                pintarDetalle(j.cuenta);
                avisar('Cobro cancelado.');
                refrescar();
            });
        }).catch(function (err) { avisar(err.message, 'error'); });
    });

    // D-48: nota de crédito (descuento, devolución o ajuste): reduce el saldo y no es dinero recibido
    $('d-nota-credito').addEventListener('click', function () {
        var c = cuentaActual;
        var caja = document.createElement('div');
        caja.className = 'alta-campos';
        caja.innerHTML = '<label class="alta-campo"><span>Monto (máximo ' + esc(dinero(c.saldo)) + ')</span><input name="monto" type="number" min="0.01" step="0.01" max="' + num(c.saldo).toFixed(2) + '" required></label>' +
            '<label class="alta-campo"><span>Folio o UUID del CFDI de egreso</span><input name="referencia" maxlength="100" placeholder="Opcional"></label>' +
            '<label class="alta-campo" style="grid-column:1/-1"><span>Motivo</span><input name="motivo" maxlength="255" required placeholder="Ej. Descuento acordado, devolución, error de facturación"></label>' +
            '<p class="texto-suave" style="grid-column:1/-1">No se borra: si se registra por error, se cancela. El tratamiento fiscal lo valida tu contador.</p>';
        AppDialogo.mostrar({ tipo: 'info', titulo: 'Nota de crédito · ' + c.folio, contenido: caja, ancho: true,
            botones: [{ texto: 'Cancelar', valor: null, estilo: 'secundario' }, { texto: 'Registrar nota de crédito', valor: 'ok', estilo: 'primario' }]
        }).then(function (v) {
            if (v !== 'ok') return;
            var d = { cuenta_id: c.id };
            caja.querySelectorAll('input').forEach(function (i) { d[i.name] = i.value; });
            return api('nota_credito', null, d).then(function (j) { pintarDetalle(j.cuenta); avisar('Nota de crédito registrada.'); refrescar(); });
        }).catch(function (err) { avisar(err.message, 'error'); });
    });

    $('form-editar').addEventListener('submit', function (e) {
        e.preventDefault();
        api('editar_cuenta', null, { id: cuentaActual.id, factura: $('ed-factura').value, vencimiento: $('ed-vence').value, notas: $('ed-notas').value })
            .then(function (j) { pintarDetalle(j.cuenta); avisar('Datos de la cuenta guardados.'); refrescar(); })
            .catch(function (err) { avisar(err.message, 'error'); });
    });

    $('d-cancelar').addEventListener('click', function () {
        pedirMotivo('¿Cancelar la cuenta ' + cuentaActual.folio + '?', 'Deja de contar como saldo por cobrar. Queda constancia en el historial.').then(function (motivo) {
            if (!motivo) return;
            return api('cancelar_cuenta', null, { id: cuentaActual.id, motivo: motivo }).then(function (j) {
                pintarDetalle(j.cuenta);
                avisar('Cuenta cancelada.');
                refrescar();
            });
        }).catch(function (err) { avisar(err.message, 'error'); });
    });

    $('d-estado-cuenta').addEventListener('click', function () {
        if (cuentaActual && cuentaActual.cliente_id) window.open('estado-cuenta.html?cliente_id=' + encodeURIComponent(cuentaActual.cliente_id), '_blank', 'noopener');
    });

    // ---------------------------------------------------------- gastos
    function filtrosGastos() {
        return { buscar: $('g-buscar').value.trim(), categoria: $('g-categoria').value, desde: $('g-desde').value, hasta: $('g-hasta').value };
    }
    function cargarGastos() {
        return api('gastos', filtrosGastos()).then(function (j) {
            gastosPuedeCancelar = j.puede_cancelar;
            var elegida = $('g-categoria').value;
            $('g-categoria').innerHTML = '<option value="">Todas las categorías</option>' + j.categorias.map(function (c) {
                return '<option' + (c === elegida ? ' selected' : '') + '>' + esc(c) + '</option>';
            }).join('');
            $('lista-categorias').innerHTML = j.categorias.map(function (c) { return '<option value="' + esc(c) + '">'; }).join('');
            var tb = $('tabla-gastos');
            if (!j.gastos.length) { tb.innerHTML = '<tr><td colspan="8" class="inv-vacio">Sin gastos con estos filtros.</td></tr>'; $('g-suma').textContent = ''; return; }
            tb.innerHTML = j.gastos.map(function (g) {
                return '<tr><td>' + fecha(g.fecha) + '</td><td>' + esc(g.categoria) + '</td><td>' + esc(g.concepto) +
                    (g.referencia ? '<span class="inv-sub">' + esc(g.referencia) + '</span>' : '') + '</td>' +
                    '<td>' + esc(g.proveedor || '') + '</td>' +
                    '<td>' + (g.incidencia_id ? enlaceOrigen('incidencia', g.incidencia_id, g.incidencia_folio) : '') + '</td>' +
                    '<td class="inv-num">' + dinero(g.monto) + (num(g.iva) > 0 ? '<span class="inv-sub">IVA ' + dinero(g.iva) + '</span>' : '') + '</td>' +
                    '<td>' + (g.comprobante ? '<a href="' + esc(urlArchivo(g.comprobante)) + '" target="_blank" rel="noopener"><i class="fas fa-paperclip"></i> Ver</a>' : '') + '</td>' +
                    '<td class="inv-acciones"><button type="button" class="btn-secundario" data-editar-gasto=\'' + esc(JSON.stringify(g)) + '\' title="Editar"><i class="fas fa-pen"></i></button>' +
                    (gastosPuedeCancelar ? '<button type="button" class="btn-delete" data-cancelar-gasto="' + g.id + '" title="Cancelar gasto"><i class="fas fa-ban"></i></button>' : '') + '</td></tr>';
            }).join('');
            var total = j.gastos.reduce(function (s, g) { return s + num(g.monto); }, 0);
            $('g-suma').textContent = j.gastos.length + (j.gastos.length === 1 ? ' gasto' : ' gastos') + ' · total ' + dinero(total);
        }).catch(function (e) { avisar(e.message, 'error'); });
    }
    $('g-buscar').addEventListener('input', conPausa(cargarGastos));
    ['g-categoria', 'g-desde', 'g-hasta'].forEach(function (id) { $(id).addEventListener('change', cargarGastos); });

    function abrirGasto(g) {
        var f = $('form-gasto');
        f.reset();
        limpiarArchivo($('ga-comprobante'));
        f.elements.id.value = g ? g.id : '';
        $('ga-titulo').textContent = g ? 'Editar gasto' : 'Registrar gasto';
        $('ga-fecha').value = g ? g.fecha : hoy();
        $('ga-fecha').max = hoy();
        if (g) {
            ['categoria', 'concepto', 'proveedor', 'referencia'].forEach(function (k) { f.elements[k].value = g[k] || ''; });
            f.elements.forma.value = g.forma;
            f.elements.monto.value = g.monto;
            f.elements.iva.value = g.iva;
            f.elements.incidencia_folio.value = g.incidencia_folio || '';
        }
        if (!$('lista-categorias').options.length) api('gastos', { desde: hoy() }).then(function (j) {
            $('lista-categorias').innerHTML = j.categorias.map(function (c) { return '<option value="' + esc(c) + '">'; }).join('');
        });
        abrir('modal-gasto');
        (g ? $('ga-concepto') : $('ga-categoria')).focus();
    }
    $('btn-nuevo-gasto').addEventListener('click', function () { abrirGasto(null); });
    $('tabla-gastos').addEventListener('click', function (e) {
        var ed = e.target.closest('[data-editar-gasto]');
        if (ed) { abrirGasto(JSON.parse(ed.getAttribute('data-editar-gasto'))); return; }
        var b = e.target.closest('[data-cancelar-gasto]');
        if (!b) return;
        pedirMotivo('¿Cancelar este gasto?', 'Deja de contar en los totales y en la rentabilidad. Queda constancia en el historial.').then(function (motivo) {
            if (!motivo) return;
            return api('cancelar_gasto', null, { id: b.dataset.cancelarGasto, motivo: motivo }).then(function () { avisar('Gasto cancelado.'); refrescar(); });
        }).catch(function (err) { avisar(err.message, 'error'); });
    });

    // Buscar la orden de servicio por folio o cliente
    $('ga-orden').addEventListener('input', conPausa(function () {
        var q = $('ga-orden').value.trim();
        if (q.length < 2) return;
        api('buscar_origen', { q: q, solo_ordenes: 1 }).then(function (j) {
            $('lista-ordenes').innerHTML = j.resultados.map(function (r) {
                return '<option value="' + esc(r.folio) + '">' + esc(r.cliente + ' · ' + fecha(r.fecha)) + '</option>';
            }).join('');
        }).catch(function () {});
    }));

    $('form-gasto').addEventListener('submit', function (e) {
        e.preventDefault();
        var f = this;
        if (!f.elements.categoria.value.trim()) { avisar('Elige o escribe la categoría.', 'error'); f.elements.categoria.focus(); return; }
        if (!f.elements.concepto.value.trim()) { avisar('Escribe el concepto.', 'error'); f.elements.concepto.focus(); return; }
        if (num(f.elements.monto.value) <= 0) { avisar('Escribe el total pagado.', 'error'); f.elements.monto.focus(); return; }
        var datos = {};
        ['id', 'fecha', 'categoria', 'concepto', 'proveedor', 'forma', 'monto', 'iva', 'referencia', 'incidencia_folio'].forEach(function (k) { datos[k] = f.elements[k].value; });
        var boton = f.querySelector('[type=submit]');
        boton.disabled = true;
        api('guardar_gasto', null, conArchivo(datos, $('ga-comprobante'))).then(function () {
            cerrar($('modal-gasto'));
            avisar(datos.id ? 'Gasto actualizado.' : 'Gasto registrado.');
            refrescar();
        }).catch(function (err) { avisar(err.message, 'error'); })
            .then(function () { boton.disabled = false; });
    });

    // ---------------------------------------------------------- rentabilidad
    function margen(pct) { return pct === null || pct === undefined ? '—' : pct + '%'; }
    function cargarRentabilidad() {
        return api('rentabilidad', { desde: $('r-desde').value, hasta: $('r-hasta').value }).then(function (j) {
            var r = j.rentabilidad, t = r.totales;
            $('r-ingreso').textContent = dinero(t.ingreso);
            $('r-refacciones').textContent = dinero(t.refacciones);
            $('r-gastos').textContent = dinero(t.gastos);
            $('r-utilidad').textContent = dinero(t.utilidad);
            $('r-utilidad').classList.toggle('fin-negativo', t.utilidad < 0);
            $('r-margen').textContent = margen(t.margen_pct);
            $('tabla-rent').innerHTML = r.ordenes.length ? r.ordenes.map(function (o) {
                return '<tr' + (o.utilidad < 0 ? ' class="fin-perdida"' : '') + '><td>' + enlaceOrigen('incidencia', o.id, o.numero_incidente) + '</td><td>' + fecha(o.fecha) + '</td>' +
                    '<td>' + esc(o.cliente) + '</td><td>' + esc(o.tecnico || '') + '</td>' +
                    '<td class="inv-num">' + (o.sin_ingreso ? '<span class="etiqueta etiqueta-alerta" title="Tiene costos pero ninguna cuenta por cobrar">Sin cobrar</span>' : dinero(o.ingreso)) + '</td>' +
                    '<td class="inv-num">' + dinero(o.refacciones) + '</td><td class="inv-num">' + dinero(o.gastos) + '</td>' +
                    '<td class="inv-num"><b>' + dinero(o.utilidad) + '</b></td><td>' + margen(o.margen_pct) + '</td></tr>';
            }).join('') : '<tr><td colspan="9" class="inv-vacio">Ninguna orden del periodo tiene ingresos ni costos registrados.</td></tr>';
            $('tabla-rent-clientes').innerHTML = r.clientes.length ? r.clientes.map(function (c) {
                return '<tr><td>' + esc(c.cliente) + '</td><td>' + c.ordenes + '</td><td class="inv-num">' + dinero(c.ingreso) + '</td>' +
                    '<td class="inv-num">' + dinero(c.costo) + '</td><td class="inv-num"><b>' + dinero(c.utilidad) + '</b></td><td>' + margen(c.margen_pct) + '</td></tr>';
            }).join('') : '<tr><td colspan="6" class="inv-vacio">Sin datos en el periodo.</td></tr>';
        }).catch(function (e) { avisar(e.message, 'error'); });
    }
    ['r-desde', 'r-hasta'].forEach(function (id) { $(id).addEventListener('change', cargarRentabilidad); });

    // ---------------------------------------------------------- exportar
    document.addEventListener('click', function (e) {
        var b = e.target.closest('[data-exportar]');
        if (!b) return;
        var tipo = b.dataset.exportar, p = tipo === 'gastos' ? 'g' : 'c';
        var desde = $(p + '-desde').value || inicioMes(), hasta = $(p + '-hasta').value || hoy();
        location.href = API + '?' + new URLSearchParams({ accion: 'exportar', tipo: tipo, desde: desde, hasta: hasta }).toString();
    });

    // ---------------------------------------------------------- inicio
    ['co-forma', 'ga-forma'].forEach(function (id) {
        $(id).innerHTML = Object.keys(FORMAS).map(function (k) { return '<option value="' + k + '">' + FORMAS[k] + '</option>'; }).join('');
    });
    $('r-desde').value = sumarDias(hoy(), -90);
    $('r-hasta').value = hoy();
    $('g-desde').value = inicioMes();

    cargarClientes().catch(function () {});
    mostrarPestana((location.hash || '#resumen').substr(1));
    var q = new URLSearchParams(location.search);
    if (q.get('nueva') && q.get('id')) abrirCuenta({ tipo: q.get('nueva'), id: q.get('id') });
    if (q.get('cuenta')) abrirDetalle(q.get('cuenta'), false);  // enlace desde el perfil del cliente (D-48)
})();
