// Sugerencias al capturar un equipo del padrón (D-41): tipo, marca y modelo conocidos.
// Al elegir un modelo conocido, se llenan el tipo de equipo y la marca si están vacíos.
//   AppSugEquipo.vincular({ equipo: input, marca: input, modelo: input })
window.AppSugEquipo = (function () {
    var BASE = /\/public\//.test(location.pathname) ? '../' : '';
    var datos = null;
    var cuenta = 0;
    function cargar() {
        if (!datos) {
            datos = fetch(BASE + 'backend/sugerencias_equipo.php', { credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (j) { return j && j.exito ? j : { equipos: [], marcas: [], modelos: [] }; })
                .catch(function () { return { equipos: [], marcas: [], modelos: [] }; });
        }
        return datos;
    }
    function lista(input, valores) {
        if (!input) return;
        var dl = document.createElement('datalist');
        dl.id = 'sug-equipo-' + (++cuenta);
        valores.forEach(function (v) { var o = document.createElement('option'); o.value = v; dl.appendChild(o); });
        input.setAttribute('list', dl.id);
        input.setAttribute('autocomplete', 'off');
        input.insertAdjacentElement('afterend', dl);
    }
    function vincular(campos) {
        var listo = false;
        var alUsar = function () {
            if (listo) return;
            listo = true;
            conectar(campos);
        };
        [campos.equipo, campos.marca, campos.modelo].forEach(function (c) { if (c) c.addEventListener('focus', alUsar); });
    }
    function conectar(campos) {
        cargar().then(function (d) {
            lista(campos.equipo, d.equipos);
            lista(campos.marca, d.marcas);
            lista(campos.modelo, d.modelos.map(function (m) { return m.modelo; }));
            if (!campos.modelo) return;
            var alElegir = function () {
                var clave = campos.modelo.value.trim().toUpperCase();
                var m = d.modelos.find(function (x) { return x.modelo.toUpperCase() === clave; });
                if (!m) return;
                if (campos.marca && !campos.marca.value.trim() && m.marca) campos.marca.value = m.marca;
                if (campos.equipo && !campos.equipo.value.trim() && m.equipo) campos.equipo.value = m.equipo;
            };
            campos.modelo.addEventListener('change', alElegir);
            campos.modelo.addEventListener('blur', alElegir);
        });
    }
    return { vincular: vincular };
})();
