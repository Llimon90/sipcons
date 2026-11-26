(function () {
    document.addEventListener('DOMContentLoaded', function () {
        var formProducto = document.getElementById('form-producto');
        var mensajeProducto = document.getElementById('mensaje-producto');
        var cuerpoProductos = document.getElementById('cuerpo-productos');
        var campoBusqueda = document.getElementById('campo-busqueda');
        var filtroBajoMinimo = document.getElementById('filtro-bajo-minimo');
        var seccionMovimiento = document.getElementById('seccion-movimiento');
        var formMovimiento = document.getElementById('form-movimiento');
        var movimientoProductoId = document.getElementById('movimiento-producto-id');
        var nombreProductoMovimiento = document.getElementById('nombre-producto-movimiento');
        var historialMovimientos = document.getElementById('historial-movimientos');

        function escapeHtml(v) {
            if (v === null || v === undefined) return '';
            return String(v).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
        }

        function cargarProductos() {
            var params = new URLSearchParams();
            if (campoBusqueda.value.trim()) params.set('q', campoBusqueda.value.trim());
            if (filtroBajoMinimo.checked) params.set('bajo_minimo', '1');
            fetch('../backend/buscar-productos.php?' + params.toString(), { credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (productos) {
                    if (!Array.isArray(productos) || productos.length === 0) {
                        cuerpoProductos.innerHTML = '<tr><td colspan="8">Sin productos registrados.</td></tr>';
                        return;
                    }
                    cuerpoProductos.innerHTML = productos.map(function (p) {
                        var bajoMinimo = parseInt(p.stock_actual, 10) <= parseInt(p.stock_minimo, 10);
                        return '<tr' + (bajoMinimo ? ' style="background:#fdecea;"' : '') + '>' +
                            '<td>' + escapeHtml(p.sku) + '</td>' +
                            '<td>' + escapeHtml(p.nombre) + '</td>' +
                            '<td>' + escapeHtml(p.unidad) + '</td>' +
                            '<td>' + escapeHtml(p.stock_actual) + (bajoMinimo ? ' <i class="fas fa-exclamation-triangle" title="Stock bajo mínimo" style="color:#e74c3c;"></i>' : '') + '</td>' +
                            '<td>' + escapeHtml(p.stock_minimo) + '</td>' +
                            '<td>$' + parseFloat(p.costo).toFixed(2) + '</td>' +
                            '<td>$' + parseFloat(p.precio_venta).toFixed(2) + '</td>' +
                            '<td>' +
                                '<button type="button" class="btn-movimiento" data-id="' + p.id + '" data-nombre="' + escapeHtml(p.nombre) + '">Movimiento</button> ' +
                                '<button type="button" class="btn-eliminar" data-id="' + p.id + '">Desactivar</button>' +
                            '</td>' +
                        '</tr>';
                    }).join('');
                })
                .catch(function () { cuerpoProductos.innerHTML = '<tr><td colspan="8">No se pudo cargar el inventario.</td></tr>'; });
        }

        formProducto.addEventListener('submit', function (e) {
            e.preventDefault();
            var datos = {
                sku: document.getElementById('sku').value.trim(),
                nombre: document.getElementById('nombre').value.trim(),
                descripcion: document.getElementById('descripcion').value.trim(),
                unidad: document.getElementById('unidad').value.trim() || 'pza',
                stock_actual: document.getElementById('stock_actual').value,
                stock_minimo: document.getElementById('stock_minimo').value,
                costo: document.getElementById('costo').value,
                precio_venta: document.getElementById('precio_venta').value,
            };
            mensajeProducto.textContent = 'Guardando...';
            fetch('../backend/guardar-producto.php', {
                method: 'POST', credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(datos)
            }).then(function (r) { return r.json(); }).then(function (j) {
                mensajeProducto.textContent = j.message || (j.success ? 'Producto guardado.' : 'Error al guardar.');
                mensajeProducto.style.color = j.success ? '#27ae60' : '#e74c3c';
                if (j.success) { formProducto.reset(); document.getElementById('unidad').value = 'pza'; cargarProductos(); }
            }).catch(function () { mensajeProducto.textContent = 'Sin conexión con el servidor.'; mensajeProducto.style.color = '#e74c3c'; });
        });

        cuerpoProductos.addEventListener('click', function (e) {
            var btnMov = e.target.closest('.btn-movimiento');
            if (btnMov) {
                movimientoProductoId.value = btnMov.dataset.id;
                nombreProductoMovimiento.textContent = btnMov.dataset.nombre;
                seccionMovimiento.style.display = '';
                cargarMovimientos(btnMov.dataset.id);
                seccionMovimiento.scrollIntoView({ behavior: 'smooth' });
                return;
            }
            var btnDel = e.target.closest('.btn-eliminar');
            if (btnDel) {
                if (!confirm('¿Desactivar este producto? Dejará de aparecer en el catálogo activo.')) return;
                fetch('../backend/eliminar-producto.php?id=' + encodeURIComponent(btnDel.dataset.id), { method: 'DELETE', credentials: 'same-origin' })
                    .then(function (r) { return r.json(); }).then(function (j) { if (j.success) cargarProductos(); else alert(j.message || 'No se pudo desactivar.'); });
            }
        });

        function cargarMovimientos(productoId) {
            fetch('../backend/obtener-movimientos-producto.php?producto_id=' + encodeURIComponent(productoId), { credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (movs) {
                    if (!Array.isArray(movs) || movs.length === 0) { historialMovimientos.innerHTML = '<p>Sin movimientos registrados.</p>'; return; }
                    historialMovimientos.innerHTML = '<table class="tabla-ventas" style="width:100%;"><thead><tr><th>Fecha</th><th>Tipo</th><th>Cantidad</th><th>Motivo</th><th>Usuario</th></tr></thead><tbody>' +
                        movs.map(function (m) {
                            return '<tr><td>' + escapeHtml(m.creado_en) + '</td><td>' + escapeHtml(m.tipo) + '</td><td>' + escapeHtml(m.cantidad) + '</td><td>' + escapeHtml(m.motivo) + '</td><td>' + escapeHtml(m.usuario_nombre) + '</td></tr>';
                        }).join('') + '</tbody></table>';
                });
        }

        formMovimiento.addEventListener('submit', function (e) {
            e.preventDefault();
            var datos = {
                producto_id: movimientoProductoId.value,
                tipo: document.getElementById('movimiento-tipo').value,
                cantidad: document.getElementById('movimiento-cantidad').value,
                motivo: document.getElementById('movimiento-motivo').value.trim(),
            };
            fetch('../backend/registrar-movimiento-inventario.php', {
                method: 'POST', credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(datos)
            }).then(function (r) { return r.json(); }).then(function (j) {
                if (j.success) { document.getElementById('movimiento-motivo').value = ''; cargarMovimientos(datos.producto_id); cargarProductos(); }
                else alert(j.message || 'No se pudo registrar el movimiento.');
            });
        });

        campoBusqueda.addEventListener('input', function () { clearTimeout(window.__invTimer); window.__invTimer = setTimeout(cargarProductos, 300); });
        filtroBajoMinimo.addEventListener('change', cargarProductos);

        cargarProductos();
    });
})();
