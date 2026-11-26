// Cálculos del aviso "cambios sin guardar" que no dependen del DOM (probados en tests/frontend_test.php).
(function (raiz) {
    /**
     * Punto de comparación después de guardar.
     * - Campos que tomaron el valor del servidor: lo que MUESTRA el formulario. Un select sin
     *   esa opción (categoría, técnico) muestra otra cosa, y comparar contra el servidor dejaba
     *   el aviso de "cambios sin guardar" encendido para siempre.
     * - Campos que el usuario editó mientras se guardaba (C-H13): el valor del servidor, para
     *   que lo escrito siga contando como cambio.
     */
    function lineaBaseTrasGuardar(campos, servidor, pantalla, tomadosDelServidor, tecnicosDelServidor) {
        var base = {};
        campos.forEach(function (c) {
            base[c] = tomadosDelServidor.indexOf(c) !== -1 ? pantalla[c] : servidor[c];
        });
        base.tecnicos = (tecnicosDelServidor ? pantalla.tecnicos : servidor.tecnicos).slice();
        return base;
    }

    var api = { lineaBaseTrasGuardar: lineaBaseTrasGuardar };
    if (typeof module !== 'undefined' && module.exports) module.exports = api;
    else raiz.AppCambios = api;
})(typeof window !== 'undefined' ? window : this);
