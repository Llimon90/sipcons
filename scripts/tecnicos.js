// Técnicos asignables desde la BD (usuarios con rol técnico, supervisor o
// administrador), compartido por alta, detalle y reportes. La lista se pide
// una sola vez por página y se guarda en sessionStorage durante CACHE_TTL_MS,
// así que navegar entre incidencias/detalle/reportes no vuelve a pedirla.
// Un técnico recién dado de alta aparece, a más tardar, a los 10 minutos (o
// al iniciar sesión de nuevo: login.html y el cierre de sesión borran la caché).
//
// Si una incidencia tiene guardado un técnico que ya no está en la lista
// (usuario dado de baja, cambio de rol, nombre histórico), se agrega como
// opción "(sin usuario activo)" para no borrarlo sin querer al guardar.
window.SipconsTecnicos = (function () {
    let promesa = null;

    function escapar(valor) {
        return String(valor ?? '')
            .replace(/&/g, '&amp;')
            .replace(/"/g, '&quot;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;');
    }

    const CACHE_KEY = 'sipcons_tecnicos';
    const CACHE_TTL_MS = 10 * 60 * 1000;

    function leerCache() {
        try {
            const o = JSON.parse(sessionStorage.getItem(CACHE_KEY) || 'null');
            if (o && Array.isArray(o.data) && Date.now() - o.t < CACHE_TTL_MS) return o.data;
        } catch (e) { /* sin storage */ }
        return null;
    }

    function guardarCache(data) {
        try { sessionStorage.setItem(CACHE_KEY, JSON.stringify({ t: Date.now(), data })); } catch (e) { /* sin storage */ }
    }

    function cargar() {
        if (!promesa) {
            const enCache = leerCache();
            if (enCache) {
                promesa = Promise.resolve(enCache);
                return promesa;
            }
            promesa = fetch('../backend/obtener_tecnicos.php', { cache: 'no-store' })
                .then(r => r.json())
                .then(json => {
                    if (json && json.success && Array.isArray(json.data)) {
                        guardarCache(json.data); // solo se guarda una respuesta correcta
                        return json.data;
                    }
                    return [];
                })
                .catch(err => {
                    console.error('No se pudo cargar la lista de técnicos:', err);
                    promesa = null; // permitir reintentar en la siguiente llamada
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
            html += `<option value="${escapar(actual)}" selected>${escapar(actual)} (sin usuario activo)</option>`;
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
