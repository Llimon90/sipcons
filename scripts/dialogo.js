// scripts/dialogo.js

/**
 * Modal de mensaje para resultados importantes (guardado correcto, error,
 * conflicto). A diferencia del aviso flotante, no desaparece solo: el
 * usuario tiene que leerlo y elegir. Estilos: "DIÁLOGOS" en styles.css.
 *
 *   SipconsDialogo.mostrar({
 *       tipo: 'ok' | 'error' | 'aviso' | 'info',
 *       titulo: 'Cambios guardados',
 *       mensaje: 'Texto principal',
 *       lista: ['línea opcional', ...],
 *       nota: 'Texto secundario opcional',
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
        caja.className = `dialogo dialogo--${o.tipo}`;
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
        if (o.cerrable) {
            fondo.addEventListener('click', (e) => { if (e.target === fondo) cerrar(null); });
        }

        const alTeclado = (e) => {
            if (e.key === 'Escape' && o.cerrable) { e.preventDefault(); cerrar(null); return; }
            if (e.key === 'Tab') {
                const botones = Array.from(acciones.querySelectorAll('button'));
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

    window.SipconsDialogo = { mostrar, cerrar };
})();
