<?php
// Backlog P1 (docs/BACKLOG_PRELANZAMIENTO.md): O-13 moneda con partidas, O-14 textos
// internos, O-15 confirmación al enviar solo la primera vez.
require_once __DIR__ . '/../backend/lib/cotizaciones_helpers.php';
require_once __DIR__ . '/../backend/lib/inventario_helpers.php';

[$empP, $adminP] = empresaTemporal('P1');
como($empP, $adminP, 'Administrador');
clienteCatalogo('Cliente P1');
$cliP = (int)$pdo->query("SELECT id FROM clientes WHERE nombre = 'Cliente P1'")->fetchColumn();
$pdo->prepare("INSERT INTO inventario_articulos (sku, nombre, categoria, controla_existencia, unidad, existencia, costo_unitario, precio_venta)
               VALUES ('P1-EQ', 'Equipo', 'Equipo', 1, 'pza', 5, 600, 1000)")->execute();
$artP = (int)$pdo->lastInsertId();

function encP(int $cli, array $extra = []): array {
    return $extra + ['cliente_id' => $cli, 'fecha' => date('Y-m-d'), 'vigencia_hasta' => date('Y-m-d', strtotime('+15 days')), 'moneda' => 'MXN'];
}

grupo('Moneda con partidas (O-13)');

prueba('O-13: cambiar la moneda sin confirmar la conversión se rechaza y no cambia nada', function () use ($cliP, $artP) {
    global $pdo;
    $cot = cotGuardar($pdo, 0, encP($cliP), [['articulo_id' => $artP, 'cantidad' => 1]]);
    $partidas = array_map(fn($p) => ['id' => $p['id'], 'cantidad' => $p['cantidad'], 'precio_unitario' => $p['precio_unitario']], $cot['partidas']);
    lanza(fn() => cotGuardar($pdo, (int)$cot['id'], encP($cliP, ['moneda' => 'USD', 'tipo_cambio' => 20]), $partidas), CotizacionException::class);
    igual('MXN', $pdo->query('SELECT moneda FROM cotizaciones WHERE id = ' . (int)$cot['id'])->fetchColumn(), 'moneda:');
});

prueba('O-13: con confirmación, las partidas se convierten al tipo de cambio indicado (y de regreso)', function () use ($cliP, $artP) {
    global $pdo;
    $cot = cotGuardar($pdo, 0, encP($cliP), [['articulo_id' => $artP, 'cantidad' => 2]]);
    $p = $cot['partidas'][0];
    $usd = cotGuardar($pdo, (int)$cot['id'], encP($cliP, ['moneda' => 'USD', 'tipo_cambio' => 20, 'convertir_moneda' => 1]),
        [['id' => $p['id'], 'cantidad' => 2, 'precio_unitario' => $p['precio_unitario']]]);
    igual(50.0, (float)$usd['partidas'][0]['precio_unitario'], 'precio en USD (1000 / 20):');
    igual(30.0, (float)$usd['partidas'][0]['costo_unitario'], 'costo en USD (600 / 20):');
    $mxn = cotGuardar($pdo, (int)$cot['id'], encP($cliP, ['moneda' => 'MXN', 'convertir_moneda' => 1]),
        [['id' => $p['id'], 'cantidad' => 2, 'precio_unitario' => 50]]);
    igual(1000.0, (float)$mxn['partidas'][0]['precio_unitario'], 'precio de regreso en MXN (50 × 20):');
});

grupo('Textos internos (O-14)');

prueba('O-14: la cotización desde una orden no llena las notas internas con textos del sistema', function () use ($artP) {
    global $pdo;
    $pdo->prepare("INSERT INTO incidencias (numero_incidente, cliente, contacto, fecha, categoria, estatus, falla, tecnico) VALUES (?, 'Cliente P1', 'x', CURDATE(), 'General', 'Completado', 'x', '')")
        ->execute(['P1-' . bin2hex(random_bytes(3))]);
    $orden = (int)$pdo->lastInsertId();
    $pdo->beginTransaction();
    invRegistrarMovimiento($pdo, $artP, -1, 'salida', 'Consumo', 'incidencia', $orden, 'P1');
    $pdo->commit();
    $a = cotCrearDesdeIncidencia($pdo, $orden);
    $b = cotCrearDesdeIncidencia($pdo, $orden, true);
    igual('', trim((string)$a['notas_internas']), 'notas internas (en blanco):');
    igual('', trim((string)$b['notas_internas']), 'notas internas (posterior al servicio):');
});

grupo('Confirmación al enviar solo la primera vez (O-15)');

prueba('O-15: "no volver a mostrar" se guarda en la cuenta del usuario', function () use ($empP, $adminP) {
    $antes = llamar($empP, $adminP, 'Administrador', 'GET', 'backend/preferencias.php');
    verdadero(empty($antes['json']['preferencias']['cot_sin_confirmar_envio']), 'ya venía marcada: ' . mb_substr($antes['texto'], 0, 100));
    $r = llamar($empP, $adminP, 'Administrador', 'POST', 'backend/preferencias.php', ['json' => ['clave' => 'cot_sin_confirmar_envio', 'valor' => true]]);
    igual(200, $r['http'], 'guardar:');
    $despues = llamar($empP, $adminP, 'Administrador', 'GET', 'backend/preferencias.php');
    verdadero(!empty($despues['json']['preferencias']['cot_sin_confirmar_envio']), 'no quedó guardada');
    como($empP, $adminP, 'Administrador');
});

prueba('O-15: solo se aceptan preferencias conocidas', function () use ($empP, $adminP) {
    $r = llamar($empP, $adminP, 'Administrador', 'POST', 'backend/preferencias.php', ['json' => ['clave' => 'rol', 'valor' => 'Administrador']]);
    igual(400, $r['http'], 'clave desconocida:');
    como($empP, $adminP, 'Administrador');
});
