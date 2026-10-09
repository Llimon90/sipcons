// scripts/conexion.js

/**
 * Aviso de "sin conexión a internet" en todo el sistema (lo carga
 * auth-guard.js en todas las páginas). Estilos: "SIN CONEXIÓN" en styles.css.
 *
 *  - Muestra una barra fija mientras no hay conexión y un aviso breve cuando
 *    vuelve.
 *  - El navegador puede creer que hay conexión cuando la red no tiene
 *    internet; por eso, cuando una petición falla por red, se comprueba de
 *    verdad pidiendo un archivo pequeño del propio servidor.
 *  - Mientras no hay conexión, cualquier envío de formulario o de datos
 *    (POST, PUT, DELETE...) se detiene ANTES de salir y se avisa en un modal.
 *    Así la página no recarga ni borra lo capturado.
 */
(function () {
    'use strict';
    if (window.SipconsConexion) return;

    var enPublic = /\/public\//.test(location.pathname);
    var RAIZ = enPublic ? '../' : '';
    var URL_PRUEBA = RAIZ + 'img/favicon-32.png';
    var REVISAR_CADA_MS = 8000;

    var sinConexion = false;
    var temporizador = null;
    var barra = null;
    var avisoAbierto = false;
    var fetchOriginal = window.fetch ? window.fetch.bind(window) : null;

    // ---------- comprobación real ----------
    function comprobar() {
        if (!fetchOriginal) return Promise.resolve(navigator.onLine !== false);
        if (navigator.onLine === false) return Promise.resolve(false);
        var control = window.AbortController ? new AbortController() : null;
        var limite = setTimeout(function () { if (control) control.abort(); }, 6000);
        return fetchOriginal(URL_PRUEBA + '?conexion=' + Date.now(), {
            method: 'HEAD',
            cache: 'no-store',
            credentials: 'same-origin',
            signal: control ? control.signal : undefined
        }).then(function () { return true; }, function () { return false; })
          .then(function (hay) { clearTimeout(limite); return hay; });
    }

    function revisarAhora() {
        return comprobar().then(function (hay) {
            establecer(!hay);
            return hay;
        });
    }

    // ---------- barra ----------
    function crearBarra() {
        if (barra) return barra;
        barra = document.createElement('div');
        barra.className = 'aviso-conexion';
        barra.setAttribute('role', 'alert');
        barra.innerHTML =
            '<i class="fas fa-wifi" aria-hidden="true"></i>' +
            '<span class="aviso-conexion-texto"></span>' +
            '<button type="button" class="aviso-conexion-btn">Reintentar</button>';
        barra.querySelector('.aviso-conexion-btn').addEventListener('click', function () {
            var boton = this;
            boton.disabled = true;
            boton.textContent = 'Revisando…';
            revisarAhora().then(function () {
                boton.disabled = false;
                boton.textContent = 'Reintentar';
            });
        });
        (document.body || document.documentElement).appendChild(barra);
        return barra;
    }

    function mostrarBarra(estado) {
        var b = crearBarra();
        b.classList.remove('aviso-conexion--ok', 'visible');
        if (estado === 'sin') {
            b.querySelector('.aviso-conexion-texto').textContent =
                'Estás sin conexión a internet. No podrás guardar ni registrar hasta que vuelva.';
            b.querySelector('.aviso-conexion-btn').hidden = false;
            b.classList.add('visible');
            document.body.classList.add('con-aviso-conexion');
        } else {
            b.querySelector('.aviso-conexion-texto').textContent = 'Se restableció la conexión a internet.';
            b.querySelector('.aviso-conexion-btn').hidden = true;
            b.classList.add('aviso-conexion--ok', 'visible');
            setTimeout(function () {
                if (!sinConexion) {
                    b.classList.remove('visible');
                    document.body.classList.remove('con-aviso-conexion');
                }
            }, 4000);
        }
    }

    function establecer(estaSinConexion) {
        if (estaSinConexion === sinConexion) return;
        sinConexion = estaSinConexion;
        aplicarEstado();
    }

    function aplicarEstado() {
        // El script se carga desde <head>: si aún no hay <body>, se espera
        if (!document.body) {
            document.addEventListener('DOMContentLoaded', aplicarEstado, { once: true });
            return;
        }
        if (sinConexion) {
            mostrarBarra('sin');
            registrar('Sin conexión');
            // Mientras tanto se revisa cada pocos segundos para avisar cuando vuelva
            clearInterval(temporizador);
            temporizador = setInterval(revisarAhora, REVISAR_CADA_MS);
        } else {
            clearInterval(temporizador);
            mostrarBarra('ok');
            registrar('Conexión restablecida');
        }
        try { window.dispatchEvent(new CustomEvent('sipcons-conexion', { detail: { conectado: !sinConexion } })); } catch (e) { /* nada */ }
    }

    function registrar(texto) {
        try {
            // Al volver la conexión se envía junto con lo demás que estaba en cola
            if (window.sipconsTrack) window.sipconsTrack('error_js', texto, 'conexion');
        } catch (e) { /* nada */ }
    }

    // ---------- modal al intentar enviar sin conexión ----------
    function cargarDialogo() {
        if (window.SipconsDialogo) return Promise.resolve(window.SipconsDialogo);
        return new Promise(function (resolver) {
            var s = document.createElement('script');
            s.src = RAIZ + 'scripts/dialogo.js?v=2';
            s.onload = function () { resolver(window.SipconsDialogo || null); };
            s.onerror = function () { resolver(null); };
            document.head.appendChild(s);
        });
    }

    function avisarEnvioBloqueado() {
        if (avisoAbierto) return;
        avisoAbierto = true;
        // Un instante después, para quedar encima del mensaje de error que
        // cada página muestra cuando su envío falla
        setTimeout(mostrarAvisoEnvio, 80);
    }

    function mostrarAvisoEnvio() {
        var mensaje = 'No se envió la información porque no hay conexión a internet. ' +
            'Lo que capturaste sigue en pantalla: cuando vuelva la conexión, inténtalo de nuevo.';
        cargarDialogo().then(function (dialogo) {
            if (!dialogo) {
                alert('Estás sin conexión a internet.\n\n' + mensaje);
                avisoAbierto = false;
                return;
            }
            return dialogo.mostrar({
                tipo: 'aviso',
                titulo: 'Estás sin conexión a internet',
                mensaje: mensaje,
                botones: [
                    { texto: 'Revisar conexión', valor: 'revisar', estilo: 'secundario' },
                    { texto: 'Entendido', valor: 'ok', estilo: 'primario' }
                ]
            }).then(function (eleccion) {
                avisoAbierto = false;
                if (eleccion === 'revisar') {
                    revisarAhora().then(function (hay) {
                        if (hay) {
                            dialogo.mostrar({
                                tipo: 'ok',
                                titulo: 'La conexión volvió',
                                mensaje: 'Ya puedes intentar guardar de nuevo.',
                                botones: [{ texto: 'Aceptar', valor: 'ok', estilo: 'primario' }]
                            });
                        }
                    });
                }
            });
        });
    }

    // Formularios: se detiene el envío antes de que lo procese la página
    document.addEventListener('submit', function (e) {
        if (!sinConexion && navigator.onLine !== false) return;
        establecer(true);
        e.preventDefault();
        e.stopImmediatePropagation();
        avisarEnvioBloqueado();
    }, true);

    // Peticiones: los envíos de datos sin conexión no salen; las lecturas fallidas
    // por red disparan una comprobación real de la conexión.
    if (fetchOriginal) {
        window.fetch = function (recurso, opciones) {
            var metodo = String((opciones && opciones.method) || (recurso && recurso.method) || 'GET').toUpperCase();
            var url = typeof recurso === 'string' ? recurso : (recurso && recurso.url) || '';
            var esEnvio = metodo !== 'GET' && metodo !== 'HEAD' && !/analytics_registrar/.test(url);

            if (esEnvio && (sinConexion || navigator.onLine === false)) {
                establecer(true);
                avisarEnvioBloqueado();
                return Promise.reject(new TypeError('Sin conexión a internet'));
            }

            var promesa = fetchOriginal.apply(window, arguments);
            promesa.then(function () {
                if (sinConexion) establecer(false);
            }, function (error) {
                // Cancelaciones a propósito (AbortController) no son falta de conexión
                if (error && error.name === 'AbortError') return;
                revisarAhora().then(function (hay) {
                    if (!hay && esEnvio) avisarEnvioBloqueado();
                });
            });
            return promesa;
        };
    }

    // ---------- eventos del navegador ----------
    window.addEventListener('offline', function () { establecer(true); });
    window.addEventListener('online', function () { revisarAhora(); });
    document.addEventListener('visibilitychange', function () {
        if (document.visibilityState === 'visible' && (sinConexion || navigator.onLine === false)) revisarAhora();
    });

    function iniciar() {
        if (navigator.onLine === false) establecer(true);
    }
    if (document.body) iniciar();
    else document.addEventListener('DOMContentLoaded', iniciar);

    window.SipconsConexion = {
        conectado: function () { return !sinConexion && navigator.onLine !== false; },
        revisar: revisarAhora
    };
})();
