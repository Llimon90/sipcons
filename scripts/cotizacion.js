// public/cotizacion.html - alta, edición y detalle de una cotización.
//
// - Los importes que se ven aquí son una VISTA PREVIA: el servidor recalcula
//   y redondea todo al guardar (backend/lib/cotizaciones_helpers.php).
// - Lo que se escribe se respalda en el navegador (borrador local) hasta que
//   se guarda, como en las incidencias.
// - "version" detecta si alguien más modificó la cotización (409).
(function () {
    var C = window.AppCotizaciones;
    var esc = C.esc;
    var TASAS = [{ valor: '0.16', texto: '16%' }, { valor: '0.08', texto: '8%' }, { valor: '0', texto: '0%' }, { valor: 'exento', texto: 'Exento' }];

    var estado = {
        id: null,
        cot: null,          // cotización tal como llegó del servidor
        config: null,
        permisos: {},
        editable: true,
        partidas: [],
        clientes: [],
        cambios: false,
        guardando: false,
        finanzas: false,    // el rol tiene el módulo Finanzas (acceso a Cobranza)
        incidenciaId: null
    };

    function $(id) { return document.getElementById(id); }
    function num(v) { return parseFloat(v) || 0; }
    function r2(x) { return Math.round((x + Number.EPSILON) * 100) / 100; }

    // Aviso estándar del sistema (AppAviso, scripts/dialogo.js)
    function toast(texto, tipo) {
        AppAviso.mostrar(texto, tipo || 'ok');
    }

    function error(e, titulo) {
        return AppDialogo.mostrar({ tipo: 'error', titulo: titulo || 'No se pudo completar', mensaje: e.message || String(e) });
    }

    // ---------------------------------------------------------- cálculo (vista previa)
    function calcularPartida(p, incluyeIva) {
        var cant = Math.max(0, r2(num(p.cantidad))), precio = Math.max(0, r2(num(p.precio_unitario)));
        var pct = Math.min(100, Math.max(0, r2(num(p.descuento_pct))));
        var tasa = p.es_exento ? 0 : num(p.tasa_iva);
        var bruto = r2(cant * precio), importe, iva, desc;
        if (incluyeIva) {
            var total = r2(bruto * (1 - pct / 100));
            importe = tasa > 0 ? r2(total / (1 + tasa)) : total;
            iva = r2(total - importe);
            var descConIva = r2(bruto - total);
            desc = tasa > 0 ? r2(descConIva / (1 + tasa)) : descConIva;
        } else {
            desc = r2(bruto * pct / 100);
            importe = r2(bruto - desc);
            iva = r2(importe * tasa);
        }
        return { importe: importe, iva: iva, descuento: desc, costo: r2(num(p.costo_unitario) * cant) };
    }

    function totales() {
        var incluye = $('c-incluye-iva').checked;
        var t = { subtotal: 0, descuento: 0, iva: 0, base: 0, costo: 0, porTasa: {} };
        estado.partidas.forEach(function (p) {
            var c = calcularPartida(p, incluye);
            p._calc = c;
            t.subtotal += c.importe + c.descuento; t.descuento += c.descuento; t.iva += c.iva; t.base += c.importe; t.costo += c.costo;
            var clave = p.es_exento ? 'Exento' : 'IVA ' + Math.round(num(p.tasa_iva) * 100) + '%';
            t.porTasa[clave] = r2((t.porTasa[clave] || 0) + c.iva);
        });
        t.total = r2(t.base + t.iva);
        t.margen = r2(t.base - t.costo);
        t.margenPct = t.base > 0 ? t.margen / t.base * 100 : null;
        return t;
    }

    // ---------------------------------------------------------- pintar partidas
    function monedaActual() { return $('c-moneda').value; }

    function pintarCabecera() {
        var m = estado.permisos.ve_margen;
        $('cabeza-partidas').innerHTML = '<tr><th>#</th><th>Descripción</th><th>Cantidad</th><th>P. unitario</th><th>Desc. %</th><th>IVA</th><th>Importe</th>' +
            (m ? '<th>Costo</th><th>Margen</th>' : '') + (estado.editable ? '<th></th>' : '') + '</tr>';
    }

    function celdaIva(p, i) {
        var actual = p.es_exento ? 'exento' : String(num(p.tasa_iva));
        if (!estado.editable) return (TASAS.find(function (t) { return t.valor === actual; }) || {}).texto || actual;
        return '<select data-campo="iva" data-i="' + i + '" aria-label="IVA">' + TASAS.map(function (t) {
            return '<option value="' + t.valor + '"' + (t.valor === actual ? ' selected' : '') + '>' + t.texto + '</option>';
        }).join('') + '</select>';
    }

    function pintarPartidas() {
        pintarCabecera();
        var m = estado.permisos.ve_margen;
        var moneda = monedaActual();
        var tbody = $('tabla-partidas');
        var columnas = 7 + (m ? 2 : 0) + (estado.editable ? 1 : 0);
        totales();
        if (!estado.partidas.length) {
            tbody.innerHTML = '<tr><td colspan="' + columnas + '" class="inv-vacio">' +
                (estado.editable ? 'Agrega productos o servicios del catálogo con los botones de arriba.' : 'Sin partidas.') + '</td></tr>';
            pintarTotales();
            return;
        }
        var minimo = estado.permisos.margen_minimo || 15;
        tbody.innerHTML = estado.partidas.map(function (p, i) {
            var sub = [p.sku, [p.marca, p.modelo].filter(Boolean).join(' ')].filter(Boolean).join(' · ');
            var avisoExist = '';
            // Ya registrada en la incidencia (trabajo hecho): no se vuelve a descontar
            var instalada = String(p.cargada_incidencia) === '1' && p.tipo === 'refaccion';
            if (instalada) avisoExist = '<span class="etiqueta etiqueta-ok" title="Ya se registró en la orden de servicio y se descontó del inventario">Ya instalada</span>';
            else if (estado.editable && p.controla_existencia !== undefined && Number(p.controla_existencia) === 1 && p.existencia !== undefined) {
                if (num(p.existencia) <= 0) avisoExist = '<span class="etiqueta etiqueta-error">Sin existencia</span>';
                else if (num(p.existencia) <= num(p.stock_minimo)) avisoExist = '<span class="etiqueta etiqueta-alerta">Bajo mínimo</span>';
            }
            var aceptacion = p.aceptada === '0' || p.aceptada === 0 ? ' <span class="etiqueta etiqueta-alerta">No aceptada</span>' : '';
            var margenPct = p._calc.importe > 0 ? (p._calc.importe - p._calc.costo) / p._calc.importe * 100 : null;
            var campo = function (nombre, valor, paso, ancho) {
                return estado.editable
                    ? '<input type="number" min="0" step="' + paso + '" data-campo="' + nombre + '" data-i="' + i + '" value="' + valor + '" class="cot-num" style="width:' + ancho + '">'
                    : (nombre === 'precio_unitario' ? C.dinero(valor, moneda) : C.cantidad(valor));
            };
            return '<tr' + (aceptacion ? ' class="cot-no-aceptada"' : '') + '>' +
                '<td>' + (i + 1) + '</td>' +
                '<td class="cot-desc">' + (estado.editable
                    ? '<input type="text" maxlength="255" data-campo="descripcion" data-i="' + i + '" value="' + esc(p.descripcion) + '">'
                    : '<strong>' + esc(p.descripcion) + '</strong>') +
                '<span class="inv-sub">' + esc(sub) + (sub ? ' · ' : '') + esc(p.unidad) + '</span>' + avisoExist + aceptacion + '</td>' +
                '<td>' + campo('cantidad', p.cantidad, '0.01', '80px') + '</td>' +
                '<td>' + campo('precio_unitario', p.precio_unitario, '0.01', '110px') + '</td>' +
                '<td>' + campo('descuento_pct', p.descuento_pct, '0.01', '70px') + '</td>' +
                '<td>' + celdaIva(p, i) + '</td>' +
                '<td class="inv-num"><strong>' + C.dinero(p._calc.importe, moneda) + '</strong></td>' +
                (m ? '<td class="inv-num">' + C.dinero(p.costo_unitario, moneda) + '</td>' +
                     '<td class="inv-num' + (margenPct !== null && margenPct < minimo ? ' cot-margen-bajo' : '') + '">' + (margenPct === null ? '—' : margenPct.toFixed(1) + '%') + '</td>' : '') +
                (estado.editable ? '<td><div class="inv-acciones">' +
                    '<button type="button" class="btn-edit" data-mover="-1" data-i="' + i + '" title="Subir"' + (i === 0 ? ' disabled' : '') + '><i class="fas fa-arrow-up"></i></button>' +
                    '<button type="button" class="btn-edit" data-mover="1" data-i="' + i + '" title="Bajar"' + (i === estado.partidas.length - 1 ? ' disabled' : '') + '><i class="fas fa-arrow-down"></i></button>' +
                    '<button type="button" class="btn-delete" data-quitar="' + i + '" title="Quitar"><i class="fas fa-trash"></i></button></div></td>' : '') +
                '</tr>';
        }).join('');
        pintarTotales();
    }

    function pintarTotales() {
        var t = totales();
        var moneda = monedaActual();
        var filas = '<div><span>Subtotal</span><span>' + C.dinero(t.subtotal, moneda) + '</span></div>';
        if (t.descuento > 0) filas += '<div><span>Descuento</span><span>−' + C.dinero(t.descuento, moneda) + '</span></div>';
        Object.keys(t.porTasa).forEach(function (k) {
            filas += '<div><span>' + esc(k) + '</span><span>' + C.dinero(t.porTasa[k], moneda) + '</span></div>';
        });
        filas += '<div class="cot-total"><span>Total ' + moneda + '</span><span>' + C.dinero(t.total, moneda) + '</span></div>';
        if (estado.cot && estado.cot.total_aprobado !== null && estado.cot.total_aprobado !== undefined && Number(estado.cot.aprobacion_parcial) === 1) {
            filas += '<div class="cot-aprobado"><span>Total aprobado</span><span>' + C.dinero(estado.cot.total_aprobado, moneda) + '</span></div>';
        }
        if (moneda === 'USD' && num($('c-tc').value) > 0) {
            filas += '<div class="cot-equivalente"><span>Equivale a</span><span>' + C.dinero(t.total * num($('c-tc').value), 'MXN') + ' MXN</span></div>';
        }
        if (estado.permisos.ve_margen && estado.partidas.length) {
            var bajo = t.margenPct !== null && t.margenPct < (estado.permisos.margen_minimo || 15);
            filas += '<div class="cot-margen' + (bajo ? ' bajo' : '') + '" title="Solo lo ven Administrador y Administrativo; nunca aparece en el PDF">' +
                '<span><i class="fas fa-lock"></i> Margen interno</span><span>' + C.dinero(t.margen, moneda) +
                (t.margenPct === null ? '' : ' · ' + t.margenPct.toFixed(1) + '%') + '</span></div>' +
                (bajo ? '<p class="cot-alerta-margen"><i class="fas fa-exclamation-triangle"></i> El margen es menor a ' + (estado.permisos.margen_minimo || 15) + '%.</p>' : '');
        }
        if (estado.editable && estado.config) {
            var max = estado.config.descuento_max;
            var excedido = estado.partidas.some(function (p) { return num(p.descuento_pct) > max; }) || (t.subtotal > 0 && t.descuento / t.subtotal * 100 > max);
            if (excedido) {
                filas += '<p class="cot-alerta-margen"><i class="fas fa-user-shield"></i> El descuento rebasa el máximo de ' + max + '%' +
                    (estado.permisos.autoriza_descuentos ? ': lo autorizas tú al guardar.' : ': necesita que lo autorice un administrador.') + '</p>';
            }
        }
        $('cot-totales').innerHTML = '<h3>Resumen</h3>' + filas;
    }

    // ---------------------------------------------------------- encabezado
    function clientePorNombre(nombre) {
        nombre = (nombre || '').trim().toUpperCase();
        return estado.clientes.find(function (c) { return c.nombre.trim().toUpperCase() === nombre; });
    }

    function cargarContactos(clienteId, seleccionado) {
        var sel = $('c-contacto');
        if (!clienteId) { sel.innerHTML = '<option value="">Sin contacto</option>'; return Promise.resolve(); }
        return Promise.all([C.api('contactos', { cliente_id: clienteId }), C.api('sucursales', { cliente_id: clienteId })]).then(function (r) {
            sel.innerHTML = '<option value="">Sin contacto</option>' + r[0].contactos.map(function (c) {
                return '<option value="' + c.id + '"' + (String(c.id) === String(seleccionado) ? ' selected' : '') + '>' + esc(c.nombre) + '</option>';
            }).join('');
            $('lista-sucursales').innerHTML = r[1].sucursales.map(function (s) { return '<option value="' + esc(s) + '">'; }).join('');
        });
    }

    function leerDatos() {
        var cliente = clientePorNombre($('c-cliente').value);
        return {
            cliente_id: cliente ? cliente.id : 0,
            sucursal: $('c-sucursal').value,
            contacto_id: $('c-contacto').value,
            fecha: $('c-fecha').value,
            vigencia_hasta: $('c-vigencia').value,
            moneda: $('c-moneda').value,
            // En cotización USD, el tc es el del documento; en MXN se manda el de la
            // empresa como tasa de referencia para convertir el COSTO de artículos en USD.
            tipo_cambio: $('c-moneda').value === 'USD'
                ? $('c-tc').value
                : (estado.config ? Number(estado.config.tipo_cambio) || '' : ''),
            precios_incluyen_iva: $('c-incluye-iva').checked ? 1 : 0,
            condiciones: $('c-condiciones').value,
            notas_cliente: $('c-notas-cliente').value,
            notas_internas: $('c-notas-internas').value,
            convertir_moneda: estado.convertirMoneda ? 1 : 0,  // O-13 (compatibilidad)
            // La interfaz convierte las partidas en vivo al cambiar la moneda, así que el
            // servidor no debe reconvertirlas (evita duplicar la conversión).
            ya_convertido: estado.monedaConvertidaEnVivo ? 1 : 0
        };
    }

    function leerPartidas() {
        return estado.partidas.map(function (p) {
            var r = { cantidad: p.cantidad, precio_unitario: p.precio_unitario, descuento_pct: p.descuento_pct, descripcion: p.descripcion,
                tasa_iva: p.es_exento ? 0 : p.tasa_iva, es_exento: p.es_exento ? 1 : 0 };
            if (p.id) { r.id = p.id; if (p.costo_unitario != null) r.costo_unitario = p.costo_unitario; }
            else r.articulo_id = p.articulo_id;
            return r;
        });
    }

    function llenarFormulario(c) {
        var cfg = estado.config || {};
        var hoy = estado.hoy;
        $('c-cliente').value = c ? c.cliente_nombre : '';
        $('c-sucursal').value = c ? (c.sucursal || '') : '';
        $('c-fecha').value = c ? c.fecha : hoy;
        $('c-vigencia').value = c ? c.vigencia_hasta : sumarDias(hoy, cfg.vigencia_dias || 15);
        $('c-moneda').value = c ? c.moneda : 'MXN';
        estado.monedaPrevia = $('c-moneda').value;  // para la conversión en vivo
        estado.monedaConvertidaEnVivo = false;
        $('c-tc').value = c && c.moneda === 'USD' ? num(c.tipo_cambio) : '';
        $('c-incluye-iva').checked = c ? Number(c.precios_incluyen_iva) === 1
            : !!(estado.config && Number(estado.config.precios_incluyen_iva) === 1);  // nueva: default de Mi empresa
        $('c-condiciones').value = c ? (c.condiciones || '') : (cfg.terminos || '');
        $('c-notas-cliente').value = c ? (c.notas_cliente || '') : '';
        $('c-notas-internas').value = c ? (c.notas_internas || '') : '';
        $('campo-tc').hidden = $('c-moneda').value !== 'USD';
        var cliente = c ? { id: c.cliente_id } : null;
        return cargarContactos(cliente && cliente.id, c ? c.contacto_id : '');
    }

    function sumarDias(fecha, dias) {
        var d = new Date(fecha + 'T12:00:00');
        d.setDate(d.getDate() + Number(dias));
        return d.toISOString().slice(0, 10);
    }

    function aplicarEditable() {
        var form = $('form-cot');
        form.querySelectorAll('input, select, textarea').forEach(function (el) {
            el.disabled = !estado.editable;
        });
        $('botones-partidas').hidden = !estado.editable;
        $('barra-guardar').hidden = !estado.editable;
        // O-13: con partidas la moneda sí cambia; al guardar se confirma la conversión de precios
        $('c-moneda').title = estado.partidas.length ? 'Al cambiarla, los precios de las partidas se convierten al tipo de cambio' : '';
        if (!estado.permisos.ve_margen) {
            $('c-notas-internas').closest('div').hidden = !estado.permisos.gestionar;
        }
    }

    // ---------------------------------------------------------- cabecera, acciones y avisos
    function boton(id, icono, texto, clase) {
        return '<button type="button" class="' + (clase || 'btn-secundario') + '" data-accion="' + id + '"><i class="fas ' + icono + '"></i> ' + texto + '</button>';
    }

    function pintarBarra() {
        var c = estado.cot;
        $('cot-titulo').textContent = c ? c.folio : 'Nueva cotización';
        document.title = (c ? c.folio : 'Nueva cotización') + ' · Cotización';
        $('cot-estatus').innerHTML = (c ? C.etiqueta(c) : '<span class="etiqueta etiqueta-neutral">Borrador sin guardar</span>') +
            (c && c.aviso_credito ? ' <span class="etiqueta etiqueta-alerta" title="' + esc(c.aviso_credito) + '"><i class="fas fa-exclamation-triangle"></i> Rebasa el límite de crédito</span>' : '');  // D-48
        var p = estado.permisos;
        var b = [];
        if (c) {
            b.push('<button type="button" class="btn-secundario" id="cot-vista-previa"><i class="fas fa-eye"></i> Vista previa</button>');
            b.push('<a class="btn-secundario" href="' + C.urlPdf(c.id) + '"><i class="fas fa-file-pdf"></i> Descargar PDF</a>');
            if (!c.reemplazada_por && p.gestionar) {
                if (c.estatus === 'borrador') b.push(boton('enviar', 'fa-paper-plane', 'Marcar como enviada', 'btn-primary'));
                if (c.estatus === 'enviada') {
                    b.push(boton('aprobar', 'fa-check', 'Marcar como aprobada', 'btn-primary'));
                    b.push(boton('rechazar', 'fa-times', 'Marcar como rechazada'));
                }
                // L-19: el cliente pudo aprobarla dentro de la vigencia y la oficina capturarlo después
                if (c.estatus === 'vencida') b.push(boton('aprobar', 'fa-check', 'Marcar como aprobada'));
                if (['enviada', 'vencida', 'rechazada'].indexOf(c.estatus) !== -1) b.push(boton('version', 'fa-code-branch', 'Nueva versión'));
                // D-54: compartir con el cliente por WhatsApp (enlace de solo lectura que caduca)
                if (c.estatus !== 'borrador') b.push(boton('compartir', 'fa-share-alt', 'Compartir por WhatsApp'));
                b.push(boton('duplicar', 'fa-copy', 'Duplicar'));
            }
            if (c.permisos && c.permisos.eliminar) b.push(boton('eliminar', 'fa-trash', 'Eliminar', 'btn-rojo'));
        }
        $('cot-acciones').innerHTML = b.join('');

        var avisos = [];
        if (c && c.reemplazada_por) {
            var nueva = (c.serie || []).find(function (s) { return String(s.id) === String(c.reemplazada_por); });
            avisos.push('<i class="fas fa-code-branch"></i> Esta versión fue sustituida por <a href="cotizacion.html?id=' + c.reemplazada_por + '">' + esc(nueva ? nueva.folio : 'una más reciente') + '</a>. Queda solo de consulta.');
        }
        if (c && c.incidencia) {
            // O-14: el origen es una etiqueta del sistema (las notas internas son del usuario)
            var instaladas = (c.partidas || estado.partidas || []).some(function (p) { return Number(p.cargada_incidencia) === 1; });
            avisos.push('<i class="fas fa-tools"></i> Desde la orden de servicio <a href="detalle.html?id=' + c.incidencia.id + '">' + esc(c.incidencia.numero_incidente) + '</a> (' + esc(c.incidencia.estatus) + ')' +
                (instaladas ? ' · las refacciones ya se instalaron: al aprobarla no se vuelven a descontar.' : '.'));
        }
        if (c && c.estatus === 'rechazada' && c.motivo_rechazo) avisos.push('<i class="fas fa-comment-slash"></i> Motivo del rechazo: ' + esc(c.motivo_rechazo));
        if (c && (c.estatus === 'aprobada' || c.estatus === 'convertida') && c.aprobada_por_nombre) {
            avisos.push('<i class="fas fa-user-check"></i> Aprobada por ' + esc(c.aprobada_por_nombre) + ' el ' + C.fecha(c.fecha_aprobacion) + (Number(c.aprobacion_parcial) === 1 ? ' (aprobación parcial).' : '.'));
        }
        if (c && c.estatus === 'vencida') avisos.push('<i class="fas fa-hourglass-end"></i> La vigencia terminó. Para reactivarla crea una nueva versión con nueva vigencia.');
        if (c && c.estatus === 'enviada') avisos.push('<i class="fas fa-lock"></i> Una cotización enviada ya no se edita: para cambiarla crea una nueva versión.');
        $('cot-avisos').innerHTML = avisos.map(function (a) { return '<div class="cot-aviso-linea">' + a + '</div>'; }).join('');
    }

    function pintarRelacionados() {
        var c = estado.cot;
        if (!c) { $('cot-relacionados').innerHTML = ''; return; }
        var html = '';
        if (c.ventas && c.ventas.length) {
            html += '<div class="cot-bloque"><h3><i class="fas fa-cart-arrow-down"></i> Ventas de esta cotización</h3><ul class="cot-ventas">' +
                c.ventas.map(function (v) {
                    var accion = v.estado === 'por_registrar'
                        ? '<a class="btn-primary" href="ventas.html?cotizacion_venta=' + v.id + '"><i class="fas fa-file-signature"></i> Registrar venta</a>' +
                          (estado.permisos.gestionar ? ' <button type="button" class="btn-secundario" data-descartar="' + v.id + '">Descartar</button>' : '')
                        : v.estado === 'registrada'
                            ? '<a href="detalles-venta.html?id=' + v.venta_id + '"><span class="etiqueta etiqueta-ok">Registrada · ' + esc(v.venta_folio || '') + '</span></a>'
                            : '<span class="etiqueta etiqueta-neutral">Descartada</span>';
                    return '<li><span>' + C.cantidad(v.cantidad) + ' × <strong>' + esc(v.descripcion) + '</strong>' + (v.modelo ? ' · ' + esc(v.modelo) : '') + '</span><span>' + accion + '</span></li>';
                }).join('') + '</ul><p class="cot-nota">Cada modelo de equipo aceptado se registra como una venta (los iguales juntos). Al registrar la venta capturas las series, la garantía y la póliza; la venta es la que descuenta el inventario.</p></div>';
        }
        var pendientesInc = c.incidencia && (c.estatus === 'aprobada' || c.estatus === 'convertida') && (c.partidas || []).some(function (p) {
            return p.tipo === 'refaccion' && String(p.aceptada) === '1' && String(p.cargada_incidencia) === '0';
        });
        if (pendientesInc && estado.permisos.gestionar) {
            html += '<div class="cot-bloque"><h3><i class="fas fa-tools"></i> Refacciones para la orden de servicio</h3><p class="cot-nota">Registra en la orden de servicio ' + esc(c.incidencia.numero_incidente) +
                ' las refacciones aceptadas cuando se usen. Esto sí descuenta el inventario.</p>' + boton('cargar', 'fa-dolly', 'Registrar refacciones en la orden de servicio') + '</div>';
        }
        if ((c.estatus === 'aprobada' || c.estatus === 'convertida') && estado.finanzas) {
            html += '<div class="cot-bloque"><h3><i class="fas fa-wallet"></i> Cobranza</h3><p class="cot-nota">Registra lo que el cliente debe por esta cotización (completa o por partes, por ejemplo un anticipo) y sigue sus cobros en Finanzas.</p>' +
                '<a class="btn-secundario" href="finanzas.html?nueva=cotizacion&id=' + encodeURIComponent(c.id) + '"><i class="fas fa-file-invoice"></i> Crear cuenta por cobrar</a></div>';
        }
        if (c.serie && c.serie.length > 1) {
            html += '<div class="cot-bloque"><h3><i class="fas fa-code-branch"></i> Versiones</h3><ul class="cot-ventas">' + c.serie.map(function (s) {
                return '<li><span>' + (String(s.id) === String(c.id) ? '<strong>' + esc(s.folio) + '</strong> (esta)' : '<a href="cotizacion.html?id=' + s.id + '">' + esc(s.folio) + '</a>') +
                    ' · ' + C.fecha(s.creado_en) + '</span><span>' + C.dinero(s.total, c.moneda) + ' ' + C.etiqueta(s) + '</span></li>';
            }).join('') + '</ul></div>';
        }
        $('cot-relacionados').innerHTML = html;
    }

    // ---------------------------------------------------------- borrador local
    function claveBorrador() { return 'app_borrador_cotizacion_' + (estado.id || 'nueva'); }

    var temporizador = null;
    function marcarCambio() {
        estado.cambios = true;
        $('aviso-sin-guardar').hidden = false;
        clearTimeout(temporizador);
        temporizador = setTimeout(function () {
            try {
                localStorage.setItem(claveBorrador(), JSON.stringify({ fecha: Date.now(), version: estado.cot ? estado.cot.version_huella : '', datos: leerDatos(), cliente: $('c-cliente').value, partidas: estado.partidas }));
            } catch (e) { /* sin almacenamiento */ }
        }, 400);
    }

    function borrarBorrador() {
        try { localStorage.removeItem(claveBorrador()); } catch (e) { /* nada */ }
    }

    function ofrecerBorrador() {
        var b = null;
        try { b = JSON.parse(localStorage.getItem(claveBorrador()) || 'null'); } catch (e) { b = null; }
        if (!b || !estado.editable || Date.now() - b.fecha > 30 * 86400000) return;
        AppDialogo.mostrar({
            tipo: 'aviso', titulo: 'Tienes cambios sin guardar',
            mensaje: 'Hay cambios de esta cotización guardados en este navegador el ' + new Date(b.fecha).toLocaleString('es-MX') + '. ¿Quieres recuperarlos?',
            botones: [{ texto: 'Descartar', valor: 'no', estilo: 'secundario' }, { texto: 'Recuperar cambios', valor: 'si', estilo: 'primario' }]
        }).then(function (v) {
            if (v !== 'si') { borrarBorrador(); return; }
            var d = b.datos;
            $('c-cliente').value = b.cliente;
            ['sucursal', 'fecha', 'tipo_cambio', 'condiciones', 'notas_cliente', 'notas_internas'].forEach(function (k) {
                var id = { sucursal: 'c-sucursal', fecha: 'c-fecha', tipo_cambio: 'c-tc', condiciones: 'c-condiciones', notas_cliente: 'c-notas-cliente', notas_internas: 'c-notas-internas' }[k];
                $(id).value = d[k] || '';
            });
            $('c-vigencia').value = d.vigencia_hasta;
            $('c-moneda').value = d.moneda;
            $('c-incluye-iva').checked = Number(d.precios_incluyen_iva) === 1;
            $('campo-tc').hidden = d.moneda !== 'USD';
            estado.partidas = b.partidas || [];
            var cliente = clientePorNombre(b.cliente);
            cargarContactos(cliente && cliente.id, d.contacto_id);
            pintarPartidas();
            aplicarEditable();
            estado.cambios = true;
            $('aviso-sin-guardar').hidden = false;
        });
    }

    // ---------------------------------------------------------- guardar
    function guardar(forzar) {
        if (estado.guardando) return Promise.resolve(false);
        var form = $('form-cot');
        if (!clientePorNombre($('c-cliente').value)) {
            $('c-cliente').setCustomValidity('Selecciona un cliente de la lista.');
        } else {
            $('c-cliente').setCustomValidity('');
        }
        if (!form.reportValidity()) return Promise.resolve(false);
        estado.guardando = true;
        $('btn-guardar').disabled = true;
        return C.api('guardar', null, {
            id: estado.id || 0, version: estado.cot ? estado.cot.version_huella : '', forzar: !!forzar,
            datos: leerDatos(), partidas: leerPartidas()
        }).then(function (j) {
            estado.convertirMoneda = false;
            borrarBorrador();
            var nuevo = !estado.id;
            if (nuevo) try { localStorage.removeItem('app_borrador_cotizacion_nueva'); } catch (e) { /* nada */ }
            cargarDesde(j.cotizacion);
            if (nuevo) history.replaceState(null, '', 'cotizacion.html?id=' + j.cotizacion.id);
            toast('Cotización ' + j.cotizacion.folio + ' guardada.');
            return true;
        }).catch(function (e) {
            if (e.status === 409) {
                return AppDialogo.mostrar({
                    tipo: 'aviso', titulo: 'Alguien más modificó esta cotización',
                    mensaje: 'La cotización cambió en otro lugar después de que la abriste' + (e.datos.modificado_por ? ' (' + e.datos.modificado_por + ')' : '') + '.',
                    nota: 'Puedes ver la versión actual (tus cambios quedan respaldados en este navegador) o guardar encima.',
                    botones: [{ texto: 'Ver la versión actual', valor: 'recargar', estilo: 'secundario' }, { texto: 'Guardar de todos modos', valor: 'forzar', estilo: 'peligro' }]
                }).then(function (v) {
                    estado.guardando = false;
                    if (v === 'forzar') return guardar(true);
                    if (v === 'recargar') location.reload();
                    return false;
                });
            }
            if (e.datos && e.datos.motivo === 'convertir_moneda') {  // O-13
                return confirmar('Convertir la moneda', e.message + ' Los precios de todas las partidas se recalculan; queda en el historial.', 'Convertir y guardar').then(function (si) {
                    estado.guardando = false;
                    if (!si) return false;
                    estado.convertirMoneda = true;
                    return guardar(forzar);
                });
            }
            error(e, e.datos && e.datos.motivo === 'descuento' ? 'Descuento no autorizado' : 'No se guardó la cotización');
            return false;
        }).then(function (r) {
            estado.guardando = false;
            $('btn-guardar').disabled = false;
            return r;
        });
    }

    function cargarDesde(cot) {
        estado.cot = cot;
        estado.id = cot.id;
        estado.permisos = Object.assign({}, estado.permisos, cot.permisos || {});
        estado.editable = !!(cot.permisos && cot.permisos.editar);
        estado.partidas = cot.partidas.map(function (p) {
            return Object.assign({}, p, { es_exento: Number(p.es_exento) === 1, tasa_iva: num(p.tasa_iva) });
        });
        estado.cambios = false;
        $('aviso-sin-guardar').hidden = true;
        $('cot-volver').href = cot.incidencia && !estado.permisos.gestionar ? 'detalle.html?id=' + cot.incidencia.id : 'cotizaciones.html';
        return llenarFormulario(cot).then(function () {
            pintarBarra();
            pintarPartidas();
            aplicarEditable();
            pintarRelacionados();
        });
    }

    // ---------------------------------------------------------- acciones
    function confirmar(titulo, mensaje, textoSi, peligro) {
        return AppDialogo.mostrar({
            tipo: peligro ? 'aviso' : 'info', titulo: titulo, mensaje: mensaje,
            botones: [{ texto: 'Cancelar', valor: null, estilo: 'secundario' }, { texto: textoSi, valor: 'si', estilo: peligro ? 'peligro' : 'primario' }]
        }).then(function (v) { return v === 'si'; });
    }

    // O-15: la explicación de "enviar" se muestra hasta que el usuario marca "no volver a mostrar"
    // (se guarda en su cuenta: backend/preferencias.php)
    var prefs = null;
    function preferencias() {
        if (prefs) return Promise.resolve(prefs);
        return fetch('../backend/preferencias.php', { credentials: 'same-origin', headers: { Accept: 'application/json' } })
            .then(function (r) { return r.ok ? r.json() : {}; })
            .then(function (j) { prefs = (j && j.preferencias) || {}; return prefs; })
            .catch(function () { prefs = {}; return prefs; });
    }
    function confirmarEnvio() {
        return preferencias().then(function (p) {
            if (p.cot_sin_confirmar_envio) return true;
            return AppDialogo.mostrar({
                tipo: 'info', titulo: 'Marcar como enviada',
                mensaje: 'Después de enviarla ya no se podrá editar; si el cliente pide cambios, se crea una nueva versión.',
                botones: [{ texto: 'Cancelar', valor: null, estilo: 'secundario' },
                          { texto: 'Entendido, no volver a mostrar', valor: 'siempre', estilo: 'secundario' },
                          { texto: 'Marcar como enviada', valor: 'si', estilo: 'primario' }]
            }).then(function (v) {
                if (v === 'siempre') {
                    prefs.cot_sin_confirmar_envio = true;
                    fetch('../backend/preferencias.php', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ clave: 'cot_sin_confirmar_envio', valor: true }) }).catch(function () {});
                }
                return v === 'si' || v === 'siempre';
            });
        });
    }

    function cambiarEstatus(cuerpo) {
        return C.api('estatus', null, cuerpo).then(function (j) {
            cargarDesde(j.cotizacion);
            return j.avisos || {};
        });
    }

    var acciones = {
        enviar: function () {
            var previo = estado.cambios ? guardar() : Promise.resolve(true);
            return previo.then(function (ok) {
                if (!ok) return;
                return confirmarEnvio().then(function (si) {
                    if (!si) return;
                    return cambiarEstatus({ id: estado.id, estatus: 'enviada' }).then(function () {
                        toast('Cotización marcada como enviada. Descarga el PDF para compartirla.');
                    });
                });
            }).catch(error);
        },
        aprobar: function () {
            var nodo = document.createElement('div');
            nodo.className = 'cot-dialogo';
            var vencida = estado.cot && estado.cot.estatus === 'vencida';
            var vence = vencida && estado.cot.vigencia_hasta ? estado.cot.vigencia_hasta.split('-').reverse().join('/') : '';
            nodo.innerHTML = '<label for="ap-nombre">Nombre de quien aprueba</label><input id="ap-nombre" maxlength="150" placeholder="Ej. Ing. Roberto Salgado">' +
                (vencida ? '<p class="cot-nota"><label><input type="checkbox" id="ap-en-vigencia"> El cliente la aprobó antes de que venciera (' + esc(vence) + ')</label></p>' : '') +
                '<p class="cot-nota">Marca las partidas que el cliente aceptó:</p><ul class="cot-checklist">' +
                estado.partidas.map(function (p) {
                    return '<li><label><input type="checkbox" value="' + p.id + '" checked> ' + C.cantidad(p.cantidad) + ' × ' + esc(p.descripcion) +
                        ' <span class="inv-sub">' + C.dinero(p._calc.importe + p._calc.iva, monedaActual()) + ' con IVA</span></label></li>';
                }).join('') + '</ul>';
            return AppDialogo.mostrar({
                tipo: 'info', titulo: 'Marcar como aprobada', contenido: nodo, ancho: true,
                botones: [{ texto: 'Cancelar', valor: null, estilo: 'secundario' }, { texto: 'Aprobar', valor: 'si', estilo: 'primario' }]
            }).then(function (v) {
                if (v !== 'si') return;
                var aceptadas = Array.prototype.map.call(nodo.querySelectorAll('.cot-checklist input[type=checkbox]:checked'), function (c) { return Number(c.value); });
                var enVigencia = nodo.querySelector('#ap-en-vigencia');
                if (enVigencia && !enVigencia.checked) {
                    toast('Si el cliente la aprobó después del ' + vence + ', crea una nueva versión con la vigencia corregida.', 'error');
                    return;
                }
                return cambiarEstatus({ id: estado.id, estatus: 'aprobada', aprobada_por: nodo.querySelector('#ap-nombre').value, aceptadas: aceptadas,
                    aprobada_en_vigencia: enVigencia && enVigencia.checked ? 1 : 0 })
                    .then(despuesDeAprobar);
            }).catch(error);
        },
        rechazar: function () {
            var nodo = document.createElement('div');
            nodo.className = 'cot-dialogo';
            nodo.innerHTML = '<label for="re-motivo">Motivo del rechazo</label><textarea id="re-motivo" rows="3" maxlength="255" placeholder="Ej. Eligió otro proveedor por precio"></textarea>';
            return AppDialogo.mostrar({
                tipo: 'aviso', titulo: 'Marcar como rechazada', contenido: nodo,
                botones: [{ texto: 'Cancelar', valor: null, estilo: 'secundario' }, { texto: 'Rechazar', valor: 'si', estilo: 'peligro' }]
            }).then(function (v) {
                if (v !== 'si') return;
                return cambiarEstatus({ id: estado.id, estatus: 'rechazada', motivo: nodo.querySelector('#re-motivo').value }).then(function (avisos) {
                    if (!avisos.refacciones_instaladas) return;
                    return AppDialogo.mostrar({
                        tipo: 'aviso', titulo: 'Las refacciones ya estaban instaladas',
                        mensaje: (avisos.refacciones_instaladas === 1 ? 'Una refacción de esta cotización ya se registró' : avisos.refacciones_instaladas + ' refacciones de esta cotización ya se registraron') +
                            ' en la orden de servicio ' + estado.cot.incidencia.numero_incidente + ' y siguen descontadas del inventario.',
                        nota: 'Si se retiran del sitio, devuélvelas al almacén desde la orden de servicio. Si se quedan, crea una nueva versión con el precio que el cliente acepte.'
                    });
                });
            }).catch(error);
        },
        version: function () {
            return confirmar('Nueva versión', 'Se crea ' + estado.cot.folio_base + '-v' + (Math.max.apply(null, (estado.cot.serie || [{ version: 1 }]).map(function (s) { return Number(s.version); })) + 1) +
                ' como borrador con las mismas partidas y precios. Esta versión queda solo de consulta.', 'Crear nueva versión').then(function (si) {
                if (!si) return;
                return C.api('nueva_version', null, { id: estado.id }).then(function (j) { location.href = 'cotizacion.html?id=' + j.cotizacion.id; });
            }).catch(error);
        },
        compartir: function () {
            // Se abre la ventana antes de pedir el enlace (los navegadores bloquean ventanas abiertas después)
            var ventana = window.open('', '_blank');
            return C.api('enlace', { id: estado.id }).then(function (j) {
                if (ventana) { ventana.opener = null; ventana.location.href = j.whatsapp; }
                else window.location.href = j.whatsapp;
                toast('Se abrió WhatsApp con el mensaje y el enlace (vence en 7 días). Revísalo antes de enviarlo.');
            }).catch(function (e) { if (ventana) ventana.close(); return error(e); });
        },
        duplicar: function () {
            return confirmar('Duplicar cotización', 'Se crea una cotización nueva (otro folio) en borrador, con las mismas partidas y precios.', 'Duplicar').then(function (si) {
                if (!si) return;
                return C.api('duplicar', null, { id: estado.id }).then(function (j) { location.href = 'cotizacion.html?id=' + j.cotizacion.id; });
            }).catch(error);
        },
        eliminar: function () {
            return confirmar('Eliminar cotización', 'Se elimina el borrador ' + estado.cot.folio + '. Queda constancia en el historial.', 'Eliminar', true).then(function (si) {
                if (!si) return;
                return C.api('eliminar', null, { id: estado.id }).then(function () { borrarBorrador(); estado.cambios = false; location.href = 'cotizaciones.html'; });
            }).catch(error);
        },
        cargar: function () {
            return confirmar('Registrar refacciones en la orden de servicio', 'Las refacciones aceptadas se registran en la orden de servicio ' + estado.cot.incidencia.numero_incidente + ' y se descuentan del inventario.', 'Registrar').then(function (si) {
                if (!si) return;
                return C.api('cargar_refacciones', null, { id: estado.id }).then(function (j) {
                    toast(j.mensaje);
                    return C.api('detalle', { id: estado.id }).then(function (d) { cargarDesde(d.cotizacion); });
                });
            }).catch(error);
        }
    };

    function despuesDeAprobar(avisos) {
        var cadena = Promise.resolve();
        if (avisos.incidencia_pendiente) {
            cadena = cadena.then(function () {
                return confirmar('Reactivar la orden de servicio', 'La orden de servicio ' + estado.cot.incidencia.numero_incidente + ' está en Pendiente. ¿La sacamos de Pendiente para continuar el servicio? (Pasa a Asignado, o a Abierto si no tiene técnico.)', 'Continuar el servicio').then(function (si) {
                    if (si) return C.api('reactivar_incidencia', null, { id: estado.id }).then(function (j) { toast(j.mensaje); });
                });
            });
        }
        if (avisos.refacciones_ya_aplicadas && avisos.refacciones_ya_aplicadas.length) {
            // D-47: la orden ya tiene aplicadas estas refacciones; registrarlas otra vez las descontaría dos veces
            cadena = cadena.then(function () {
                return AppDialogo.mostrar({
                    tipo: 'aviso', titulo: 'Refacciones ya aplicadas en la orden',
                    mensaje: 'La orden de servicio ya tiene registradas: ' + avisos.refacciones_ya_aplicadas.join(', ') + '.',
                    nota: 'Las que la orden ya cubre quedaron marcadas como registradas: «Registrar refacciones en la orden de servicio» no las vuelve a descontar.'
                });
            });
        }
        if (avisos.ventas_por_registrar) {
            cadena = cadena.then(function () {
                return AppDialogo.mostrar({
                    tipo: 'ok', titulo: 'Cotización aprobada',
                    mensaje: 'Se prepararon ' + avisos.ventas_por_registrar + (avisos.ventas_por_registrar === 1 ? ' venta por registrar' : ' ventas por registrar') + ' (una por modelo de equipo).',
                    nota: 'Regístralas desde "Ventas de esta cotización" cuando tengas las series; la venta es la que descuenta el inventario.'
                });
            });
        }
        return cadena.then(function () {
            return C.api('detalle', { id: estado.id }).then(function (d) { cargarDesde(d.cotizacion); });
        }).catch(error);
    }

    // ---------------------------------------------------------- catálogo
    var esperaCatalogo = null;
    function abrirCatalogo(tipo) {
        $('cat-tipo').value = tipo || '';
        $('cat-buscar').value = '';
        $('modal-catalogo').style.display = 'block';
        $('cat-buscar').focus();
        buscarCatalogo();
    }

    // Tasa MXN<->USD para convertir el precio/costo de un artículo a la moneda de
    // la cotización. En cotización USD es el tc de la cotización; en MXN es el tipo
    // de cambio de la empresa (Mi empresa -> Personalización).
    function tasaConversion() {
        return monedaActual() === 'USD'
            ? num($('c-tc').value)
            : (estado.config ? Number(estado.config.tipo_cambio) || 0 : 0);
    }
    // Igual que cotConvertirPrecio del backend (MXN como moneda intermedia).
    function convertirPrecio(precio, origen, destino, rate) {
        if (origen === destino || !(rate > 0)) return r2(precio);
        var enMXN = origen === 'MXN' ? precio : precio * rate;
        return destino === 'MXN' ? r2(enMXN) : r2(enMXN / rate);
    }

    function buscarCatalogo() {
        var rate = tasaConversion();
        C.api('catalogo', { buscar: $('cat-buscar').value.trim(), tipo: $('cat-tipo').value }).then(function (j) {
            var ul = $('cat-resultados');
            if (!j.articulos.length) { ul.innerHTML = '<li class="inv-vacio">Sin resultados.</li>'; return; }
            ul.innerHTML = j.articulos.map(function (a) {
                var fisico = Number(a.controla_existencia) === 1;
                var ex = fisico ? (num(a.existencia) <= 0 ? '<span class="etiqueta etiqueta-error">Sin existencia</span>'
                    : num(a.existencia) <= num(a.stock_minimo) ? '<span class="etiqueta etiqueta-alerta">Bajo mínimo · ' + C.cantidad(a.existencia) + ' ' + esc(a.unidad) + '</span>'
                    : '<span class="etiqueta etiqueta-ok">' + C.cantidad(a.existencia) + ' ' + esc(a.unidad) + '</span>')
                    : '<span class="etiqueta etiqueta-servicio">' + esc(a.categoria) + '</span>';
                var precio = convertirPrecio(num(a.precio_venta), a.moneda || 'MXN', monedaActual(), rate);
                return '<li><div><strong>' + esc(a.nombre) + '</strong><span class="inv-sub">' + esc([a.sku, a.marca, a.modelo].filter(Boolean).join(' · ')) + '</span>' + ex + '</div>' +
                    '<div class="cot-cat-precio">' + C.dinero(precio, monedaActual()) + '<button type="button" class="btn-primary" data-agregar="' + a.id + '"><i class="fas fa-plus"></i> Agregar</button></div></li>';
            }).join('');
            ul._articulos = j.articulos;
        }).catch(function (e) { $('cat-resultados').innerHTML = '<li class="inv-vacio">' + esc(e.message) + '</li>'; });
    }

    function agregarArticulo(a) {
        var rate = tasaConversion();
        var monedaArt = a.moneda || 'MXN';
        if (monedaArt !== monedaActual() && !(rate > 0)) {
            AppDialogo.mostrar({ tipo: 'aviso', titulo: 'Falta el tipo de cambio',
                mensaje: monedaActual() === 'USD'
                    ? 'Captura el tipo de cambio de la cotización antes de agregar partidas en dólares.'
                    : 'Este artículo está en dólares. Configura el tipo de cambio de tu empresa en Mi empresa → Personalización para cargarlo en una cotización en pesos.' });
            return;
        }
        var existente = estado.partidas.find(function (p) { return !p.id && String(p.articulo_id) === String(a.id); });
        if (existente) {
            existente.cantidad = num(existente.cantidad) + 1;
        } else {
            estado.partidas.push({
                articulo_id: a.id, sku: a.sku, descripcion: a.nombre, marca: a.marca, modelo: a.modelo, unidad: a.unidad,
                cantidad: 1, precio_unitario: convertirPrecio(num(a.precio_venta), monedaArt, monedaActual(), rate), descuento_pct: 0,
                tasa_iva: estado.config ? estado.config.iva : 0.16, es_exento: false,
                costo_unitario: a.costo_unitario !== undefined ? convertirPrecio(num(a.costo_unitario), monedaArt, monedaActual(), rate) : 0,
                controla_existencia: a.controla_existencia, existencia: a.existencia, stock_minimo: a.stock_minimo
            });
        }
        pintarPartidas();
        aplicarEditable();
        marcarCambio();
        toast((existente ? 'Se sumó 1 a ' : 'Agregado: ') + a.nombre);
    }

    // ---------------------------------------------------------- eventos
    function enlazar() {
        $('form-cot').addEventListener('submit', function (e) { e.preventDefault(); guardar(); });
        $('btn-guardar').addEventListener('click', function () { guardar(); });

        $('form-cot').addEventListener('input', function (e) {
            var t = e.target;
            if (t.dataset.campo) {
                var p = estado.partidas[Number(t.dataset.i)];
                if (t.dataset.campo === 'iva') { p.es_exento = t.value === 'exento'; p.tasa_iva = p.es_exento ? 0 : num(t.value); }
                else p[t.dataset.campo] = t.dataset.campo === 'descripcion' ? t.value : t.value;
                // Repintar solo totales e importes (sin perder el foco del campo)
                totales();
                var fila = t.closest('tr');
                var celdaImporte = fila.querySelector('td.inv-num strong');
                if (celdaImporte) celdaImporte.textContent = C.dinero(p._calc.importe, monedaActual());
                pintarTotales();
            }
            marcarCambio();
        });
        $('form-cot').addEventListener('change', function (e) {
            if (e.target.dataset.campo) { pintarPartidas(); aplicarEditable(); }
        });
        $('c-cliente').addEventListener('change', function () {
            var c = clientePorNombre(this.value);
            this.setCustomValidity(c || !this.value ? '' : 'Selecciona un cliente de la lista.');
            cargarContactos(c && c.id, '');
        });
        // Alta rápida del cliente sin salir de la cotización (queda seleccionado)
        if (window.AppAltaRapida) AppAltaRapida.cliente($('c-cliente'), {
            existe: function (n) { return !!clientePorNombre(n); },
            alCrear: function (c) {
                estado.clientes.push({ id: c.id, nombre: c.nombre });
                $('lista-clientes').insertAdjacentHTML('beforeend', '<option value="' + esc(c.nombre) + '">');
            }
        });
        $('c-moneda').addEventListener('change', function () {
            var nueva = this.value, previa = estado.monedaPrevia || 'MXN';
            var esUSD = nueva === 'USD';
            // Prefill con el tipo de cambio de la empresa (Mi empresa) si está configurado y el campo está vacío
            if (esUSD && !num($('c-tc').value) && estado.config && Number(estado.config.tipo_cambio) > 0) {
                $('c-tc').value = estado.config.tipo_cambio;
            }
            // Conversión EN VIVO de las partidas (precio y costo) al cambiar la moneda
            if (previa !== nueva && estado.partidas.length) {
                var tc = num($('c-tc').value) || (estado.config ? Number(estado.config.tipo_cambio) : 0);
                var tcAntes = previa === 'MXN' ? 1 : tc;
                var tcNuevo = nueva === 'MXN' ? 1 : tc;
                if (!(tcAntes > 0) || !(tcNuevo > 0)) {
                    AppDialogo.mostrar({ tipo: 'aviso', titulo: 'Falta el tipo de cambio',
                        mensaje: 'Configura el tipo de cambio en Mi empresa (o captúralo) para convertir las partidas a ' + nueva + '.' });
                    this.value = previa;  // se revierte el selector
                    return;
                }
                var factor = tcAntes / tcNuevo;
                estado.partidas.forEach(function (p) {
                    p.precio_unitario = r2(num(p.precio_unitario) * factor);
                    if (p.costo_unitario != null) p.costo_unitario = r2(num(p.costo_unitario) * factor);
                });
                estado.monedaConvertidaEnVivo = true;
                marcarCambio();
            }
            estado.monedaPrevia = nueva;
            $('campo-tc').hidden = !esUSD;
            pintarPartidas(); aplicarEditable();
        });
        $('c-incluye-iva').addEventListener('change', pintarTotales);
        $('c-tc').addEventListener('input', pintarTotales);

        $('tabla-partidas').addEventListener('click', function (e) {
            var mover = e.target.closest('[data-mover]');
            var quitar = e.target.closest('[data-quitar]');
            if (mover) {
                var i = Number(mover.dataset.i), j = i + Number(mover.dataset.mover);
                var tmp = estado.partidas[i]; estado.partidas[i] = estado.partidas[j]; estado.partidas[j] = tmp;
            } else if (quitar) {
                estado.partidas.splice(Number(quitar.dataset.quitar), 1);
            } else return;
            pintarPartidas();
            aplicarEditable();
            marcarCambio();
        });

        document.querySelectorAll('[data-catalogo]').forEach(function (b) {
            b.addEventListener('click', function () { abrirCatalogo(b.dataset.catalogo); });
        });
        $('cat-buscar').addEventListener('input', function () { clearTimeout(esperaCatalogo); esperaCatalogo = setTimeout(buscarCatalogo, 250); });
        $('cat-tipo').addEventListener('change', buscarCatalogo);
        $('cat-resultados').addEventListener('click', function (e) {
            var b = e.target.closest('[data-agregar]');
            if (!b) return;
            var a = (this._articulos || []).find(function (x) { return String(x.id) === b.dataset.agregar; });
            if (a) agregarArticulo(a);
        });
        document.addEventListener('click', function (e) {
            if (e.target.matches('[data-cerrar]')) $('modal-catalogo').style.display = 'none';
        });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') $('modal-catalogo').style.display = 'none';
        });

        $('cot-acciones').addEventListener('click', function (e) {
            // D-47: vista previa del PDF dentro del sistema (lo guardado; los cambios sin guardar no salen)
            if (e.target.closest('#cot-vista-previa') && estado.cot) {
                if (estado.cambios) toast('La vista previa muestra lo último guardado. Guarda para ver tus cambios.');
                window.AppVisor.abrir([{ url: C.urlPdf(estado.cot.id) + '&ver=1&t=' + Date.now(), nombre: estado.cot.folio + '.pdf', tipo: 'pdf' }], 0);
                return;
            }
            var b = e.target.closest('[data-accion]');
            if (b && acciones[b.dataset.accion]) acciones[b.dataset.accion]();
        });
        $('cot-relacionados').addEventListener('click', function (e) {
            var b = e.target.closest('[data-accion]');
            if (b && acciones[b.dataset.accion]) return acciones[b.dataset.accion]();
            var d = e.target.closest('[data-descartar]');
            if (d) confirmar('Descartar venta', 'Esta venta por registrar ya no se registrará (por ejemplo, si el cliente canceló ese equipo).', 'Descartar', true).then(function (si) {
                if (!si) return;
                return C.api('descartar_venta', null, { id: Number(d.dataset.descartar) }).then(function () {
                    return C.api('detalle', { id: estado.id }).then(function (j) { cargarDesde(j.cotizacion); });
                });
            }).catch(error);
        });

        window.addEventListener('beforeunload', function (e) {
            if (estado.cambios && estado.editable) { e.preventDefault(); e.returnValue = ''; }
        });
    }

    // ---------------------------------------------------------- inicio
    document.addEventListener('DOMContentLoaded', function () {
        enlazar();
        var params = new URLSearchParams(location.search);
        estado.id = Number(params.get('id')) || null;

        Promise.all([C.api('config'), C.api('clientes').catch(function () { return { clientes: [] }; })]).then(function (r) {
            estado.config = r[0].config;
            estado.permisos = r[0].permisos;
            estado.hoy = r[0].hoy;
            estado.clientes = r[1].clientes;
            $('lista-clientes').innerHTML = estado.clientes.map(function (c) { return '<option value="' + esc(c.nombre) + '">'; }).join('');
            if (estado.id) {
                return C.api('detalle', { id: estado.id }).then(function (j) { return cargarDesde(j.cotizacion); });
            }
            // Nueva: solo con acceso al módulo (el técnico cotiza desde su incidencia)
            if (!estado.permisos.gestionar) {
                location.replace('../index.html');
                return;
            }
            estado.editable = true;
            return llenarFormulario(null).then(function () {
                var clienteId = params.get('cliente_id');
                var cliente = clienteId && estado.clientes.find(function (c) { return String(c.id) === clienteId; });
                if (cliente) { $('c-cliente').value = cliente.nombre; cargarContactos(cliente.id, ''); }
                pintarBarra();
                pintarPartidas();
                aplicarEditable();
            });
        }).then(ofrecerBorrador).then(function () {
            if (window.appOnAuthReady) window.appOnAuthReady(function (a) {
                estado.finanzas = (a.modulos || []).indexOf('finanzas') !== -1;
                if (estado.finanzas && estado.cot) pintarRelacionados();
            });
        }).catch(function (e) {
            $('form-cot').hidden = true;
            $('cot-avisos').innerHTML = '<div class="cot-aviso-linea"><i class="fas fa-exclamation-circle"></i> ' + esc(e.message) + '</div>';
        });
    });
})();
