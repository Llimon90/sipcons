// Técnicos asignables desde la BD (usuarios con rol técnico, supervisor o
// administrador), compartido por alta, detalle y reportes. La lista se pide
// una sola vez por página.
//
// Si una incidencia tiene guardado un técnico que ya no está en la lista
// (usuario dado de baja, cambio de rol, nombre histórico), se agrega como
// opción "(no registrado)" para no borrarlo sin querer al guardar.
window.SipconsTecnicos = (function () {
    let promesa = null;

    function escapar(valor) {
        return String(valor ?? '')
            .replace(/&/g, '&amp;')
            .replace(/"/g, '&quot;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;');
    }

    function cargar() {
        if (!promesa) {
            promesa = fetch('../backend/obtener_tecnicos.php', { cache: 'no-store' })
                .then(r => r.json())
                .then(json => (json && json.success && Array.isArray(json.data)) ? json.data : [])
                .catch(err => {
                    console.error('No se pudo cargar la lista de técnicos:', err);
                    return [];
                });
        }
        return promesa;
    }

    // HTML de <option>s. `seleccionado` es el valor guardado en la incidencia.
    function opcionesHTML(lista, seleccionado, placeholder) {
        const actual = String(seleccionado ?? '').trim();
        const coincide = t => t.valor.toLowerCase() === actual.toLowerCase();
        let html = `<option value="">${escapar(placeholder)}</option>`;

        if (actual && !lista.some(coincide)) {
            html += `<option value="${escapar(actual)}" selected>${escapar(actual)} (no registrado)</option>`;
        }
        lista.forEach(t => {
            const sel = actual && coincide(t) ? ' selected' : '';
            html += `<option value="${escapar(t.valor)}"${sel}>${escapar(t.nombre)}</option>`;
        });
        return html;
    }

    async function llenarSelect(select, { placeholder = 'Seleccione una opción', seleccionado = '' } = {}) {
        if (!select) return;
        const lista = await cargar();
        select.innerHTML = opcionesHTML(lista, seleccionado || select.value, placeholder);
    }

    return { cargar, opcionesHTML, llenarSelect };
})();
