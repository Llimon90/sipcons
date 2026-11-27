<?php
// Migración SIPCONS -> Servidesk: las rutas de archivos subidos deben llevar el
// segmento de empresa (uploads/e<ID>/..., manuales/e<ID>/...). El esquema viejo
// las guardaba sin empresa (../uploads/x.jpg), pero backend/archivo.php solo
// sirve uploads/e<ID>/... y da 404 a cualquier otra forma. Sin este ajuste, al
// migrar producción TODOS los adjuntos quedarían rotos (404). Bug encontrado el
// 2026-11-27 antes del cutover.

require_once __DIR__ . '/../database/tools/rutas_empresa.php';

grupo('Migración de rutas de archivos (empresa)');

prueba('Adjunto de orden de servicio recibe el segmento e<ID>', function () {
    igual('../uploads/e1/bascula2-slider.jpg',
        rutaConEmpresa('../uploads/bascula2-slider.jpg', 1));
});

prueba('Archivo de venta conserva su subcarpeta de cliente bajo e<ID>', function () {
    igual('../uploads/e1/ventas/DISTRIBUIDORA_EL_FLORIDO/VT-00002_x.png',
        rutaConEmpresa('../uploads/ventas/DISTRIBUIDORA_EL_FLORIDO/VT-00002_x.png', 1));
});

prueba('Documento/manual recibe el segmento e<ID> tras manuales/', function () {
    igual('manuales/e1/rhino/IPES/Manual_x.pdf',
        rutaConEmpresa('manuales/rhino/IPES/Manual_x.pdf', 1));
});

prueba('Respeta el número de empresa (no siempre e1)', function () {
    igual('../uploads/e7/foto.jpg', rutaConEmpresa('../uploads/foto.jpg', 7));
});

prueba('Idempotente: una ruta ya migrada no se vuelve a prefijar', function () {
    igual('../uploads/e1/ya-migrado.jpg',
        rutaConEmpresa('../uploads/e1/ya-migrado.jpg', 1));
    igual('manuales/e1/x.pdf', rutaConEmpresa('manuales/e1/x.pdf', 1));
});

prueba('Cadena vacía se queda vacía (archivo sin ruta)', function () {
    igual('', rutaConEmpresa('', 1));
});

prueba('No toca rutas que no son de uploads/ ni manuales/', function () {
    igual('otra/cosa/x.pdf', rutaConEmpresa('otra/cosa/x.pdf', 1));
});

// El SQL que usa la migración (REGEXP_REPLACE) debe dar EXACTAMENTE lo mismo que
// la versión PHP, para que no diverjan. Se comprueba contra la base real.
prueba('El SQL de migración coincide con la versión PHP', function () {
    global $pdo;
    $casos = [
        '../uploads/bascula2-slider.jpg',
        '../uploads/ventas/CLIENTE X/VT-1_a.png',
        'manuales/rhino/IPES/m.pdf',
        '../uploads/e1/ya.jpg',
        '',
        'otra/cosa.pdf',
    ];
    foreach ($casos as $ruta) {
        $st = $pdo->prepare('SELECT ' . sqlRutaConEmpresa('?', 1));
        $st->execute([$ruta]);
        igual(rutaConEmpresa($ruta, 1), (string)$st->fetchColumn(), "SQL vs PHP para: $ruta");
    }
});
