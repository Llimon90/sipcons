// Refacciones usadas en esta incidencia (descuenta inventario). Puerto
// acotado de Servidesk (backend/inventario.php: consumir_incidencia /
// devolver_incidencia). Sección independiente de detalle.js y de
// detalle-extras.js: fetches propios, sin compartir estado con el
// formulario principal.
(function () {
    function idIncidencia() {
        return new URLSearchParams(window.location.search).get('id');
    }

    function escapeHtml(valor) {
        return String(valor ?? '')
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    function mostrarMensaje(texto, tipo) {
        var el = document.getElementById('mensaje-refaccion');
        if (!el) return;
        el.textContent = texto;
        el.style.color = tipo === 'error' ? '#c0392b' : '#27ae60';
    }

    var productosCatalogo = [];

    function renderSelectorProductos() {
        var selector = document.getElementById('refaccion-producto');
        if (!selector) return;
        if (productosCatalogo.length === 0) {
            selector.innerHTML = '<option value="">Sin productos activos en inventario</option>';
            return;
        }
        selector.innerHTML = productosCatalogo.map(function (p) {
            return '<option value="' + p.id + '">' + escapeHtml(p.sku) + ' — ' + escapeHtml(p.nombre) +
                ' (stock: ' + escapeHtml(p.stock_actual) + ' ' + escapeHtml(p.unidad) + ')</option>';
        }).join('');
    }

    function cargarCatalogoProductos() {
        fetch('../backend/opciones-productos.php')
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data.success) return;
                productosCatalogo = data.productos || [];
                renderSelectorProductos();
            })
            .catch(function () { /* el selector queda vacío si falla */ });
    }

    function renderConsumos(consumos) {
        var contenedor = document.getElementById('refacciones-consumidas');
        if (!contenedor) return;
        if (!consumos || consumos.length === 0) {
            contenedor.innerHTML = '<p>Sin refacciones registradas en esta incidencia.</p>';
            return;
        }
        contenedor.innerHTML = '<table class="tabla-ventas" style="width:100%;"><thead><tr>' +
            '<th>SKU</th><th>Producto</th><th>Cantidad</th><th>Importe</th><th></th></tr></thead><tbody>' +
            consumos.map(function (c) {
                var importe = (parseFloat(c.precio_venta) || 0) * (parseInt(c.cantidad, 10) || 0);
                return '<tr>' +
                    '<td>' + escapeHtml(c.sku) + '</td>' +
                    '<td>' + escapeHtml(c.nombre) + ' (' + escapeHtml(c.unidad) + ')</td>' +
                    '<td>' + escapeHtml(c.cantidad) + '</td>' +
                    '<td>$' + importe.toFixed(2) + '</td>' +
                    '<td><button type="button" class="btn-devolver-refaccion" data-producto-id="' + c.producto_id + '" data-cantidad="' + c.cantidad + '">Devolver</button></td>' +
                '</tr>';
            }).join('') + '</tbody></table>';
    }

    function cargarConsumos() {
        var contenedor = document.getElementById('refacciones-consumidas');
        fetch('../backend/obtener-consumos-incidencia.php?incidencia_id=' + encodeURIComponent(idIncidencia()))
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data.success) { if (contenedor) contenedor.innerHTML = '<p>No se pudo cargar.</p>'; return; }
                renderConsumos(data.consumos);
            })
            .catch(function () { if (contenedor) contenedor.innerHTML = '<p>Sin conexión con el servidor.</p>'; });
    }

    document.addEventListener('DOMContentLoaded', function () {
        if (!document.getElementById('seccion-refacciones-incidencia')) return;

        cargarCatalogoProductos();
        cargarConsumos();

        var btnRegistrar = document.getElementById('btn-registrar-refaccion');
        if (btnRegistrar) {
            btnRegistrar.addEventListener('click', function () {
                var productoId = document.getElementById('refaccion-producto').value;
                var cantidad = document.getElementById('refaccion-cantidad').value;
                if (!productoId) { mostrarMensaje('Elige un producto.', 'error'); return; }
                mostrarMensaje('Registrando...', 'ok');
                fetch('../backend/consumir-refaccion-incidencia.php', {
                    method: 'POST', credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ incidencia_id: idIncidencia(), producto_id: productoId, cantidad: cantidad })
                }).then(function (r) { return r.json(); }).then(function (data) {
                    if (data.success) {
                        mostrarMensaje('Refacción registrada.', 'ok');
                        cargarConsumos();
                        cargarCatalogoProductos();
                    } else {
                        mostrarMensaje(data.message || 'No se pudo registrar.', 'error');
                    }
                }).catch(function () { mostrarMensaje('Sin conexión con el servidor.', 'error'); });
            });
        }

        var contenedorConsumos = document.getElementById('refacciones-consumidas');
        if (contenedorConsumos) {
            contenedorConsumos.addEventListener('click', function (e) {
                var btn = e.target.closest('.btn-devolver-refaccion');
                if (!btn) return;
                var cantidad = prompt('¿Cuántas unidades se devuelven al almacén?', btn.dataset.cantidad);
                if (cantidad === null) return;
                fetch('../backend/devolver-refaccion-incidencia.php', {
                    method: 'POST', credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ incidencia_id: idIncidencia(), producto_id: btn.dataset.productoId, cantidad: cantidad })
                }).then(function (r) { return r.json(); }).then(function (data) {
                    if (data.success) { mostrarMensaje('Refacción devuelta al almacén.', 'ok'); cargarConsumos(); cargarCatalogoProductos(); }
                    else mostrarMensaje(data.message || 'No se pudo devolver.', 'error');
                }).catch(function () { mostrarMensaje('Sin conexión con el servidor.', 'error'); });
            });
        }
    });
})();
