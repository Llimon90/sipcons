<?php
// Auditoría integral 2026-11-24, bloque 5: seguridad y arreglos rápidos.
// F-1 fotos de perfil, S-1 error_log, S-2 sesión en contratar, S-4 clave de envío ajena,
// S-5 fórmulas en CSV, S-6 bloqueo de login por IP, S-8 display_errors, S-9 cotización ajena = 404.
require_once __DIR__ . '/../config/login_log.php';
require_once __DIR__ . '/../config/csv.php';
require_once __DIR__ . '/../backend/lib/cotizaciones_helpers.php';

[$empS, $adminS] = empresaTemporal('Seguridad 2');
$tecS = usuarioTemporal($empS, 'Técnico');
como($empS, $adminS, 'Administrador');

grupo('Seguridad y arreglos rápidos (auditoría integral, bloque 5)');

prueba('F-1 · D-46: la foto de perfil se puede ver dentro de la empresa', function () use ($empS, $tecS) {
    $dir = __DIR__ . "/../uploads/e$empS/usuarios";
    if (!is_dir($dir)) mkdir($dir, 0775, true);
    file_put_contents("$dir/foto-prueba.jpg", 'jpg');
    $r = llamar($empS, $tecS, 'Técnico', 'GET', 'backend/archivo.php', ['get' => ['ruta' => "uploads/e$empS/usuarios/foto-prueba.jpg"]]);
    @unlink("$dir/foto-prueba.jpg");
    igual(200, $r['http'], 'foto de perfil:');
});

prueba('S-1: el error_log de PHP no se sirve por web ni guarda el POST completo', function () {
    verdadero((bool)preg_match('/RewriteRule[^\n]*error_log/', (string)file_get_contents(__DIR__ . '/../.htaccess')), '.htaccess no bloquea error_log');
    verdadero(!str_contains((string)file_get_contents(__DIR__ . '/../backend/soporte_backend.php'), 'print_r($_POST'), 'soporte_backend registra el POST');
});

prueba('S-8: ningún endpoint enciende display_errors', function () {
    foreach (glob(__DIR__ . '/../backend/*.php') as $f) {
        verdadero(!preg_match("/ini_set\(\s*'display_errors'\s*,\s*'?1'?\s*\)/", (string)file_get_contents($f)), basename($f) . ' muestra errores');
    }
});

prueba('S-2: contratar.php rechaza una sesión de un usuario que ya no existe', function () use ($empS) {
    $r = llamar($empS, 999999, 'Administrador', 'GET', 'auth/contratar.php', ['get' => ['accion' => 'estado']]);
    igual(401, $r['http'], 'sesión de usuario eliminado: ' . substr($r['texto'], 0, 120));
});

prueba('S-4 · D-9: la clave de envío de una orden ajena no devuelve sus datos al Técnico', function () use ($empS, $tecS) {
    global $pdo;
    $clave = bin2hex(random_bytes(16));
    $pdo->prepare("INSERT INTO incidencias (numero_incidente, cliente, contacto, sucursal, fecha, categoria, estatus, falla, tecnico, clave_envio)
                   VALUES ('SG-4', 'Cliente secreto', 'Contacto secreto', '', CURDATE(), 'General', 'Abierto', 'x', 'Otro técnico', ?)")->execute([$clave]);
    $pdo->exec("INSERT INTO clientes (nombre, rfc, direccion, telefono, contactos, email) VALUES ('Cliente S4', '', '', '', 'A', '')");
    $r = llamar($empS, $tecS, 'Técnico', 'POST', 'backend/server.php', ['post' => ['clave_envio' => $clave, 'reporte_cliente' => '', 'cliente' => 'Cliente S4',
        'contacto' => 'A', 'sucursal' => '', 'fecha' => date('Y-m-d'), 'tecnico' => '', 'status' => 'Abierto', 'falla' => 'Revisión', 'notas' => '', 'categoria' => 'General']]);
    verdadero(!str_contains($r['texto'], 'Cliente secreto'), 'se filtró la orden ajena: ' . substr($r['texto'], 0, 160));
});

prueba('S-5: una celda que empieza con = + - @ se neutraliza en los CSV', function () {
    // (L2-5: un número como "-1" o "+1" no es fórmula y sigue siendo número)
    igual(["'=HYPERLINK(\"x\")", "'+A1", '-1', "'-A1", "'@a", 'normal', 12.5, ''], csvFilaSegura(['=HYPERLINK("x")', '+A1', '-1', '-A1', '@a', 'normal', 12.5, '']));
});

prueba('S-6: los fallos de una IP no bloquean el correo desde otra IP', function () {
    global $pdo;
    $correo = 'bloqueo-' . bin2hex(random_bytes(3)) . '@pruebas.test';
    $_SERVER['REMOTE_ADDR'] = '10.0.0.1';
    for ($i = 0; $i < LOGIN_MAX_FALLOS_USUARIO; $i++) loginRegistrarFallo($pdo, $correo);
    verdadero(loginMinutosBloqueo($pdo, $correo) > 0, 'la IP que falló debe quedar bloqueada');
    $_SERVER['REMOTE_ADDR'] = '10.0.0.2';
    igual(0, loginMinutosBloqueo($pdo, $correo), 'otra IP:');
    unset($_SERVER['REMOTE_ADDR']);
    $pdo->prepare('DELETE FROM login_intentos WHERE usuario = ?')->execute([$correo]);
});

prueba('S-9 · D-9: una cotización ajena responde 404 al Técnico, igual que una inexistente', function () use ($empS, $adminS, $tecS) {
    global $pdo;
    $pdo->exec("INSERT INTO clientes (nombre, rfc, direccion, telefono, contactos, email) VALUES ('Cliente S9', '', '', '', 'A', '')");
    $cli = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO inventario_articulos (sku, nombre, categoria, controla_existencia, unidad, existencia, costo_unitario, precio_venta) VALUES (?, 'Eq', 'Equipo', 1, 'pza', 5, 1, 100)")->execute(['S9' . bin2hex(random_bytes(3))]);
    $art = (int)$pdo->lastInsertId();
    $cot = cotGuardar($pdo, 0, ['cliente_id' => $cli, 'sucursal' => '', 'fecha' => date('Y-m-d'), 'vigencia_hasta' => date('Y-m-d', strtotime('+5 days')), 'moneda' => 'MXN', 'precios_incluyen_iva' => 0], [['articulo_id' => $art, 'cantidad' => 1]]);
    $r = llamar($empS, $tecS, 'Técnico', 'GET', 'backend/cotizaciones.php', ['get' => ['accion' => 'detalle', 'id' => $cot['id']]]);
    como($empS, $adminS, 'Administrador');
    igual(404, $r['http'], 'cotización ajena:');
});
