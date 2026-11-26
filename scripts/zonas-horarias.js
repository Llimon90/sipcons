// Desplegable de zonas horarias (Mi empresa y panel de plataforma).
// Lista IANA del navegador agrupada por región, con las más usadas por los
// clientes (México, Latinoamérica y España) al principio y la diferencia con
// UTC de hoy. El servidor vuelve a validar la zona (DateTimeZone).
window.AppZonas = (function () {
    var FRECUENTES = [
        'America/Mexico_City', 'America/Monterrey', 'America/Merida', 'America/Cancun', 'America/Chihuahua',
        'America/Ciudad_Juarez', 'America/Hermosillo', 'America/Mazatlan', 'America/Tijuana',
        'America/Bogota', 'America/Lima', 'America/Guatemala', 'America/Costa_Rica', 'America/Panama',
        'America/Santiago', 'America/Argentina/Buenos_Aires', 'America/Montevideo', 'America/Asuncion',
        'America/Caracas', 'America/La_Paz', 'America/Guayaquil', 'America/Santo_Domingo', 'America/Sao_Paulo',
        'America/New_York', 'America/Chicago', 'America/Denver', 'America/Los_Angeles',
        'Europe/Madrid', 'Atlantic/Canary'
    ];
    var REGIONES = {
        America: 'América', Europe: 'Europa', Atlantic: 'Atlántico', Africa: 'África', Asia: 'Asia',
        Australia: 'Australia', Pacific: 'Pacífico', Indian: 'Índico', Antarctica: 'Antártida', Arctic: 'Ártico', Etc: 'Otras'
    };
    var NOMBRES = {
        'America/Mexico_City': 'Ciudad de México', 'America/Merida': 'Mérida', 'America/Cancun': 'Cancún',
        'America/Ciudad_Juarez': 'Ciudad Juárez', 'America/Mazatlan': 'Mazatlán', 'America/Bogota': 'Bogotá',
        'America/Argentina/Buenos_Aires': 'Buenos Aires', 'America/Asuncion': 'Asunción', 'America/Sao_Paulo': 'São Paulo',
        'America/New_York': 'Nueva York', 'America/Los_Angeles': 'Los Ángeles', 'America/Panama': 'Panamá',
        'Atlantic/Canary': 'Canarias'
    };

    function esc(v) {
        return String(v == null ? '' : v).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    function todas() {
        try {
            if (Intl.supportedValuesOf) return Intl.supportedValuesOf('timeZone');
        } catch (e) { /* navegador antiguo */ }
        return FRECUENTES.slice();
    }

    function desfase(zona) {
        try {
            var partes = new Intl.DateTimeFormat('en-US', { timeZone: zona, timeZoneName: 'shortOffset' }).formatToParts(new Date());
            var tz = (partes.find(function (p) { return p.type === 'timeZoneName'; }) || {}).value || '';
            var m = tz.match(/GMT([+-])(\d{1,2})(?::(\d{2}))?/);
            if (!m) return 'UTC±00:00';
            return 'UTC' + (m[1] === '-' ? '−' : '+') + ('0' + m[2]).slice(-2) + ':' + (m[3] || '00');
        } catch (e) {
            return '';
        }
    }

    function nombre(zona) {
        if (NOMBRES[zona]) return NOMBRES[zona];
        var partes = zona.split('/');
        return partes.slice(1).join(' / ').replace(/_/g, ' ') || zona;
    }

    function opcion(zona, elegida) {
        var d = desfase(zona);
        return '<option value="' + esc(zona) + '"' + (zona === elegida ? ' selected' : '') + '>' +
            esc(nombre(zona) + (d ? ' (' + d + ')' : '')) + '</option>';
    }

    /** <option>/<optgroup> para un <select>, con la zona elegida marcada. */
    function opciones(elegida) {
        elegida = elegida || 'America/Mexico_City';
        var lista = todas();
        var disponibles = {};
        lista.forEach(function (z) { disponibles[z] = true; });
        if (!disponibles[elegida]) lista = lista.concat([elegida]);
        var html = '<optgroup label="Más usadas">' + FRECUENTES.filter(function (z) { return disponibles[z] || z === elegida; })
            .map(function (z) { return opcion(z, elegida); }).join('') + '</optgroup>';
        var grupos = {};
        lista.forEach(function (z) {
            if (FRECUENTES.indexOf(z) !== -1) return;
            var region = z.split('/')[0];
            (grupos[region] = grupos[region] || []).push(z);
        });
        Object.keys(grupos).sort().forEach(function (region) {
            html += '<optgroup label="' + esc(REGIONES[region] || region) + '">' +
                grupos[region].sort(function (a, b) { return nombre(a).localeCompare(nombre(b), 'es'); })
                    .map(function (z) { return opcion(z, elegida); }).join('') + '</optgroup>';
        });
        return html;
    }

    return { opciones: opciones, nombre: nombre };
})();
