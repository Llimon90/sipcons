// D-44: el campo "Sucursal" sugiere las sucursales del cliente elegido (catálogo del
// cliente). Se puede escribir una nueva: al guardar, el servidor la da de alta y la liga.
//
//   AppSucursales.vincular(inputCliente, inputSucursal)
window.AppSucursales = (function () {
    var BASE = /\/public\//.test(location.pathname) ? '../' : '';
    var cuenta = 0;

    function vincular(inputCliente, inputSucursal) {
        if (!inputCliente || !inputSucursal) return;
        var lista = document.createElement('datalist');
        lista.id = 'lista-sucursales-' + (++cuenta);
        inputSucursal.setAttribute('list', lista.id);
        inputSucursal.setAttribute('autocomplete', 'off');
        if (!inputSucursal.placeholder) inputSucursal.placeholder = 'Elige o escribe la sucursal';
        inputSucursal.insertAdjacentElement('afterend', lista);
        var cargado = null;
        function cargar() {
            var cliente = inputCliente.value.trim();
            if (cliente === cargado) return;
            cargado = cliente;
            lista.innerHTML = '';
            if (!cliente) return;
            fetch(BASE + 'backend/sucursales.php?accion=listar&cliente=' + encodeURIComponent(cliente), { credentials: 'same-origin', cache: 'no-store' })
                .then(function (r) { return r.json(); })
                .then(function (j) {
                    if (!j || !j.exito || cliente !== inputCliente.value.trim()) return;
                    (j.sucursales || []).forEach(function (s) {
                        var o = document.createElement('option');
                        o.value = s.nombre;
                        if (s.direccion) o.label = s.direccion;
                        lista.appendChild(o);
                    });
                })
                .catch(function () { /* sin sugerencias: se puede escribir */ });
        }
        inputCliente.addEventListener('change', cargar);
        inputCliente.addEventListener('blur', cargar);
        inputSucursal.addEventListener('focus', cargar);
        cargar();
    }

    return { vincular: vincular };
})();
