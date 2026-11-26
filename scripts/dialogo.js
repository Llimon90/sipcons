// scripts/dialogo.js

/**
 * Modal de mensaje para resultados importantes (guardado correcto, error,
 * conflicto). A diferencia del aviso flotante, no desaparece solo: el
 * usuario tiene que leerlo y elegir. Estilos: "DIÁLOGOS" en styles.css.
 *
 *   AppDialogo.mostrar({
 *       tipo: 'ok' | 'error' | 'aviso' | 'info',
 *       titulo: 'Cambios guardados',
 *       mensaje: 'Texto principal',
 *       lista: ['línea opcional', ...],
 *       nota: 'Texto secundario opcional',
 *       contenido: nodoHTML,  // opcional: controles propios (p. ej. opciones a elegir)
 *       ancho: false,         // true: caja más ancha y texto alineado a la izquierda
 *       botones: [{ texto: 'Aceptar', valor: 'ok', estilo: 'primario' | 'peligro' | 'secundario' }],
 *       cerrable: true   // Esc / clic fuera devuelven null
 *   }).then(valor => ...)
 */
(function () {
    const ICONOS = {
        ok: 'fa-check-circle',
        error: 'fa-times-circle',
        aviso: 'fa-exclamation-triangle',
        info: 'fa-info-circle'
    };

    let abierto = null;

    function cerrar(valor) {
        if (!abierto) return;
        const { fondo, resolver, focoPrevio, alTeclado } = abierto;
        abierto = null;
        document.removeEventListener('keydown', alTeclado, true);
        fondo.remove();
        document.body.classList.remove('dialogo-abierto');
        if (focoPrevio && focoPrevio.focus) focoPrevio.focus();
        resolver(valor);
    }

    function mostrar(opciones) {
        if (abierto) cerrar(null);

        const o = Object.assign({ tipo: 'info', botones: [{ texto: 'Aceptar', valor: 'ok', estilo: 'primario' }], cerrable: true }, opciones);

        const fondo = document.createElement('div');
        fondo.className = 'dialogo-fondo';

        const caja = document.createElement('div');
        caja.className = `dialogo dialogo--${o.tipo}${o.ancho ? ' dialogo--ancho' : ''}`;
        caja.setAttribute('role', o.tipo === 'error' || o.tipo === 'aviso' ? 'alertdialog' : 'dialog');
        caja.setAttribute('aria-modal', 'true');
        caja.setAttribute('aria-labelledby', 'dialogo-titulo');
        caja.setAttribute('aria-describedby', 'dialogo-mensaje');

        const icono = document.createElement('i');
        icono.className = `fas ${ICONOS[o.tipo] || ICONOS.info} dialogo-icono`;
        icono.setAttribute('aria-hidden', 'true');
        caja.appendChild(icono);

        const titulo = document.createElement('h2');
        titulo.id = 'dialogo-titulo';
        titulo.className = 'dialogo-titulo';
        titulo.textContent = o.titulo || '';
        caja.appendChild(titulo);

        const mensaje = document.createElement('p');
        mensaje.id = 'dialogo-mensaje';
        mensaje.className = 'dialogo-mensaje';
        mensaje.textContent = o.mensaje || '';
        caja.appendChild(mensaje);

        if (o.lista && o.lista.length) {
            const ul = document.createElement('ul');
            ul.className = 'dialogo-lista';
            o.lista.forEach(texto => {
                const li = document.createElement('li');
                li.textContent = texto;
                ul.appendChild(li);
            });
            caja.appendChild(ul);
        }

        if (o.nota) {
            const nota = document.createElement('p');
            nota.className = 'dialogo-nota';
            nota.textContent = o.nota;
            caja.appendChild(nota);
        }

        if (o.contenido) {
            const contenedor = document.createElement('div');
            contenedor.className = 'dialogo-contenido';
            contenedor.appendChild(o.contenido);
            caja.appendChild(contenedor);
        }

        const acciones = document.createElement('div');
        acciones.className = 'dialogo-acciones';
        o.botones.forEach(b => {
            const boton = document.createElement('button');
            boton.type = 'button';
            boton.className = `dialogo-btn dialogo-btn--${b.estilo || 'secundario'}`;
            boton.textContent = b.texto;
            boton.addEventListener('click', () => cerrar(b.valor));
            acciones.appendChild(boton);
        });
        caja.appendChild(acciones);

        fondo.appendChild(caja);
        // Un clic fuera no cierra: solo Esc o los botones (no se pierde lo capturado)

        const alTeclado = (e) => {
            if (e.key === 'Escape' && o.cerrable) { e.preventDefault(); cerrar(null); return; }
            if (e.key === 'Tab') {
                const botones = Array.from(caja.querySelectorAll('button, input, select, textarea, a[href]'))
                    .filter(n => !n.disabled && n.offsetParent !== null);
                const i = botones.indexOf(document.activeElement);
                if (e.shiftKey && i <= 0) { e.preventDefault(); botones[botones.length - 1].focus(); }
                else if (!e.shiftKey && i === botones.length - 1) { e.preventDefault(); botones[0].focus(); }
            }
        };

        return new Promise(resolver => {
            abierto = { fondo, resolver, focoPrevio: document.activeElement, alTeclado };
            document.addEventListener('keydown', alTeclado, true);
            document.body.appendChild(fondo);
            document.body.classList.add('dialogo-abierto');
            const principal = acciones.querySelector('.dialogo-btn--primario') || acciones.querySelector('button');
            if (principal) principal.focus();
        });
    }

    window.AppDialogo = { mostrar, cerrar };

    // ------------------------------------------------------------------
    // AppAviso: el único estilo de notificación del sistema.
    //   AppAviso.mostrar('Cliente guardado', 'ok' | 'error' | 'aviso' | 'info')
    //       aviso flotante que se cierra solo (los errores duran más)
    //   AppAviso.alerta('texto')   igual, deduce el tipo del texto (reemplaza alert())
    //   AppAviso.confirmar('¿Eliminar…?', { titulo, boton, peligro }) -> Promise<boolean>
    //       (reemplaza confirm(): mismo diálogo en todo el sistema)
    // Si la página navega justo después (guardar y redirigir), el aviso se
    // muestra en la página siguiente.
    // ------------------------------------------------------------------
    const CLAVE_PENDIENTE = 'app_aviso_pendiente';
    let pila = null;

    function contenedor() {
        if (pila && document.body.contains(pila)) return pila;
        pila = document.createElement('div');
        pila.className = 'avisos';
        pila.setAttribute('aria-live', 'polite');
        pila.setAttribute('role', 'status');
        document.body.appendChild(pila);
        return pila;
    }

    function tipoDeTexto(texto) {
        const t = String(texto || '').toLowerCase();
        if (/error|no se pudo|no fue posible|fall[oó]|inv[aá]lid|incorrect|denegad|no tienes|no puedes|no existe|no se encontr/.test(t)) return 'error';
        if (/obligatori|debes|debe |selecciona|completa|falta|ingresa|escribe|duplicad|ya existe|atenci[oó]n/.test(t)) return 'aviso';
        if (/correctamente|guardad|eliminad|registrad|actualizad|cread|agregad|subid|[eé]xito|listo/.test(t)) return 'ok';
        return 'info';
    }

    // Avisos recién mostrados: si la página se va antes de que se alcancen a
    // leer (guardar y redirigir), se vuelven a mostrar en la siguiente.
    let ultimo = null;
    window.addEventListener('pagehide', () => {
        if (!ultimo || Date.now() - ultimo.t > 1500) return;
        try { sessionStorage.setItem(CLAVE_PENDIENTE, JSON.stringify(ultimo)); } catch (e) { /* sin storage */ }
    });

    function mostrarAviso(texto, tipo) {
        tipo = ICONOS[tipo] ? tipo : (tipo === 'success' ? 'ok' : tipo === 'warning' ? 'aviso' : 'info');
        ultimo = { texto: String(texto), tipo, t: Date.now() };
        const pintar = () => {
            const a = document.createElement('div');
            a.className = 'aviso aviso--' + tipo;
            const icono = document.createElement('i');
            icono.className = 'fas ' + ICONOS[tipo];
            icono.setAttribute('aria-hidden', 'true');
            const cuerpo = document.createElement('div');
            cuerpo.className = 'aviso-texto';
            cuerpo.textContent = String(texto);
            const x = document.createElement('button');
            x.type = 'button';
            x.className = 'aviso-cerrar';
            x.setAttribute('aria-label', 'Cerrar aviso');
            x.innerHTML = '&times;';
            a.append(icono, cuerpo, x);
            const quitar = () => {
                a.classList.add('saliendo');
                setTimeout(() => a.remove(), 200);
            };
            x.addEventListener('click', quitar);
            setTimeout(quitar, tipo === 'error' ? 7000 : tipo === 'aviso' ? 5500 : 4000);
            contenedor().appendChild(a);
        };
        if (document.body) pintar(); else document.addEventListener('DOMContentLoaded', pintar);
    }

    function confirmarAviso(texto, o = {}) {
        return mostrar({
            tipo: o.peligro ? 'aviso' : 'info',
            titulo: o.titulo || (o.peligro ? '¿Seguro que quieres continuar?' : 'Confirmar'),
            mensaje: texto,
            nota: o.nota || '',
            botones: [
                { texto: o.cancelar || 'Cancelar', valor: false, estilo: 'secundario' },
                { texto: o.boton || 'Aceptar', valor: true, estilo: o.peligro ? 'peligro' : 'primario' }
            ]
        }).then(v => v === true);
    }

    window.AppAviso = {
        mostrar: mostrarAviso,
        alerta: (texto) => mostrarAviso(texto, tipoDeTexto(texto)),
        confirmar: confirmarAviso
    };

    // Aviso que quedó pendiente porque la página anterior navegó enseguida
    try {
        const p = JSON.parse(sessionStorage.getItem(CLAVE_PENDIENTE) || 'null');
        sessionStorage.removeItem(CLAVE_PENDIENTE);
        if (p && Date.now() - p.t < 15000) mostrarAviso(p.texto, p.tipo);
        ultimo = null;
    } catch (e) { /* sin storage */ }
})();
