// Alta rápida de clientes sin salir del formulario (incidencias, detalle,
// ventas). Junto al campo de cliente aparece «+ Nuevo cliente»; si lo escrito
// no existe en el catálogo, el aviso ofrece crearlo con ese nombre. Abre un
// modal corto (nombre, teléfono, correo y contacto), lo guarda con
// backend/guardar-cliente.php y lo deja seleccionado.
//
//   AppAltaRapida.cliente(input, { existe: (nombre) => bool, alCrear: (cliente) => {} })
//
// Solo se ofrece a quien tiene el módulo Clientes (el servidor lo vuelve a exigir).
// Usa los estilos de los diálogos (dialogo.js) y de los formularios del sistema.
window.AppAltaRapida = (function () {
    var BASE = /\/public\//.test(location.pathname) ? '../' : '';

    function el(tag, clase, texto) {
        var n = document.createElement(tag);
        if (clase) n.className = clase;
        if (texto != null) n.textContent = texto;
        return n;
    }

    function campo(etiqueta, nombre, tipo, extra) {
        var label = el('label', 'alta-campo');
        label.appendChild(el('span', '', etiqueta));
        var input = el('input');
        input.name = nombre;
        input.type = tipo || 'text';
        Object.keys(extra || {}).forEach(function (k) { input.setAttribute(k, extra[k]); });
        label.appendChild(input);
        return label;
    }

    /** Modal del alta. Resuelve con { id, nombre, contacto } o null si se cancela. */
    function abrirModal(nombreInicial) {
        return new Promise(function (resolver) {
            var fondo = el('div', 'dialogo-fondo');
            var caja = el('form', 'dialogo dialogo--info alta-rapida');
            caja.setAttribute('role', 'dialog');
            caja.setAttribute('aria-modal', 'true');
            caja.noValidate = true;
            var titulo = el('h2', 'dialogo-titulo', 'Nuevo cliente');
            titulo.id = 'alta-titulo-' + Date.now();
            caja.setAttribute('aria-labelledby', titulo.id);
            caja.appendChild(titulo);
            caja.appendChild(el('p', 'dialogo-nota', 'Lo esencial para registrarlo ahora. Los demás datos (RFC, dirección, más contactos) se completan después en Clientes.'));
            var cuerpo = el('div', 'alta-campos');
            cuerpo.appendChild(campo('Nombre del cliente *', 'nombre', 'text', { required: '', maxlength: '255', autocomplete: 'off' }));
            cuerpo.appendChild(campo('Contacto principal *', 'contacto', 'text', { required: '', maxlength: '150', placeholder: 'Quién reporta o recibe', autocomplete: 'off' }));
            cuerpo.appendChild(campo('Teléfono', 'telefono', 'tel', { maxlength: '30', autocomplete: 'off' }));
            cuerpo.appendChild(campo('Correo', 'email', 'email', { maxlength: '100', autocomplete: 'off' }));
            caja.appendChild(cuerpo);
            var error = el('p', 'alta-error');
            error.hidden = true;
            caja.appendChild(error);
            var acciones = el('div', 'dialogo-acciones');
            var cancelar = el('button', 'dialogo-btn dialogo-btn--secundario', 'Cancelar');
            cancelar.type = 'button';
            var guardar = el('button', 'dialogo-btn dialogo-btn--primario', 'Guardar cliente');
            guardar.type = 'submit';
            acciones.append(cancelar, guardar);
            caja.appendChild(acciones);
            fondo.appendChild(caja);

            var f = caja.elements;
            f.nombre.value = nombreInicial || '';
            var focoPrevio = document.activeElement;

            function cerrar(valor) {
                document.removeEventListener('keydown', teclado, true);
                fondo.remove();
                document.body.classList.remove('dialogo-abierto');
                if (focoPrevio && focoPrevio.focus) focoPrevio.focus();
                resolver(valor);
            }
            // Como todos los modales de captura: Esc o Cancelar, nunca un clic fuera
            function teclado(e) {
                if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); cerrar(null); }
            }
            function mostrarError(texto) {
                error.textContent = texto;
                error.hidden = !texto;
            }
            cancelar.addEventListener('click', function () { cerrar(null); });
            caja.addEventListener('submit', function (e) {
                e.preventDefault();
                var nombre = f.nombre.value.trim(), contacto = f.contacto.value.trim();
                if (!nombre) { mostrarError('Escribe el nombre del cliente.'); f.nombre.focus(); return; }
                if (!contacto) { mostrarError('Escribe el nombre del contacto principal.'); f.contacto.focus(); return; }
                if (f.email.value && !f.email.checkValidity()) { mostrarError('El correo no es válido.'); f.email.focus(); return; }
                mostrarError('');
                guardar.disabled = true;
                guardar.textContent = 'Guardando…';
                var datos = new FormData();
                datos.append('nombre', nombre);
                datos.append('telefono', f.telefono.value.trim());
                datos.append('email', f.email.value.trim());
                datos.append('contactos_json', JSON.stringify([{ nombre: contacto, telefono: f.telefono.value.trim(), email: f.email.value.trim() }]));
                fetch(BASE + 'backend/guardar-cliente.php', { method: 'POST', body: datos, credentials: 'same-origin' })
                    .then(function (r) { return r.json().catch(function () { return {}; }).then(function (j) { return { ok: r.ok, j: j }; }); })
                    .then(function (res) {
                        if (!res.ok || !res.j.success) throw new Error(res.j.message || res.j.error || 'No se pudo guardar el cliente.');
                        cerrar({ id: res.j.id, nombre: res.j.nombre || nombre, contacto: contacto });
                    })
                    .catch(function (err) {
                        mostrarError(err.message);
                        guardar.disabled = false;
                        guardar.textContent = 'Guardar cliente';
                    });
            });

            document.addEventListener('keydown', teclado, true);
            document.body.appendChild(fondo);
            document.body.classList.add('dialogo-abierto');
            (nombreInicial ? f.contacto : f.nombre).focus();
        });
    }

    function cliente(input, opciones) {
        if (!input || input._altaRapida) return;
        input._altaRapida = true;
        opciones = opciones || {};
        var barra = el('div', 'alta-rapida-barra');
        barra.hidden = true;
        var aviso = el('span', 'alta-rapida-aviso');
        aviso.hidden = true; // el formulario ya explica que el cliente no existe; aquí basta el botón
        var boton = el('button', 'alta-rapida-btn');
        boton.type = 'button';
        boton.innerHTML = '<i class="fas fa-plus-circle" aria-hidden="true"></i> <span>Nuevo cliente</span>';
        barra.append(aviso, boton);
        input.insertAdjacentElement('afterend', barra);

        function revisar() {
            var valor = input.value.trim();
            var noExiste = valor && opciones.existe && !opciones.existe(valor);
            aviso.textContent = noExiste ? '«' + valor + '» no está en tus clientes.' : '';
            boton.querySelector('span').textContent = noExiste ? 'Crear cliente' : 'Nuevo cliente';
        }
        input.addEventListener('input', function () { setTimeout(revisar, 0); });
        input.addEventListener('change', function () { setTimeout(revisar, 0); });

        // Sin esto, al pulsar el botón el campo pierde el foco, el formulario
        // muestra su mensaje de error y el botón se desplaza antes del clic
        boton.addEventListener('mousedown', function (e) { e.preventDefault(); });
        boton.addEventListener('click', function () {
            var valor = input.value.trim();
            abrirModal(opciones.existe && valor && !opciones.existe(valor) ? valor : '').then(function (nuevo) {
                if (!nuevo) return;
                if (opciones.alCrear) opciones.alCrear(nuevo);
                input.value = nuevo.nombre;
                input.setCustomValidity('');
                input.dispatchEvent(new Event('input', { bubbles: true }));
                input.dispatchEvent(new Event('change', { bubbles: true }));
                if (window.AppAviso) AppAviso.mostrar('Cliente «' + nuevo.nombre + '» registrado y seleccionado.', 'ok');
            });
        });

        var mostrar = function (estado) {
            barra.hidden = !(estado.modulos || []).includes('clientes');
            revisar();
        };
        if (window.appOnAuthReady) window.appOnAuthReady(mostrar); else barra.hidden = false;
    }

    return { cliente: cliente, abrirModal: abrirModal };
})();
