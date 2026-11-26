<?php
// Lógica de la interfaz que vive en JavaScript sin depender del DOM. Se ejecuta con Node
// (si no está instalado, las pruebas se marcan como fallidas para que no pasen en silencio).

/** Ejecuta una expresión JS con el módulo cargado como `m` y devuelve su resultado decodificado. */
function js(string $modulo, string $expresion) {
    $ruta = str_replace('\\', '/', realpath(__DIR__ . '/../scripts/' . $modulo));
    $codigo = 'const m = require(' . json_encode($ruta) . '); process.stdout.write(JSON.stringify(' . $expresion . '));';
    $archivo = tempnam(sys_get_temp_dir(), 'js') . '.js';
    file_put_contents($archivo, $codigo);
    $salida = shell_exec('node ' . escapeshellarg($archivo) . ' 2>&1');
    @unlink($archivo);
    $valor = json_decode((string)$salida, true);
    if ($valor === null && trim((string)$salida) !== 'null') throw new RuntimeException('Node: ' . mb_substr((string)$salida, 0, 300));
    return $valor;
}

grupo('Aviso de cambios sin guardar en la orden');

prueba('Bug: después de guardar, un campo que no puede mostrar el valor del servidor no deja el aviso de "cambios sin guardar"', function () {
    // La categoría guardada no está en la lista: el select muestra ''. Antes el punto de
    // comparación era el valor del servidor y el aviso salía siempre al irse a Inicio.
    $r = js('cambios-formulario.js', 'm.lineaBaseTrasGuardar(
        ["categoria", "notas"],
        { categoria: "General", notas: "ok", tecnicos: ["Ana"] },
        { categoria: "", notas: "ok", tecnicos: ["Ana"] },
        ["categoria", "notas"], true)');
    igual(['categoria' => '', 'notas' => 'ok', 'tecnicos' => ['Ana']], $r, 'punto de comparación:');
});

prueba('C-H13: lo que se escribió mientras se guardaba sigue contando como cambio', function () {
    $r = js('cambios-formulario.js', 'm.lineaBaseTrasGuardar(
        ["categoria", "notas"],
        { categoria: "General", notas: "guardado", tecnicos: ["Ana"] },
        { categoria: "General", notas: "escrito después", tecnicos: ["Ana", "Luis"] },
        ["categoria"], false)');
    igual(['categoria' => 'General', 'notas' => 'guardado', 'tecnicos' => ['Ana']], $r, 'punto de comparación:');
});
