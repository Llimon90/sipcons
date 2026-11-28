// D-44: el campo "Sucursal" sugiere las sucursales del cliente elegido (catálogo del
// cliente). Se puede escribir una nueva: al guardar, el servidor la da de alta y la liga.
//
//   AppSucursales.vincular(inputCliente, inputSucursal)
window.AppSucursales = (function () {
    var BASE = /\/public\//.test(location.pathname) ? '../' : '';
    var cuenta = 0;

    function norm(s) { return (s || '').trim().toLowerCase(); }

    function vincular(inputCliente, inputSucursal) {
        if (!inputCliente || !inputSucursal) return;
        var lista = document.createElement('datalist');
        lista.id = 'lista-sucursales-' + (++cuenta);
        inputSucursal.setAttribute('list', lista.id);
        inputSucursal.setAttribute('autocomplete', 'off');
        if (!inputSucursal.placeholder) inputSucursal.placeholder = 'Elige o escribe la sucursal';
        inputSucursal.insertAdjacentElement('afterend', lista);
        // Aviso cuando lo escrito no es una sucursal registrada del cliente (evita
        // que "cualquier cosa" cree una sucursal basura sin que el usuario lo note).
        var aviso = document.createElement('small');
        aviso.className = 'suc-aviso';
        aviso.hidden = true;
        lista.insertAdjacentElement('afterend', aviso);
        var cargado = null, nombres = [];
        inputSucursal._sucNombres = function () { return nombres; };  // lo usa la validación al guardar

        function revisar() {
            var v = norm(inputSucursal.value);
            if (!v || !nombres.length) { aviso.hidden = true; return; }
            var existe = nombres.some(function (n) { return norm(n) === v; });
            aviso.hidden = existe;
            if (!existe) {
                aviso.textContent = '➕ Sucursal nueva: se dará de alta para este cliente. (Registradas: ' + nombres.join(', ') + ')';
            }
        }

        function cargar() {
            var cliente = inputCliente.value.trim();
            if (cliente === cargado) return;
            cargado = cliente;
            lista.innerHTML = '';
            nombres = [];
            aviso.hidden = true;
            if (!cliente) return;
            fetch(BASE + 'backend/sucursales.php?accion=listar&cliente=' + encodeURIComponent(cliente), { credentials: 'same-origin', cache: 'no-store' })
                .then(function (r) { return r.json(); })
                .then(function (j) {
                    if (!j || !j.exito || cliente !== inputCliente.value.trim()) return;
                    nombres = (j.sucursales || []).map(function (s) { return s.nombre; });
                    (j.sucursales || []).forEach(function (s) {
                        var o = document.createElement('option');
                        o.value = s.nombre;
                        if (s.direccion) o.label = s.direccion;
                        lista.appendChild(o);
                    });
                    // Si el cliente tiene UNA sola sucursal y el campo está vacío, se llena sola
                    // (así el usuario no escribe "cualquier cosa" cuando no hay de dónde elegir).
                    if (nombres.length === 1 && !inputSucursal.value.trim()) inputSucursal.value = nombres[0];
                    revisar();
                })
                .catch(function () { /* sin sugerencias: se puede escribir */ });
        }
        inputCliente.addEventListener('change', cargar);
        inputCliente.addEventListener('blur', cargar);
        inputSucursal.addEventListener('focus', cargar);
        inputSucursal.addEventListener('input', revisar);
        cargar();
    }

    // Para validar al guardar: ¿lo escrito es una sucursal nueva (no registrada)?
    function esNueva(inputSucursal) {
        if (!inputSucursal || typeof inputSucursal._sucNombres !== 'function') return false;
        var v = norm(inputSucursal.value), nombres = inputSucursal._sucNombres();
        return !!v && nombres.length > 0 && !nombres.some(function (n) { return norm(n) === v; });
    }

    return { vincular: vincular, esNueva: esNueva };
})();
