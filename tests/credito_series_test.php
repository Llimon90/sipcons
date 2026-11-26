<?php
// Finanzas del cliente (D-48): límite de crédito, notas de crédito y acceso solo con el
// permiso de Finanzas. Serie de refacciones (D-45).
require_once __DIR__ . '/../backend/lib/finanzas_helpers.php';
require_once __DIR__ . '/../backend/lib/cotizaciones_helpers.php';
require_once __DIR__ . '/../backend/lib/inventario_helpers.php';

[$empK, $adminK] = empresaTemporal('Credito Series');
$sxK = bin2hex(random_bytes(2));
$nomK = "Tecnico Series $sxK";
$tecK = usuarioTemporal($empK, 'Técnico', ['nombre' => $nomK]);
$supK = usuarioTemporal($empK, 'Supervisor');
como($empK, $adminK, 'Administrador');
clienteCatalogo('Cliente Credito');
$cliK = (int)$pdo->query("SELECT id FROM clientes WHERE nombre = 'Cliente Credito'")->fetchColumn();

function finK(int $emp, int $u, string $rol, string $accion, ?array $json = null, array $get = []): array {
    return llamar($emp, $u, $rol, $json === null ? 'GET' : 'POST', 'backend/finanzas.php', ['get' => ['accion' => $accion] + $get, 'json' => $json]);
}

grupo('Crédito del cliente (D-48)');

prueba('D-48: el límite de crédito se guarda con el permiso de Finanzas; un Supervisor no lo cambia', function () use ($empK, $adminK, $supK, $cliK) {
    global $pdo;
    $r = finK($empK, $adminK, 'Administrador', 'guardar_credito', ['cliente_id' => $cliK, 'dias_credito' => 30, 'limite_credito' => 5000]);
    igual(200, $r['http'], 'guardar (' . mb_substr($r['texto'], 0, 150) . '):');
    $r = finK($empK, $supK, 'Supervisor', 'guardar_credito', ['cliente_id' => $cliK, 'dias_credito' => 0, 'limite_credito' => 999999]);
    igual(403, $r['http'], 'Supervisor:');
    como($empK, $adminK, 'Administrador');
    igual(5000.0, (float)$pdo->query("SELECT limite_credito FROM clientes WHERE id = $cliK")->fetchColumn(), 'límite:');
    igual(30, (int)$pdo->query("SELECT dias_credito FROM clientes WHERE id = $cliK")->fetchColumn(), 'días:');
});

prueba('D-48: una cuenta que rebasa el límite se crea, pero avisa', function () use ($empK, $adminK, $cliK) {
    global $pdo;
    como($empK, $adminK, 'Administrador');
    $c1 = finCrearCuenta($pdo, ['cliente_id' => $cliK, 'concepto' => 'Uno', 'subtotal' => 3000]);
    igual(null, $c1['aviso_credito'] ?? null, 'sin rebasar:');
    $c2 = finCrearCuenta($pdo, ['cliente_id' => $cliK, 'concepto' => 'Dos', 'subtotal' => 2500]);
    verdadero(!empty($c2['aviso_credito']) && str_contains($c2['aviso_credito'], 'límite'), 'no avisó: ' . json_encode($c2['aviso_credito'] ?? null));
});

prueba('D-48: la cotización avisa si rebasa el límite (solo a quien tiene Finanzas)', function () use ($empK, $adminK, $tecK, $nomK, $cliK) {
    global $pdo;
    como($empK, $adminK, 'Administrador');
    $pdo->prepare("INSERT INTO inventario_articulos (sku, nombre, categoria, controla_existencia, unidad, precio_venta) VALUES (?, 'Visita', 'Servicio', 0, 'servicio', 1000)")
        ->execute(['K-' . bin2hex(random_bytes(3))]);
    $art = (int)$pdo->lastInsertId();
    $cot = cotGuardar($pdo, 0, ['cliente_id' => $cliK, 'fecha' => date('Y-m-d'), 'vigencia_hasta' => date('Y-m-d', strtotime('+15 days')), 'moneda' => 'MXN'],
        [['articulo_id' => $art, 'cantidad' => 1]]);
    verdadero(!empty($cot['aviso_credito']), 'la cotización no avisó');
    como($empK, $tecK, 'Técnico', $nomK);
    $ver = cotObtener($pdo, (int)$cot['id']);
    igual(null, $ver['aviso_credito'] ?? null, 'el Técnico ve el aviso de crédito:');
    como($empK, $adminK, 'Administrador');
});

grupo('Notas de crédito (D-48)');

prueba('D-48: la nota de crédito reduce el saldo, exige motivo y no cuenta como dinero cobrado', function () use ($empK, $adminK, $cliK) {
    global $pdo;
    como($empK, $adminK, 'Administrador');
    $c = finCrearCuenta($pdo, ['cliente_id' => $cliK, 'concepto' => 'Con nota', 'subtotal' => 1000]);
    $antes = (float)finResumen($pdo)['cobrado_mes'];
    lanza(fn() => finRegistrarNotaCredito($pdo, (int)$c['id'], ['monto' => 200, 'motivo' => '']), FinanzasException::class, 'motivo');
    lanza(fn() => finRegistrarNotaCredito($pdo, (int)$c['id'], ['monto' => 5000, 'motivo' => 'Descuento']), FinanzasException::class, 'saldo');
    $c = finRegistrarNotaCredito($pdo, (int)$c['id'], ['monto' => 200, 'motivo' => 'Descuento por demora', 'referencia' => 'UUID-ABC']);
    igual(800.0, (float)$c['saldo'], 'saldo:');
    igual('parcial', $c['estatus'], 'estatus:');
    igual($antes, (float)finResumen($pdo)['cobrado_mes'], 'cobrado del mes:');
    $nota = array_values(array_filter($c['cobros'], fn($b) => ($b['tipo'] ?? '') === 'nota_credito'))[0] ?? [];
    igual('UUID-ABC', $nota['referencia'] ?? null, 'folio o UUID:');
    $c = finCancelarCobro($pdo, (int)$nota['id'], 'Se emitió por error');
    igual(1000.0, (float)$c['saldo'], 'saldo tras cancelar la nota:');
});

prueba('D-48: el estado de cuenta del cliente solo lo ve quien tiene Finanzas', function () use ($empK, $adminK, $supK, $cliK) {
    $r = finK($empK, $supK, 'Supervisor', 'estado_cuenta', null, ['cliente_id' => $cliK]);
    igual(403, $r['http'], 'Supervisor:');
    $r = finK($empK, $adminK, 'Administrador', 'estado_cuenta', null, ['cliente_id' => $cliK]);
    igual(200, $r['http'], 'Administrador:');
    verdadero(array_key_exists('limite_credito', $r['json']['estado']['cliente'] ?? []), 'el estado de cuenta no trae el límite');
    verdadero(array_key_exists('notas_credito', $r['json']['estado'] ?? []), 'el estado de cuenta no trae las notas de crédito');
});

grupo('Serie de refacciones (D-45)');

prueba('D-45: un artículo que controla serie pide un N/S por pieza al aplicarlo y lo guarda en el movimiento', function () use ($empK, $adminK) {
    global $pdo;
    como($empK, $adminK, 'Administrador');
    $pdo->prepare("INSERT INTO inventario_articulos (sku, nombre, categoria, controla_existencia, controla_serie, unidad, existencia, precio_venta) VALUES (?, 'Celda de carga', 'Refacción', 1, 1, 'pza', 5, 900)")
        ->execute(['CS-' . bin2hex(random_bytes(3))]);
    $art = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO incidencias (numero_incidente, cliente, contacto, sucursal, fecha, categoria, estatus, falla, tecnico) VALUES (?, 'Cliente Credito', 'x', '', CURDATE(), 'General', 'Abierto', 'x', '')")
        ->execute(['KS-' . bin2hex(random_bytes(3))]);
    $orden = (int)$pdo->lastInsertId();
    $inv = fn(array $j) => llamar($empK, $adminK, 'Administrador', 'POST', 'backend/inventario.php', ['get' => ['accion' => 'consumir_incidencia'], 'json' => $j]);
    $r = $inv(['incidencia_id' => $orden, 'articulo_id' => $art, 'cantidad' => 2]);
    igual(422, $r['http'], 'sin series:');
    $r = $inv(['incidencia_id' => $orden, 'articulo_id' => $art, 'cantidad' => 2, 'series' => ['A1', 'A1']]);
    igual(422, $r['http'], 'series repetidas:');
    $r = $inv(['incidencia_id' => $orden, 'articulo_id' => $art, 'cantidad' => 2, 'series' => ['A1', 'A2']]);
    igual(200, $r['http'], 'con series (' . mb_substr($r['texto'], 0, 150) . '):');
    como($empK, $adminK, 'Administrador');
    igual('A1, A2', $pdo->query("SELECT numero_serie FROM inventario_movimientos WHERE referencia_tipo = 'incidencia' AND referencia_id = $orden AND articulo_id = $art")->fetchColumn(), 'series en el movimiento:');
});

prueba('D-45: un artículo sin control de serie se aplica como siempre', function () use ($empK, $adminK) {
    global $pdo;
    como($empK, $adminK, 'Administrador');
    $pdo->prepare("INSERT INTO inventario_articulos (sku, nombre, categoria, controla_existencia, unidad, existencia, precio_venta) VALUES (?, 'Tornillo', 'Consumible', 1, 'pza', 50, 2)")
        ->execute(['TS-' . bin2hex(random_bytes(3))]);
    $art = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO incidencias (numero_incidente, cliente, contacto, sucursal, fecha, categoria, estatus, falla, tecnico) VALUES (?, 'Cliente Credito', 'x', '', CURDATE(), 'General', 'Abierto', 'x', '')")
        ->execute(['KT-' . bin2hex(random_bytes(3))]);
    $orden = (int)$pdo->lastInsertId();
    $r = llamar($empK, $adminK, 'Administrador', 'POST', 'backend/inventario.php', ['get' => ['accion' => 'consumir_incidencia'], 'json' => ['incidencia_id' => $orden, 'articulo_id' => $art, 'cantidad' => 3]]);
    igual(200, $r['http'], 'aplicar (' . mb_substr($r['texto'], 0, 120) . '):');
});
