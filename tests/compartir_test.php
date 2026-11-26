<?php
// Auditoría integral 2026-11-25, bloque 9: D-54 compartir por WhatsApp con enlace de solo
// lectura que caduca, «Exportar mis datos» y constancia de aceptación del registro.
require_once __DIR__ . '/../config/enlaces.php';
require_once __DIR__ . '/../backend/lib/cotizaciones_helpers.php';

[$empW, $adminW] = empresaTemporal('Compartir');
como($empW, $adminW, 'Administrador');
$pdo->exec("INSERT INTO clientes (nombre, rfc, direccion, telefono, contactos, email) VALUES ('Cliente W', '', '', '664 123 4567', 'A', '')");
$cliW = (int)$pdo->lastInsertId();

function cotizacionW(int $cli, bool $enviar = true): int {
    global $pdo;
    $pdo->prepare("INSERT INTO inventario_articulos (sku, nombre, categoria, controla_existencia, unidad, existencia, costo_unitario, precio_venta) VALUES (?, 'Equipo W', 'Equipo', 1, 'pza', 5, 1, 1000)")->execute(['W' . bin2hex(random_bytes(3))]);
    $art = (int)$pdo->lastInsertId();
    $cot = cotGuardar($pdo, 0, ['cliente_id' => $cli, 'sucursal' => '', 'fecha' => date('Y-m-d'), 'vigencia_hasta' => date('Y-m-d', strtotime('+10 days')), 'moneda' => 'MXN', 'precios_incluyen_iva' => 0], [['articulo_id' => $art, 'cantidad' => 1]]);
    if ($enviar) cotCambiarEstatus($pdo, (int)$cot['id'], 'enviada');
    return (int)$cot['id'];
}
function abrirCompartido(string $token): array {
    $f = tempnam(sys_get_temp_dir(), 'cw');
    file_put_contents($f, '<?php $_SERVER["REQUEST_METHOD"]="GET"; $_GET["t"]=' . var_export($token, true) . '; ob_start();'
        . ' register_shutdown_function(function(){ $b = ""; while (ob_get_level()) $b = ob_get_clean() . $b; echo base64_encode($b), "@@HTTP ".http_response_code(); }); require ' . var_export(realpath(__DIR__ . '/../backend/compartido.php'), true) . ';');
    $out = (string)shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($f) . ' 2>&1');
    @unlink($f);
    preg_match('/^([A-Za-z0-9+\/=]*)@@HTTP (\d{3})\s*$/', trim($out), $m);
    return ['http' => (int)($m[2] ?? 0), 'texto' => isset($m[1]) ? (string)base64_decode($m[1]) : $out];
}

grupo('Compartir y exportar (bloque 9)');

prueba('D-54: el enlace firmado abre solo ese documento, y uno alterado o caducado no sirve', function () use ($empW, $cliW) {
    global $pdo;
    $id = cotizacionW($cliW);
    $token = enlaceFirmar($pdo, 'cotizacion', $empW, $id);
    igual(['t' => 'cotizacion', 'e' => $empW, 'i' => $id], array_intersect_key(enlaceVerificar($pdo, $token), array_flip(['t', 'e', 'i'])));
    [$datos, $firma] = explode('.', $token);
    $otro = enlaceB64(json_encode(['t' => 'cotizacion', 'e' => $empW, 'i' => $id + 1, 'x' => time() + 999]));
    igual(null, enlaceVerificar($pdo, "$otro.$firma"), 'otro documento con la misma firma:');
    igual(null, enlaceVerificar($pdo, enlaceFirmar($pdo, 'cotizacion', $empW, $id, time() - 1)), 'caducado:');
    igual(null, enlaceVerificar($pdo, 'basura.' . str_repeat('A', 43)), 'basura:');
});

prueba('D-54: el enlace de una cotización enviada entrega el PDF sin sesión; uno inválido responde 404', function () use ($empW, $cliW) {
    global $pdo;
    $r = abrirCompartido(enlaceFirmar($pdo, 'cotizacion', $empW, cotizacionW($cliW)));
    igual(200, $r['http'], 'enlace válido: ' . substr($r['texto'], -400));
    verdadero(str_starts_with(ltrim($r['texto']), '%PDF'), 'no entregó un PDF: ' . substr($r['texto'], 0, 60));
    igual(404, abrirCompartido('no.valido')['http'], 'enlace inválido:');
});

prueba('D-54: un borrador no se comparte; el estado de cuenta compartido muestra el saldo', function () use ($empW, $adminW, $cliW) {
    global $pdo;
    $borrador = cotizacionW($cliW, false);
    $r = llamar($empW, $adminW, 'Administrador', 'GET', 'backend/cotizaciones.php', ['get' => ['accion' => 'enlace', 'id' => $borrador]]);
    verdadero($r['http'] >= 400, 'compartió un borrador');
    $r = llamar($empW, $adminW, 'Administrador', 'GET', 'backend/cotizaciones.php', ['get' => ['accion' => 'enlace', 'id' => cotizacionW($cliW)]]);
    verdadero(str_starts_with((string)($r['json']['whatsapp'] ?? ''), 'https://wa.me/526641234567?text='), 'WhatsApp al teléfono del cliente: ' . substr($r['texto'], 0, 160));
    require_once __DIR__ . '/../backend/lib/finanzas_helpers.php';
    como($empW, $adminW, 'Administrador');
    finCrearCuenta($pdo, ['cliente_id' => $cliW, 'concepto' => 'Servicio W', 'subtotal' => 1000, 'iva' => 160]);
    $html = abrirCompartido(enlaceFirmar($pdo, 'estado_cuenta', $empW, $cliW));
    igual(200, $html['http'], 'estado de cuenta:');
    verdadero(str_contains($html['texto'], '$1,160.00'), 'sin el saldo');
});

prueba('D-54: una empresa suspendida ya no sirve sus enlaces', function () use ($cliW) {
    global $pdo;
    [$emp] = empresaTemporal('Compartir suspendida');
    $token = enlaceFirmar($pdo, 'estado_cuenta', $emp, $cliW);
    $pdo->prepare("UPDATE empresas SET estado = 'suspendida' WHERE id = ?")->execute([$emp]);
    igual(404, abrirCompartido($token)['http']);
});

prueba('Exportar mis datos: ZIP con un CSV por tabla y usuarios sin contraseñas', function () use ($empW, $adminW) {
    $f = tempnam(sys_get_temp_dir(), 'zx');
    $r = llamar($empW, $adminW, 'Administrador', 'GET', 'backend/mi_empresa.php', ['get' => ['accion' => 'exportar'], 'sesion' => ['__binario' => 1]]);
    file_put_contents($f, base64_decode(substr(trim($r['texto']), strpos($r['texto'], '@@B64 ') + 6)));
    $zip = new ZipArchive();
    verdadero($zip->open($f) === true, 'no es un ZIP: ' . substr($r['texto'], 0, 80));
    verdadero($zip->locateName('clientes.csv') !== false && $zip->locateName('usuarios.csv') !== false, 'faltan CSV');
    $usuarios = (string)$zip->getFromName('usuarios.csv');
    verdadero(!str_contains($usuarios, 'password') && !str_contains($usuarios, '$2y$'), 'el CSV de usuarios trae contraseñas');
    verdadero(str_contains((string)$zip->getFromName('clientes.csv'), 'Cliente W'), 'sin los clientes');
    $zip->close();
    @unlink($f);
    $tec = usuarioTemporal($empW, 'Técnico');
    igual(403, llamar($empW, $tec, 'Técnico', 'GET', 'backend/mi_empresa.php', ['get' => ['accion' => 'exportar']])['http'], 'un Técnico exporta:');
});
