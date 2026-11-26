// Comportamiento común de los formularios (estilos en css/formularios.css):
//  - etiquetas uniformes: sin dos puntos finales, sin MAYÚSCULAS sostenidas,
//    asterisco en los campos obligatorios y marca "Opcional" donde la etiqueta
//    decía "(opcional)";
//  - validación en línea: al salir de un campo o al intentar guardar, el error
//    se muestra debajo del campo (en lugar del globo del navegador) y la página
//    lleva al primer campo con problema;
//  - los cuadros de texto crecen al escribir y muestran el contador si tienen límite.
// Funciona también con formularios que se arman después (detalle, modales).
(function () {
    'use strict';

    var AMBITO = 'main:not(.ini):not(.me-pagina), .modal, .modal-content';
    var SIGLAS = ['RFC', 'SKU', 'CFDI', 'IVA', 'PDF', 'ID', 'CP', 'C.P.', 'S/N', 'URL', 'WhatsApp'];

    function enAmbito(el) { return !!(el && el.closest && el.closest(AMBITO)); }

    function controlDe(label) {
        if (label.htmlFor) return document.getElementById(label.htmlFor);
        return label.querySelector('input, select, textarea');
    }

    // "NÚMERO DE SERIE DEL EQUIPO" -> "Número de serie del equipo" (respeta siglas)
    function aOracion(texto) {
        var minus = texto.toLocaleLowerCase('es-MX');
        SIGLAS.forEach(function (s) {
            minus = minus.replace(new RegExp('(^|[^\\wáéíóúñ])' + s.toLocaleLowerCase('es-MX').replace(/[.*+?^${}()|[\]\\/]/g, '\\$&') + '(?=$|[^\\wáéíóúñ])', 'g'),
                function (m, antes) { return antes + s; });
        });
        return minus.replace(/[a-záéíóúñ]/i, function (c) { return c.toLocaleUpperCase('es-MX'); });
    }

    function textosDe(label) {
        var nodos = [];
        var recorrer = document.createTreeWalker(label, NodeFilter.SHOW_TEXT, {
            acceptNode: function (n) {
                return n.parentElement && n.parentElement.closest('select, option, .f-req, .f-opcional') ? NodeFilter.FILTER_REJECT : NodeFilter.FILTER_ACCEPT;
            }
        });
        while (recorrer.nextNode()) nodos.push(recorrer.currentNode);
        return nodos;
    }

    function mejorarEtiqueta(label) {
        if (label.dataset.fListo) return;
        label.dataset.fListo = '1';
        var control = controlDe(label);
        var envuelveCasilla = control && label.contains(control) && /checkbox|radio/.test(control.type);
        var nodos = textosDe(label);
        // "(opcional)" -> marca
        nodos.forEach(function (n) {
            if (!/\(\s*opcional\s*\)/i.test(n.nodeValue)) return;
            n.nodeValue = n.nodeValue.replace(/\s*\(\s*opcional\s*\)/i, '');
            var marca = document.createElement('span');
            marca.className = 'f-opcional';
            marca.textContent = 'Opcional';
            n.parentNode.insertBefore(marca, n.nextSibling);
        });
        var completo = nodos.map(function (n) { return n.nodeValue; }).join('').trim();

        // MAYÚSCULAS sostenidas -> oración ("FECHA" -> "Fecha"; las siglas como RFC se respetan)
        var letras = completo.replace(/[^A-Za-zÁÉÍÓÚÑáéíóúñ]/g, '');
        var esSigla = SIGLAS.indexOf(completo.replace(/[:\s]+$/, '')) !== -1;
        if (letras.length >= 4 && !esSigla && letras === letras.toLocaleUpperCase('es-MX')) {
            var primero = true;
            nodos.forEach(function (n) {
                if (!n.nodeValue.trim()) return;
                var t = aOracion(n.nodeValue);
                if (!primero) t = t.replace(/^(\s*)([A-ZÁÉÍÓÚÑ])(?![A-ZÁÉÍÓÚÑ.\/])/, function (m, esp, c) { return esp + c.toLocaleLowerCase('es-MX'); });
                n.nodeValue = t;
                primero = false;
            });
        }

        // Dos puntos al final ("Cliente:")
        for (var i = nodos.length - 1; i >= 0; i--) {
            if (!nodos[i].nodeValue.trim()) continue;
            nodos[i].nodeValue = nodos[i].nodeValue.replace(/\s*:\s*$/, '');
            break;
        }

        // Asterisco en obligatorios (no en casillas: "requerido" ahí es aceptar algo)
        if (control && control.required && !envuelveCasilla && !label.querySelector('.f-req')) {
            var req = document.createElement('span');
            req.className = 'f-req';
            req.setAttribute('aria-hidden', 'true');
            req.textContent = '*';
            label.appendChild(req);
        }
    }

    // ------------------------------------------------------------ cuadros de texto
    function ajustarAltura(ta) {
        if (ta.dataset.fFijo) return;
        ta.style.height = 'auto';
        ta.style.height = Math.min(ta.scrollHeight + 3, 520) + 'px';
    }
    function actualizarContador(ta) {
        var c = ta.fContador;
        if (!c) return;
        var max = parseInt(ta.getAttribute('maxlength'), 10);
        c.textContent = ta.value.length + ' / ' + max;
        c.classList.toggle('limite', ta.value.length >= max);
    }
    // U-4: si el código asigna .value (al cargar un registro), el contador y la altura se actualizan solos
    var valorTexto = Object.getOwnPropertyDescriptor(HTMLTextAreaElement.prototype, 'value');
    function vigilarValor(ta) {
        try {
            Object.defineProperty(ta, 'value', {
                configurable: true,
                get: function () { return valorTexto.get.call(this); },
                set: function (v) { valorTexto.set.call(this, v); actualizarContador(this); ajustarAltura(this); }
            });
        } catch (e) { /* navegador sin soporte: el contador se actualiza al escribir */ }
    }
    function mejorarTexto(ta) {
        if (ta.dataset.fListo) return;
        ta.dataset.fListo = '1';
        vigilarValor(ta);
        if (ta.getAttribute('maxlength')) {
            var c = document.createElement('span');
            c.className = 'f-contador';
            c.setAttribute('aria-live', 'polite');
            ta.insertAdjacentElement('afterend', c);
            ta.fContador = c;
            actualizarContador(ta);
        }
        if (ta.value) requestAnimationFrame(function () { ajustarAltura(ta); });
    }

    // ------------------------------------------------------------ validación
    function mensajeDe(control) {
        var v = control.validity;
        if (v.valueMissing) return control.type === 'checkbox' ? 'Marca esta casilla para continuar.' : 'Este campo es obligatorio.';
        if (v.typeMismatch && control.type === 'email') return 'Escribe un correo válido (por ejemplo nombre@empresa.com).';
        if (v.tooShort) return 'Escribe al menos ' + control.minLength + ' caracteres.';
        if (v.rangeUnderflow) return 'El valor mínimo es ' + control.min + '.';
        if (v.rangeOverflow) return 'El valor máximo es ' + control.max + '.';
        if (v.badInput) return 'Revisa el valor capturado.';
        return control.validationMessage;
    }
    function contenedorMensaje(control) {
        // Después del contador o la ayuda del campo, si los hay
        var ref = control;
        var sig = control.nextElementSibling;
        while (sig && (sig.classList.contains('f-contador') || sig.tagName === 'DATALIST')) { ref = sig; sig = sig.nextElementSibling; }
        return ref;
    }
    function mostrarError(control) {
        var previo = control.fError;
        if (control.validity.valid) {
            if (previo) { previo.remove(); control.fError = null; }
            control.removeAttribute('aria-invalid');
            return;
        }
        var msg = previo || document.createElement('div');
        msg.className = 'f-error-msg';
        msg.id = msg.id || 'f-err-' + Math.random().toString(36).slice(2, 8);
        msg.textContent = mensajeDe(control);
        if (!previo) {
            contenedorMensaje(control).insertAdjacentElement('afterend', msg);
            control.fError = msg;
        }
        control.setAttribute('aria-invalid', 'true');
        control.setAttribute('aria-describedby', msg.id);
    }

    var primerInvalido = null;
    document.addEventListener('invalid', function (e) {
        var control = e.target;
        if (!enAmbito(control)) return;
        e.preventDefault(); // sin el globo del navegador: el mensaje va debajo del campo
        control.classList.add('f-tocado');
        mostrarError(control);
        if (!primerInvalido) {
            primerInvalido = control;
            setTimeout(function () {
                if (primerInvalido) {
                    primerInvalido.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    primerInvalido.focus({ preventScroll: true });
                }
                primerInvalido = null;
            }, 0);
        }
    }, true);

    document.addEventListener('focusout', function (e) {
        var c = e.target;
        if (!enAmbito(c) || !c.matches('input, select, textarea') || !c.willValidate) return;
        if (c.value !== '' || c.classList.contains('f-tocado')) {
            c.classList.add('f-tocado');
            mostrarError(c);
        }
    });
    document.addEventListener('input', function (e) {
        var c = e.target;
        if (!enAmbito(c)) return;
        if (c.tagName === 'TEXTAREA') { ajustarAltura(c); actualizarContador(c); }
        if (c.classList && c.classList.contains('f-tocado')) mostrarError(c);
    });
    document.addEventListener('change', function (e) {
        var c = e.target;
        if (enAmbito(c) && c.classList && c.classList.contains('f-tocado')) mostrarError(c);
    });
    // Al limpiar un formulario se quitan también las marcas de error
    document.addEventListener('reset', function (e) {
        if (!enAmbito(e.target)) return;
        e.target.querySelectorAll('.f-tocado').forEach(function (c) { c.classList.remove('f-tocado'); c.removeAttribute('aria-invalid'); });
        e.target.querySelectorAll('.f-error-msg').forEach(function (m) { m.remove(); });
        setTimeout(function () {
            e.target.querySelectorAll('textarea').forEach(function (t) { ajustarAltura(t); actualizarContador(t); });
        }, 0);
    }, true);

    // ------------------------------------------------------------ arranque
    function mejorar(raiz) {
        if (!raiz.querySelectorAll) return;
        raiz.querySelectorAll('label').forEach(function (l) { if (enAmbito(l)) mejorarEtiqueta(l); });
        raiz.querySelectorAll('textarea').forEach(function (t) { if (enAmbito(t)) mejorarTexto(t); });
        raiz.querySelectorAll('.modal-content').forEach(mejorarDialogo);
    }

    // U-5: los modales propios del sistema se anuncian como diálogos y se nombran con su título
    var nDialogo = 0;
    function mejorarDialogo(caja) {
        if (caja.getAttribute('role')) return;
        caja.setAttribute('role', 'dialog');
        caja.setAttribute('aria-modal', 'true');
        var titulo = caja.querySelector('h1, h2, h3, h4');
        if (titulo) {
            if (!titulo.id) titulo.id = 'dialogo-titulo-' + (++nDialogo);
            caja.setAttribute('aria-labelledby', titulo.id);
        }
    }

    var pendiente = false;
    function programar() {
        if (pendiente) return;
        pendiente = true;
        requestAnimationFrame(function () { pendiente = false; mejorar(document); });
    }

    document.addEventListener('DOMContentLoaded', function () {
        mejorar(document);
        new MutationObserver(function (cambios) {
            for (var i = 0; i < cambios.length; i++) {
                if (cambios[i].addedNodes.length) { programar(); return; }
            }
        }).observe(document.body, { childList: true, subtree: true });
    });
})();
