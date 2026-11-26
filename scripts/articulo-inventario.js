// Selector de artículo de inventario para ventas (alta y edición).
// - AppInventario.selectorVenta(select, campos): llena el <select> con los
//   artículos activos (primero la categoría "Equipo") y, al elegir uno, copia
//   su nombre, marca y modelo a los inputs indicados en campos.
// - AppInventario.seleccionar(select, id): marca un artículo aunque la lista
//   todavía no haya terminado de cargar.
// La venta descuenta una unidad por cada número de serie (backend/registro_ventas.php).
window.AppInventario = (function () {
    var cache = null;

    function cargar() {
        if (!cache) {
            cache = fetch('../backend/inventario.php?accion=opciones', { cache: 'no-store', credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (j) { return j.exito ? j.articulos : []; })
                .catch(function () { return []; });
        }
        return cache;
    }

    function cantidad(n) {
        n = parseFloat(n) || 0;
        return n % 1 === 0 ? String(n) : n.toFixed(2);
    }

    function seleccionar(select, id) {
        if (!select) return;
        select.dataset.valor = id ? String(id) : '';
        select.value = select.dataset.valor;
    }

    function selectorVenta(select, campos) {
        if (!select) return Promise.resolve();
        campos = campos || {};

        select.addEventListener('change', function () {
            var opt = select.options[select.selectedIndex];
            if (!opt || !opt.value) return;
            if (campos.equipo) campos.equipo.value = opt.dataset.nombre || '';
            if (campos.marca) campos.marca.value = opt.dataset.marca || '';
            if (campos.modelo) campos.modelo.value = opt.dataset.modelo || '';
        });

        return cargar().then(function (articulos) {
            var ordenados = articulos.slice().sort(function (a, b) {
                var ea = a.categoria === 'Equipo' ? 0 : 1, eb = b.categoria === 'Equipo' ? 0 : 1;
                return ea - eb || a.nombre.localeCompare(b.nombre);
            });
            select.innerHTML = '<option value="">— Sin vincular al inventario —</option>';
            var grupo = null, grupoActual = '';
            ordenados.forEach(function (a) {
                if (a.categoria !== grupoActual) {
                    grupoActual = a.categoria;
                    grupo = document.createElement('optgroup');
                    grupo.label = a.categoria;
                    select.appendChild(grupo);
                }
                var o = document.createElement('option');
                o.value = a.id;
                o.textContent = a.sku + ' · ' + a.nombre + ' (' + cantidad(a.existencia) + ' ' + a.unidad + ' disp.)';
                o.dataset.nombre = a.nombre;
                o.dataset.marca = a.marca || '';
                o.dataset.modelo = a.modelo || '';
                grupo.appendChild(o);
            });
            if (select.dataset.valor) select.value = select.dataset.valor;
        });
    }

    return { cargar: cargar, selectorVenta: selectorVenta, seleccionar: seleccionar };
})();
