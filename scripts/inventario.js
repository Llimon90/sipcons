// public/inventario.html - catálogo, existencias, movimientos manuales y kárdex.
(function () {
    var API = '../backend/inventario.php';
    var CATEGORIAS = ['Equipo', 'Refacción', 'Consumible', 'Herramienta', 'Servicio', 'Mano de obra'];
    // Sin existencias: se cotizan, pero no entran al kárdex ni a los mínimos
    var SIN_EXISTENCIA = ['Servicio', 'Mano de obra'];
    var articulos = [];
    var grafico = null;

    function $(id) { return document.getElementById(id); }
    function esc(v) {
        return String(v == null ? '' : v)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }
    function num(n) {
        n = parseFloat(n) || 0;
        return n % 1 === 0 ? n.toLocaleString('es-MX') : n.toLocaleString('es-MX', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }
    function dinero(n) {
        return (parseFloat(n) || 0).toLocaleString('es-MX', { style: 'currency', currency: 'MXN' });
    }
    // Aviso estándar del sistema (AppAviso, scripts/dialogo.js)
    function avisar(texto, tipo) {
        AppAviso.mostrar(texto, tipo === 'success' || !tipo ? 'ok' : tipo);
    }
    function api(accion, params, cuerpo) {
        var url = API + '?accion=' + accion + (params ? '&' + new URLSearchParams(params).toString() : '');
        var opciones = { credentials: 'same-origin', cache: 'no-store' };
        if (cuerpo) {
            opciones.method = 'POST';
            opciones.headers = { 'Content-Type': 'application/json' };
            opciones.body = JSON.stringify(cuerpo);
        }
        return fetch(url, opciones).then(function (r) {
            return r.json().catch(function () { return {}; }).then(function (j) {
                if (!r.ok || !j.exito) throw new Error(j.mensaje || j.error || 'Error ' + r.status);
                return j;
            });
        });
    }

    // ---------- Modales ----------
    function abrir(id) { $(id).style.display = 'block'; }
    function cerrar(modal) { modal.style.display = 'none'; }
    document.addEventListener('click', function (e) {
        // Solo el botón Cerrar o Esc: un clic fuera no descarta lo capturado
        if (e.target.matches('[data-cerrar]')) cerrar(e.target.closest('.modal'));
    });
    document.addEventListener('keydown', function (e) {
        if (e.key !== 'Escape') return;
        document.querySelectorAll('.inv-modal').forEach(function (m) { cerrar(m); });
    });

    // ---------- Resumen ----------
    function colorMarca() {
        return (window.APP_BRAND && APP_BRAND.colores.primario) || '#3498db';
    }

    function cargarResumen() {
        return api('resumen').then(function (j) {
            var k = j.kpi;
            $('kpi-articulos').textContent = num(k.articulos);
            $('kpi-valor').textContent = dinero(k.valor);
            $('kpi-bajo').textContent = num(k.bajo_minimo || 0);
            $('kpi-movs').textContent = num(k.movimientos_30d);

            $('lista-top').innerHTML = j.mas_usados.length
                ? j.mas_usados.map(function (a) {
                    return '<li><span>' + esc(a.nombre) + ' <span class="inv-sub">' + esc(a.sku) + '</span></span><span>' +
                        num(a.unidades) + ' ' + esc(a.unidad) + '</span></li>';
                }).join('')
                : '<li><span>Aún no hay consumos registrados.</span></li>';

            dibujarConsumo(j.consumo_mensual);
        }).catch(function (e) { avisar(e.message, 'error'); });
    }

    function dibujarConsumo(filas) {
        if (typeof Chart === 'undefined') return;
        var meses = [];
        var hoy = new Date();
        for (var i = 5; i >= 0; i--) {
            var d = new Date(hoy.getFullYear(), hoy.getMonth() - i, 1);
            meses.push(d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0'));
        }
        // Las ventas de equipo tienen su propio módulo: aquí solo el consumo operativo
        var series = { incidencia: 'En servicios', manual: 'Salidas internas' };
        var colores = { incidencia: colorMarca(), manual: '#94a3b8' };
        var datasets = Object.keys(series).map(function (origen) {
            return {
                label: series[origen],
                backgroundColor: colores[origen],
                data: meses.map(function (m) {
                    var f = filas.find(function (x) { return x.mes === m && x.origen === origen; });
                    return f ? Math.round(parseFloat(f.valor)) : 0;
                })
            };
        });
        var etiquetas = meses.map(function (m) {
            var p = m.split('-');
            return new Date(p[0], p[1] - 1, 1).toLocaleDateString('es-MX', { month: 'short', year: '2-digit' });
        });
        var oscuro = document.documentElement.getAttribute('data-theme') === 'dark';
        Chart.defaults.color = oscuro ? '#9fb0c1' : '#666';
        Chart.defaults.borderColor = oscuro ? 'rgba(255,255,255,0.09)' : 'rgba(0,0,0,0.1)';
        if (grafico) grafico.destroy();
        grafico = new Chart($('grafico-consumo'), {
            type: 'bar',
            data: { labels: etiquetas, datasets: datasets },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    x: { stacked: true },
                    y: { stacked: true, beginAtZero: true, ticks: { callback: function (v) { return '$' + v.toLocaleString('es-MX'); } } }
                },
                plugins: {
                    tooltip: { callbacks: { label: function (c) { return c.dataset.label + ': ' + dinero(c.parsed.y); } } }
                }
            }
        });
    }

    // ---------- Listado ----------
    function cargarArticulos() {
        var params = {
            buscar: $('f-buscar').value.trim(),
            categoria: $('f-categoria').value,
            tipo: $('f-tipo').value,
            bajo_minimo: $('f-bajo').checked ? 1 : '',
            inactivos: $('f-inactivos').checked ? 1 : ''
        };
        return api('listar', params).then(function (j) {
            articulos = j.articulos;
            pintarTabla();
        }).catch(function (e) {
            $('tabla-articulos').innerHTML = '<tr><td colspan="9" class="inv-vacio">' + esc(e.message) + '</td></tr>';
        });
    }

    function esFisico(a) {
        return a.controla_existencia == null ? SIN_EXISTENCIA.indexOf(a.categoria) === -1 : a.controla_existencia == 1;
    }

    function etiquetaExistencia(a) {
        if (!esFisico(a)) return '<span class="etiqueta etiqueta-servicio">Sin existencias</span>';
        var ex = parseFloat(a.existencia), min = parseFloat(a.stock_minimo);
        var clase = ex <= 0 ? 'etiqueta-error' : (ex <= min ? 'etiqueta-alerta' : 'etiqueta-ok');
        return '<span class="etiqueta ' + clase + '">' + num(ex) + ' ' + esc(a.unidad) + '</span>';
    }

    function pintarTabla() {
        var tbody = $('tabla-articulos');
        if (!articulos.length) {
            tbody.innerHTML = '<tr><td colspan="9" class="inv-vacio">No hay artículos que coincidan con los filtros.</td></tr>';
            return;
        }
        tbody.innerHTML = articulos.map(function (a) {
            var sub = [a.marca, a.modelo].filter(Boolean).join(' · ');
            var activo = a.activo == 1;
            return '<tr class="' + (activo ? '' : 'inv-inactivo') + '">' +
                '<td><strong>' + esc(a.sku) + '</strong></td>' +
                '<td><div class="inv-nombre">' + (a.imagen ? '<img class="inv-miniatura" src="' + esc(urlImagen(a.imagen)) + '" alt="" loading="lazy">' : '') +
                    '<div>' + esc(a.nombre) + (sub ? '<span class="inv-sub">' + esc(sub) + '</span>' : '') + '</div></div></td>' +
                '<td>' + esc(a.categoria) + '</td>' +
                '<td>' + etiquetaExistencia(a) + '</td>' +
                '<td class="inv-num">' + (esFisico(a) ? num(a.stock_minimo) : '—') + '</td>' +
                '<td class="inv-num">' + dinero(a.costo_unitario) + '</td>' +
                '<td class="inv-num">' + dinero(a.precio_venta) + '</td>' +
                '<td>' + esc(a.ubicacion || '') + '</td>' +
                '<td><div class="inv-acciones">' +
                (activo && esFisico(a) ? '<button type="button" class="btn-edit" data-accion="mov" data-id="' + a.id + '" title="Entrada, salida o ajuste"><i class="fas fa-exchange-alt"></i></button>' : '') +
                (esFisico(a) ? '<button type="button" class="btn-edit" data-accion="kardex" data-id="' + a.id + '" title="Kárdex"><i class="fas fa-list"></i></button>' : '') +
                '<button type="button" class="btn-edit" data-accion="editar" data-id="' + a.id + '" title="Editar"><i class="fas fa-pen"></i></button>' +
                '<button type="button" class="btn-delete" data-accion="estado" data-id="' + a.id + '" title="' + (activo ? 'Desactivar' : 'Reactivar') + '">' +
                '<i class="fas ' + (activo ? 'fa-ban' : 'fa-undo') + '"></i></button>' +
                '</div></td></tr>';
        }).join('');
    }

    function recargarTodo() {
        return Promise.all([cargarArticulos(), cargarResumen()]);
    }

    // ---------- Alta / edición ----------
    function abrirArticulo(a) {
        var f = $('form-articulo');
        f.reset();
        f.elements.id.value = a ? a.id : '';
        $('titulo-articulo').textContent = a ? 'Editar ' + a.sku : 'Nuevo artículo';
        $('campo-inicial').style.display = a ? 'none' : '';
        // Una unidad capturada antes que ya no está en la lista se conserva
        if (a && a.unidad && !Array.prototype.some.call(f.elements.unidad.options, function (o) { return o.value === a.unidad; })) {
            f.elements.unidad.add(new Option(a.unidad, a.unidad));
        }
        if (a) {
            ['sku', 'nombre', 'categoria', 'marca', 'modelo', 'unidad', 'ubicacion', 'stock_minimo', 'costo_unitario', 'precio_venta', 'descripcion']
                .forEach(function (c) { if (f.elements[c]) f.elements[c].value = a[c] == null ? '' : a[c]; });
        }
        if (f.elements.controla_serie) f.elements.controla_serie.checked = !!(a && Number(a.controla_serie) === 1);  // D-45
        mostrarCamposSegunCategoria(a);
        prepararFoto(a);
        abrir('modal-articulo');
        f.elements.sku.focus();
    }

    // ---------- Foto del producto ----------
    // Se sube al guardar el artículo (necesita su id); quitar también se aplica al guardar.
    var foto = { archivo: null, quitar: false, actual: null };
    function urlImagen(ruta) { return ruta ? '../' + String(ruta).replace(/^\/+/, '') : ''; }
    function pintarFoto() {
        var previa = $('foto-previa'), src = foto.archivo ? URL.createObjectURL(foto.archivo) : (!foto.quitar && foto.actual ? urlImagen(foto.actual) : '');
        previa.innerHTML = src ? '<img src="' + esc(src) + '" alt="">' : '<i class="fas fa-image" aria-hidden="true"></i>';
        $('foto-quitar').hidden = !src;
    }
    function prepararFoto(a) {
        foto = { archivo: null, quitar: false, actual: a ? a.imagen || null : null };
        if ($('a-foto')._selector) $('a-foto')._selector.limpiar(); else $('a-foto').value = '';
        pintarFoto();
    }
    $('a-foto').addEventListener('change', function () {
        var f = this.files[0];
        if (!f) return;
        var limpiar = function (input) { if (input._selector) input._selector.limpiar(); else input.value = ''; };
        if (!/^image\/(jpeg|png)$/.test(f.type)) { avisar('La foto debe ser JPG o PNG.', 'error'); limpiar(this); return; }
        if (f.size > 3 * 1024 * 1024) { avisar('La foto pesa más de 3 MB.', 'error'); limpiar(this); return; }
        foto.archivo = f;
        foto.quitar = false;
        pintarFoto();
    });
    $('foto-quitar').addEventListener('click', function () {
        foto.archivo = null;
        foto.quitar = true;
        if ($('a-foto')._selector) $('a-foto')._selector.limpiar(); else $('a-foto').value = '';
        pintarFoto();
    });
    function guardarFoto(id) {
        if (foto.archivo) {
            var datos = new FormData();
            datos.append('imagen', foto.archivo);
            return fetch(API + '?accion=subir_imagen&id=' + encodeURIComponent(id), { method: 'POST', body: datos, credentials: 'same-origin' })
                .then(function (r) { return r.json().catch(function () { return {}; }).then(function (j) { if (!r.ok || !j.exito) throw new Error(j.mensaje || 'No se pudo subir la foto.'); }); });
        }
        if (foto.quitar && foto.actual) return api('quitar_imagen', null, { id: id });
        return Promise.resolve();
    }

    // Servicio o mano de obra: sin ubicación, mínimo ni existencia inicial
    function mostrarCamposSegunCategoria(a) {
        var f = $('form-articulo');
        var fisico = SIN_EXISTENCIA.indexOf(f.elements.categoria.value) === -1;
        f.querySelectorAll('.solo-fisico').forEach(function (el) {
            el.hidden = !fisico || (el.id === 'campo-inicial' && !!(a || f.elements.id.value));
        });
        $('nota-servicio').hidden = fisico;
        if (!fisico && f.elements.unidad.value === 'pza' && !(a && a.unidad)) f.elements.unidad.value = 'servicio';
    }

    $('form-articulo').addEventListener('submit', function (e) {
        e.preventDefault();
        var datos = Object.fromEntries(new FormData(this).entries());
        var boton = this.querySelector('[type="submit"]');
        boton.disabled = true;
        api('guardar', null, datos).then(function (j) {
            return guardarFoto(j.id).then(function () {
                cerrar($('modal-articulo'));
                avisar(datos.id ? 'Artículo actualizado' : 'Artículo creado', 'success');
            }, function (errFoto) {
                // El artículo sí se guardó: queda abierto para reintentar la foto
                $('form-articulo').elements.id.value = j.id;
                avisar('El artículo se guardó, pero la foto no: ' + errFoto.message, 'error');
            });
        }).then(recargarTodo).catch(function (err) { avisar(err.message, 'error'); })
          .then(function () { boton.disabled = false; });
    });

    // ---------- Movimientos ----------
    function abrirMovimiento(a) {
        var f = $('form-movimiento');
        f.reset();
        f.elements.articulo_id.value = a.id;
        $('mov-articulo').innerHTML = '<strong>' + esc(a.sku) + '</strong> · ' + esc(a.nombre) +
            ' — existencia actual: <strong>' + num(a.existencia) + ' ' + esc(a.unidad) + '</strong>';
        actualizarEtiquetaCantidad();
        abrir('modal-movimiento');
    }
    function actualizarEtiquetaCantidad() {
        $('m-cantidad-label').textContent = $('m-tipo').value === 'ajuste' ? 'Conteo físico (existencia real)' : 'Cantidad';
    }
    $('m-tipo').addEventListener('change', actualizarEtiquetaCantidad);

    $('form-movimiento').addEventListener('submit', function (e) {
        e.preventDefault();
        var datos = Object.fromEntries(new FormData(this).entries());
        var boton = this.querySelector('[type="submit"]');
        boton.disabled = true;
        api('movimiento', null, datos).then(function (j) {
            cerrar($('modal-movimiento'));
            avisar('Movimiento registrado. Existencia: ' + num(j.existencia), 'success');
            return recargarTodo();
        }).catch(function (err) { avisar(err.message, 'error'); })
          .then(function () { boton.disabled = false; });
    });

    // ---------- Kárdex ----------
    var ETIQUETA_REF = { incidencia: 'Orden de servicio', venta: 'Venta', manual: 'Manual' };
    function abrirKardex(id) {
        $('tabla-kardex').innerHTML = '<tr><td colspan="6" class="inv-vacio">Cargando...</td></tr>';
        abrir('modal-kardex');
        api('kardex', { id: id }).then(function (j) {
            $('titulo-kardex').textContent = 'Kárdex · ' + j.articulo.sku + ' · ' + j.articulo.nombre;
            $('tabla-kardex').innerHTML = j.movimientos.length ? j.movimientos.map(function (m) {
                var c = parseFloat(m.cantidad);
                var ref = m.referencia_tipo && m.referencia_tipo !== 'manual'
                    ? ' <span class="etiqueta etiqueta-info">' + esc(ETIQUETA_REF[m.referencia_tipo] || m.referencia_tipo) +
                      (m.referencia_folio ? ' ' + esc(m.referencia_folio) : '') + '</span>'
                    : '';
                return '<tr>' +
                    '<td>' + new Date(m.creado_en.replace(' ', 'T')).toLocaleString('es-MX') + '</td>' +
                    '<td>' + esc(m.tipo.charAt(0).toUpperCase() + m.tipo.slice(1)) + '</td>' +
                    '<td class="inv-num ' + (c >= 0 ? 'inv-mov-pos' : 'inv-mov-neg') + '">' + (c > 0 ? '+' : '') + num(c) + '</td>' +
                    '<td class="inv-num">' + num(m.existencia_resultante) + '</td>' +
                    '<td>' + esc(m.motivo || '') + ref + '</td>' +
                    '<td>' + esc(m.usuario_nombre || '') + '</td>' +
                    '</tr>';
            }).join('') : '<tr><td colspan="6" class="inv-vacio">Sin movimientos.</td></tr>';
        }).catch(function (e) {
            $('tabla-kardex').innerHTML = '<tr><td colspan="6" class="inv-vacio">' + esc(e.message) + '</td></tr>';
        });
    }

    // ---------- Acciones de la tabla ----------
    $('tabla-articulos').addEventListener('click', function (e) {
        var btn = e.target.closest('[data-accion]');
        if (!btn) return;
        var a = articulos.find(function (x) { return String(x.id) === btn.getAttribute('data-id'); });
        if (!a) return;
        switch (btn.getAttribute('data-accion')) {
            case 'mov': abrirMovimiento(a); break;
            case 'kardex': abrirKardex(a.id); break;
            case 'editar': abrirArticulo(a); break;
            case 'estado':
                var activar = a.activo != 1;
                AppAviso.confirmar(activar ? 'Volverá a aparecer para registrar consumos, ventas y cotizaciones.'
                    : 'Ya no aparecerá para registrar consumos ni ventas; su historial se conserva.',
                    { titulo: (activar ? '¿Reactivar ' : '¿Desactivar ') + a.nombre + '?', boton: activar ? 'Reactivar' : 'Desactivar', peligro: !activar })
                .then(function (ok) {
                    if (!ok) return;
                    return api('estado', null, { id: a.id, activo: activar }).then(function () {
                        avisar(activar ? 'Artículo reactivado' : 'Artículo desactivado', 'success');
                        return recargarTodo();
                    });
                }).catch(function (err) { avisar(err.message, 'error'); });
                break;
        }
    });

    // ---------- Inicio ----------
    document.addEventListener('DOMContentLoaded', function () {
        var opciones = CATEGORIAS.map(function (c) { return '<option value="' + c + '">' + c + '</option>'; }).join('');
        $('f-categoria').insertAdjacentHTML('beforeend', opciones);
        $('a-categoria').innerHTML = opciones;

        var espera = null;
        $('f-buscar').addEventListener('input', function () {
            clearTimeout(espera);
            espera = setTimeout(cargarArticulos, 250);
        });
        ['f-tipo', 'f-categoria', 'f-bajo', 'f-inactivos'].forEach(function (id) { $(id).addEventListener('change', cargarArticulos); });
        $('a-categoria').addEventListener('change', function () { mostrarCamposSegunCategoria(null); });
        $('btn-nuevo').addEventListener('click', function () { abrirArticulo(null); });

        // Viene del KPI del inicio u otro enlace: ?bajo_minimo=1
        if (new URLSearchParams(location.search).get('bajo_minimo')) $('f-bajo').checked = true;
        // O-10: atajo "Nuevo artículo" de Inicio
        if (new URLSearchParams(location.search).get('nuevo')) abrirArticulo(null);

        window.addEventListener('app-tema', cargarResumen);
        recargarTodo();
    });
})();
