// Peticiones de lectura con un reintento cuando el servidor está ocupado (503: la base
// de datos rechazó la conexión por límite de conexiones del hosting). Solo GET: una
// escritura nunca se repite sola.
//   AppRed.fetch(url, opciones) → igual que fetch()
window.AppRed = {
    fetch: function (url, opciones, intentos) {
        var metodo = String((opciones && opciones.method) || 'GET').toUpperCase();
        intentos = intentos == null ? 2 : intentos;
        return fetch(url, opciones).then(function (r) {
            if (r.status !== 503 || metodo !== 'GET' || intentos <= 0) return r;
            return new Promise(function (listo) { setTimeout(listo, 700 + Math.random() * 600); })
                .then(function () { return window.AppRed.fetch(url, opciones, intentos - 1); });
        });
    }
};
