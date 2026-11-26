// Importar datos (public/importar.html): plantilla CSV, vista previa y
// confirmación. El servidor vuelve a validar al importar (backend/importar.php).
(function () {
    'use strict';

    var API = '../backend/importar.php';
    var NOTAS = {
        clientes: 'Obligatorio: Nombre. Si un cliente ya existe con el mismo nombre, se deja como está.',
        equipos: 'Obligatorios: Cliente y Equipo. El cliente ya debe existir: importa primero tus clientes. La serie no se puede repetir; deja la columna vacía si el equipo no tiene.',
        articulos: 'Obligatorios: SKU, Nombre y Categoría (Equipo, Refacción, Consumible, Herramienta, Servicio o Mano de obra). La existencia inicial entra al kárdex. Si el SKU ya existe, se deja como está.'
    };
    var $ = function (id) { return document.getElementById(id); };
    var esc = window.escHTML;
    var tipo = null;
    var analisis = null;
    var archivo = $('archivo');

    function api(accion, cuerpo) {
        return fetch(API + '?accion=' + accion + '&tipo=' + tipo, { method: 'POST', body: cuerpo, credentials: 'same-origin', cache: 'no-store' })
            .then(function (r) {
                return r.json().catch(function () { return {}; }).then(function (j) {
                    if (!r.ok || !j.exito) throw new Error(j.mensaje || 'Error ' + r.status);
                    return j;
                });
            });
    }
    function conArchivo() {
        var f = new FormData();
        f.append('archivo', archivo.files[0]);
        return f;
    }

    function elegirTipo(nuevo) {
        tipo = nuevo;
        document.querySelectorAll('.fin-tabs [data-tipo]').forEach(function (b) {
            var activo = b.dataset.tipo === tipo;
            b.classList.toggle('activo', activo);
            b.setAttribute('aria-selected', activo ? 'true' : 'false');
        });
        $('btn-plantilla').href = API + '?accion=plantilla&tipo=' + tipo;
        $('nota-tipo').textContent = NOTAS[tipo];
        reiniciar();
        try { history.replaceState(null, '', '?tipo=' + tipo); } catch (e) { /* nada */ }
    }

    function reiniciar() {
        analisis = null;
        $('vista-previa').hidden = true;
        if (archivo._selector) archivo._selector.limpiar(); else archivo.value = '';
    }

    function etiqueta(accion) {
        if (accion === 'crear') return '<span class="etiqueta etiqueta-ok">Nuevo</span>';
        if (accion === 'omitir') return '<span class="etiqueta etiqueta-neutral">Ya existe</span>';
        return '<span class="etiqueta etiqueta-error">Error</span>';
    }

    function pintar() {
        var a = analisis;
        var r = a.resumen;
        $('resumen').innerHTML =
            '<div class="imp-cifra ok"><b>' + r.crear + '</b> nuevos</div>' +
            '<div class="imp-cifra"><b>' + r.omitir + '</b> ya existen (se omiten)</div>' +
            '<div class="imp-cifra ' + (r.error ? 'error' : '') + '"><b>' + r.error + '</b> con error</div>';
        $('ignoradas').hidden = !a.ignoradas.length;
        $('ignoradas').textContent = a.ignoradas.length ? 'Columnas que no se usan y se ignoran: ' + a.ignoradas.join(', ') + '.' : '';

        var campos = Object.keys(a.columnas);
        $('previa-cabeza').innerHTML = '<tr><th>Renglón</th><th>Resultado</th><th>Observaciones</th>' +
            campos.map(function (c) { return '<th>' + esc(a.columnas[c]) + '</th>'; }).join('') + '</tr>';
        var soloProblemas = $('solo-problemas').checked;
        var filas = a.filas.filter(function (f) { return !soloProblemas || f.errores.length || f.avisos.length; });
        $('previa-cuerpo').innerHTML = filas.length ? filas.map(function (f) {
            var notas = f.errores.map(function (e) { return '<div class="fin-rojo">' + esc(e) + '</div>'; })
                .concat(f.avisos.map(function (e) { return '<div class="imp-nota-aviso">' + esc(e) + '</div>'; })).join('');
            return '<tr class="imp-' + f.accion + '"><td>' + f.renglon + '</td><td>' + etiqueta(f.accion) + '</td>' +
                '<td class="imp-notas">' + notas + '</td>' +
                campos.map(function (c) { return '<td>' + esc(f.datos[c]) + '</td>'; }).join('') + '</tr>';
        }).join('') : '<tr><td colspan="' + (campos.length + 3) + '" class="inv-vacio">Ningún renglón con error o aviso.</td></tr>';

        var boton = $('btn-importar');
        boton.disabled = r.error > 0 || r.crear === 0;
        boton.innerHTML = '<i class="fas fa-check"></i> ' + (r.error ? 'Corrige los errores para importar' : r.crear ? 'Importar ' + r.crear + ' ' + (r.crear === 1 ? 'renglón' : 'renglones') : 'Nada nuevo que importar');
        $('vista-previa').hidden = false;
    }

    archivo.addEventListener('change', function () {
        if (!archivo.files || !archivo.files[0]) return;
        $('vista-previa').hidden = true;
        api('validar', conArchivo()).then(function (j) {
            analisis = j;
            pintar();
            $('vista-previa').scrollIntoView({ behavior: 'smooth', block: 'start' });
        }).catch(function (e) {
            AppAviso.mostrar(e.message, 'error');
            reiniciar();
        });
    });

    $('solo-problemas').addEventListener('change', function () { if (analisis) pintar(); });
    $('btn-otro').addEventListener('click', reiniciar);

    $('btn-importar').addEventListener('click', function () {
        if (!analisis || !archivo.files[0]) return;
        var n = analisis.resumen.crear;
        AppAviso.confirmar('Se van a dar de alta ' + n + ' ' + (n === 1 ? 'registro' : 'registros') + ' en ' + analisis.titulo + '. Cada alta queda en el Historial.',
            { titulo: '¿Importar ahora?', boton: 'Importar' }).then(function (ok) {
            if (!ok) return;
            var boton = $('btn-importar');
            boton.disabled = true;
            boton.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Importando…';
            api('importar', conArchivo()).then(function (j) {
                AppAviso.mostrar('Listo: se importaron ' + j.importados + ' ' + (j.importados === 1 ? 'registro' : 'registros') + ' correctamente.', 'ok');
                reiniciar();
            }).catch(function (e) {
                AppAviso.mostrar(e.message, 'error');
                if (analisis) pintar();
            });
        });
    });

    window.appOnAuthReady(function (auth) {
        var administra = auth.empresa && auth.empresa.administra;
        var modulos = auth.modulos || [];
        var visibles = [];
        document.querySelectorAll('.fin-tabs [data-tipo]').forEach(function (b) {
            b.hidden = modulos.indexOf(b.dataset.modulo) === -1;
            if (!b.hidden) visibles.push(b.dataset.tipo);
            b.addEventListener('click', function () { elegirTipo(b.dataset.tipo); });
        });
        if (!administra || !visibles.length) {
            $('sin-permiso').hidden = false;
            return;
        }
        $('imp-contenido').hidden = false;
        var pedido = (location.search.match(/[?&]tipo=(\w+)/) || [])[1];
        elegirTipo(visibles.indexOf(pedido) !== -1 ? pedido : visibles[0]);
    });
})();
