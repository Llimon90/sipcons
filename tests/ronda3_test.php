<?php
// Auditoría integral 2026-11-24/25: tercera auditoría de Gemini (bloques 1 a 4) y finanzas del bloque 6.
require_once __DIR__ . '/../backend/lib/finanzas_helpers.php';
require_once __DIR__ . '/../backend/lib/cotizaciones_helpers.php';
require_once __DIR__ . '/../backend/lib/clientes_helpers.php';
require_once __DIR__ . '/../config/solid_link.php';

[$empR, $adminR] = empresaTemporal('Ronda 3');
como($empR, $adminR, 'Administrador');
$pdo->exec("INSERT INTO clientes (nombre, rfc, direccion, telefono, contactos, email) VALUES ('Cliente R3', '', '', '', 'A', '')");
$cliR = (int)$pdo->lastInsertId();

function conSateliteR(callable $fn) {
    putenv('SOLID_LINK_TOKEN=token-solid-de-prueba-0123456789');
    $_ENV['SOLID_LINK_TOKEN'] = 'token-solid-de-prueba-0123456789';
    try { return $fn(); } finally { putenv('SOLID_LINK_TOKEN'); unset($_ENV['SOLID_LINK_TOKEN']); }
}
function articuloR(string $cat, float $precio, string $desc = '', int $serie = 0): int {
    global $pdo;
    $sin = in_array($cat, INV_CATEGORIAS_SIN_EXISTENCIA, true);
    $pdo->prepare('INSERT INTO inventario_articulos (sku, nombre, descripcion, categoria, controla_existencia, controla_serie, unidad, existencia, costo_unitario, precio_venta) VALUES (?, ?, ?, ?, ?, ?, ?, 10, 1, ?)')
        ->execute(['R' . bin2hex(random_bytes(4)), "Art $cat", $desc ?: null, $cat, $sin ? 0 : 1, $serie, $sin ? 'servicio' : 'pza', $precio]);
    return (int)$pdo->lastInsertId();
}

grupo('Auditoría Argon, tercera ronda, y finanzas del bloque 6');

prueba('H-02 · D-57: los datos de venta de un equipo vendido no se editan desde el padrón (el mantenimiento sí)', function () use ($empR, $adminR) {
    global $pdo;
    $pdo->exec("INSERT INTO ventas (folio, cliente, fecha_registro) VALUES ('V-R3-2', 'Cliente R3', CURDATE())");
    $venta = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO padron_equipos (cliente, sucursal, equipo, marca, modelo, numero_serie, garantia, origen, venta_id, fecha_registro) VALUES ('Cliente R3', '', 'Minisplit', 'M', 'X', 'SER-R3', 12, 'Venta propia', ?, CURDATE())")->execute([$venta]);
    $eq = (int)$pdo->lastInsertId();
    $base = ['id' => $eq, 'sucursal' => '', 'equipo' => 'Minisplit', 'marca' => 'M', 'modelo' => 'X', 'numero_serie' => 'SER-R3', 'garantia' => 12, 'fecha_registro' => date('Y-m-d')];
    $r = llamar($empR, $adminR, 'Administrador', 'POST', 'backend/actualiza_equipo_padron.php', ['post' => ['numero_serie' => 'OTRA'] + $base]);
    igual(409, $r['http'], 'cambiar la serie:');
    $r = llamar($empR, $adminR, 'Administrador', 'POST', 'backend/actualiza_equipo_padron.php', ['post' => ['calibracion' => 6] + $base]);
    como($empR, $adminR, 'Administrador');
    verdadero($r['http'] === 200, 'cambiar el mantenimiento: ' . substr($r['texto'], 0, 120));
});

prueba('H-06 · D-56: un contacto que no es objeto o un nombre con "/" responden 400, no 500', function () use ($pdo) {
    conSateliteR(function () use ($pdo) {
        lanza(fn() => solidCrearCuenta($pdo, ['nombre' => 'X', 'contacto' => 'no-es-objeto']), InvalidArgumentException::class, 'contacto');
        lanza(fn() => solidCrearCuenta($pdo, ['nombre' => 'X', 'contacto' => ['nombre' => 'Juan / Pedro', 'correo' => 'jp-' . bin2hex(random_bytes(3)) . '@x.test']]), InvalidArgumentException::class, '/');
    });
});

prueba('H-07 · D-56: al pasar de prueba a activa sin vence, la fecha vigente pasa al periodo pagado', function () use ($pdo) {
    conSateliteR(function () use ($pdo) {
        [$emp] = empresaTemporal('Prueba a activa');
        $vence = date('Y-m-d', strtotime('+20 days'));
        solidAplicar($pdo, empresaPorId($pdo, $emp), ['estado' => 'prueba', 'vence' => $vence]);
        solidAplicar($pdo, empresaPorId($pdo, $emp), ['estado' => 'activa']);
        igual($vence, empresaPorId($pdo, $emp)['pagado_hasta']);
    });
});

prueba('H-08 · L-9: aprobar una cotización cuyo «incluye» quedó sin congelar lo congela', function () use ($cliR) {
    global $pdo;
    $srv = articuloR('Servicio', 900, 'Texto original');
    $cot = cotGuardar($pdo, 0, ['cliente_id' => $cliR, 'sucursal' => '', 'fecha' => date('Y-m-d'), 'vigencia_hasta' => date('Y-m-d', strtotime('+9 days')), 'moneda' => 'MXN', 'precios_incluyen_iva' => 0], [['articulo_id' => $srv, 'cantidad' => 1]]);
    cotCambiarEstatus($pdo, (int)$cot['id'], 'enviada');
    $pdo->prepare('UPDATE cotizacion_partidas SET incluye_congelado = NULL WHERE cotizacion_id = ?')->execute([$cot['id']]);  // como antes de la migración
    cotCambiarEstatus($pdo, (int)$cot['id'], 'aprobada', ['aprobada_por' => 'Cliente']);
    $pdo->prepare('UPDATE inventario_articulos SET descripcion = ? WHERE id = ?')->execute(['Texto nuevo', $srv]);
    igual('Texto original', cotObtener($pdo, (int)$cot['id'])['partidas'][0]['incluye']);
});

prueba('H-09 · D-47: una refacción aplicada desde la orden queda como registrada en su cotización aprobada', function () use ($empR, $adminR, $cliR) {
    global $pdo;
    $pdo->exec("INSERT INTO incidencias (numero_incidente, cliente, contacto, sucursal, fecha, categoria, estatus, falla, tecnico) VALUES ('R3-9', 'Cliente R3', 'x', '', CURDATE(), 'General', 'Abierto', 'x', '')");
    $orden = (int)$pdo->lastInsertId();
    $ref = articuloR('Refacción', 300, '', 1);
    $cot = cotGuardar($pdo, 0, ['cliente_id' => $cliR, 'sucursal' => '', 'fecha' => date('Y-m-d'), 'vigencia_hasta' => date('Y-m-d', strtotime('+9 days')), 'moneda' => 'MXN', 'precios_incluyen_iva' => 0], [['articulo_id' => $ref, 'cantidad' => 1]]);
    $pdo->prepare('UPDATE cotizaciones SET incidencia_id = ? WHERE id = ?')->execute([$orden, $cot['id']]);
    cotCambiarEstatus($pdo, (int)$cot['id'], 'enviada');
    cotCambiarEstatus($pdo, (int)$cot['id'], 'aprobada', ['aprobada_por' => 'Cliente']);
    llamar($empR, $adminR, 'Administrador', 'POST', 'backend/inventario.php', ['get' => ['accion' => 'consumir_incidencia'],
        'json' => ['incidencia_id' => $orden, 'articulo_id' => $ref, 'cantidad' => 1, 'series' => ['NS-R3-9']]]);
    como($empR, $adminR, 'Administrador');
    igual(1, (int)$pdo->query("SELECT cargada_incidencia FROM cotizacion_partidas WHERE cotizacion_id = {$cot['id']}")->fetchColumn(), 'cargada:');
});

prueba('H-10 · D-57: renombrar arrastra registros guardados con espacios de sobra', function () {
    global $pdo;
    $pdo->exec("INSERT INTO ventas (folio, cliente, fecha_registro) VALUES ('V-R3-10', 'Hotel Sur ', CURDATE())");
    cliRenombrar($pdo, 'Hotel Sur', 'Hotel Sur SA');
    igual(1, (int)$pdo->query("SELECT COUNT(*) FROM ventas WHERE folio = 'V-R3-10' AND cliente = 'Hotel Sur SA'")->fetchColumn());
});

prueba('L-23: los días de atraso no pierden un día con el cambio de horario', function () {
    $prev = date_default_timezone_get();
    date_default_timezone_set('America/Tijuana');
    igual(2, finDiasVencida(['estatus' => 'pendiente', 'vencimiento' => '2027-03-13'], '2027-03-15'));
    date_default_timezone_set($prev);
});

prueba('L-24: los importes con coma se leen igual que en inventario (miles y decimal)', function () {
    igual(1.5, finR('1,5'));
    igual(1500.0, finR('1,500'));
    igual(1234.5, finR('1,234.50'));
    igual(80.0, finR(80));
});

prueba('L-26: notas de crédito y gastos no aceptan fechas futuras; un cobro no es anterior a su cuenta', function () use ($cliR) {
    global $pdo;
    $c = finCrearCuenta($pdo, ['cliente_id' => $cliR, 'concepto' => 'R3', 'subtotal' => 100, 'iva' => 16, 'fecha' => date('Y-m-d', strtotime('-2 days'))]);
    $manana = date('Y-m-d', strtotime('+1 day'));
    lanza(fn() => finRegistrarNotaCredito($pdo, (int)$c['id'], ['monto' => 10, 'motivo' => 'x', 'fecha' => $manana]), FinanzasException::class, 'futura');
    lanza(fn() => finGuardarGasto($pdo, 0, ['fecha' => $manana, 'categoria' => 'Otros', 'concepto' => 'x', 'monto' => 10, 'iva' => 0]), FinanzasException::class, 'futura');
    lanza(fn() => finRegistrarCobro($pdo, (int)$c['id'], ['monto' => 10, 'fecha' => date('Y-m-d', strtotime('-5 days'))]), FinanzasException::class, 'anterior');
    lanza(fn() => finCrearCuenta($pdo, ['cliente_id' => $cliR, 'concepto' => 'R3', 'subtotal' => 1, 'iva' => 0, 'fecha' => '2026-02-30']), FinanzasException::class, 'no es válida');
});

// ---------------------------------------------------------------- bloque 6: lógica menor
require_once __DIR__ . '/../backend/lib/incidencias_helpers.php';
require_once __DIR__ . '/../backend/lib/importar_helpers.php';
require_once __DIR__ . '/../backend/lib/perfil_helpers.php';

function ordenR(array $c = []): int {
    global $pdo;
    $f = $c + ['numero_incidente' => 'R6-' . bin2hex(random_bytes(3)), 'cliente' => 'Cliente R3', 'contacto' => 'x', 'sucursal' => '',
        'fecha' => date('Y-m-d'), 'categoria' => 'General', 'estatus' => 'Asignado', 'falla' => 'x', 'tecnico' => 'Alguien', 'notas' => ''];
    $cols = implode(', ', array_map(fn($k) => "`$k`", array_keys($f)));
    $pdo->prepare("INSERT INTO incidencias ($cols) VALUES (" . implode(',', array_fill(0, count($f), '?')) . ')')->execute(array_values($f));
    return (int)$pdo->lastInsertId();
}
function editarR(int $emp, int $u, int $id, array $cambios): array {
    global $pdo;
    $f = $pdo->query("SELECT * FROM incidencias WHERE id = $id")->fetch(PDO::FETCH_ASSOC);
    $r = llamar($emp, $u, 'Administrador', 'POST', 'backend/actualiza.php', ['post' => $cambios + [
        'id' => $id, 'version' => versionIncidencia($f), 'reporte_cliente' => '', 'cliente' => $f['cliente'], 'contacto' => $f['contacto'],
        'sucursal' => '', 'categoria' => $f['categoria'], 'fecha' => substr((string)$f['fecha'], 0, 10), 'tecnico' => (string)$f['tecnico'],
        'estatus' => $f['estatus'], 'falla' => $f['falla'], 'accion' => '', 'notas' => '', 'motivo_estatus' => (string)($f['motivo_estatus'] ?? '')]]);
    como($emp, $u, 'Administrador');
    return $r;
}

prueba('L-16: cancelar una orden con cuenta por cobrar pide confirmación', function () use ($empR, $adminR, $cliR) {
    global $pdo;
    $orden = ordenR();
    finCrearCuenta($pdo, ['cliente_id' => $cliR, 'concepto' => 'x', 'subtotal' => 100, 'iva' => 16, 'origen_tipo' => 'incidencia', 'origen_id' => $orden]);
    $r = editarR($empR, $adminR, $orden, ['estatus' => 'Cancelado', 'motivo_estatus' => 'El cliente desistió']);
    igual(409, $r['http'], 'sin confirmar: ' . substr($r['texto'], 0, 100));
    igual('confirmar_documentos', $r['json']['motivo'] ?? null);
    $r = editarR($empR, $adminR, $orden, ['estatus' => 'Cancelado', 'motivo_estatus' => 'El cliente desistió', 'confirmar_documentos' => '1']);
    igual(200, $r['http'], 'confirmado: ' . substr($r['texto'], 0, 100));
});

prueba('L-17: cambiar el cliente de una orden con cotización enviada pide confirmación', function () use ($empR, $adminR, $cliR) {
    global $pdo;
    $pdo->exec("INSERT INTO clientes (nombre, rfc, direccion, telefono, contactos, email) VALUES ('Otro cliente R6', '', '', '', 'A', '')");
    $orden = ordenR();
    $pdo->prepare("INSERT INTO cotizaciones (folio, folio_base, version, cliente_id, cliente_nombre, fecha, vigencia_hasta, estatus, incidencia_id, moneda, tipo_cambio)
                   VALUES (?, ?, 1, ?, 'Cliente R3', CURDATE(), CURDATE(), 'enviada', ?, 'MXN', 1)")->execute(['CQ-' . $orden, 'CQ-' . $orden, $cliR, $orden]);
    $r = editarR($empR, $adminR, $orden, ['cliente' => 'Otro cliente R6']);
    igual(409, $r['http'], 'sin confirmar: ' . substr($r['texto'], 0, 120));
    igual(200, editarR($empR, $adminR, $orden, ['cliente' => 'Otro cliente R6', 'confirmar_documentos' => '1'])['http'], 'confirmado:');
});

prueba('L-18: una fecha que no existe o a más de un año se rechaza con un mensaje claro (no 500)', function () use ($empR, $adminR) {
    $orden = ordenR();
    $r = editarR($empR, $adminR, $orden, ['fecha' => '2026-02-30']);
    igual(400, $r['http'], 'fecha inexistente:');
    $r = editarR($empR, $adminR, $orden, ['fecha' => date('Y-m-d', strtotime('+2 years'))]);
    igual(400, $r['http'], 'a dos años:');
    igual(null, errorFechaOrden(date('Y-m-d', strtotime('+3 months'))), 'programada a 3 meses:');
});

prueba('L-21: la importación programa el siguiente mantenimiento que toca, con meses de calendario', function () {
    $f = impProximaFecha('2022-01-31', 6);
    verdadero($f >= date('Y-m-d'), "quedó en el pasado: $f");
    $prev = date('Y-m-d', strtotime($f . ' -6 months'));
    verdadero($prev < date('Y-m-d'), "no es el siguiente que toca: $f");
    igual(substr(impProximaFecha(date('Y') . '-08-31', 6), 5), '02-' . (date('L', strtotime((date('Y') + 1) . '-01-01')) ? '29' : '28'), '31 de agosto + 6 meses:');
});

prueba('L-30: no se aplica en una orden un artículo desactivado', function () use ($empR, $adminR) {
    global $pdo;
    $orden = ordenR();
    $art = articuloR('Refacción', 50);
    $pdo->prepare('UPDATE inventario_articulos SET activo = 0 WHERE id = ?')->execute([$art]);
    $r = llamar($empR, $adminR, 'Administrador', 'POST', 'backend/inventario.php', ['get' => ['accion' => 'consumir_incidencia'], 'json' => ['incidencia_id' => $orden, 'articulo_id' => $art, 'cantidad' => 1]]);
    como($empR, $adminR, 'Administrador');
    verdadero($r['http'] >= 400 && str_contains($r['texto'], 'desactivado'), 'se aplicó: ' . substr($r['texto'], 0, 100));
});

prueba('L-34: una orden Completado con fecha futura no aparece como programada', function () {
    global $pdo;
    $futura = date('Y-m-d', strtotime('+10 days'));
    $id = ordenR(['estatus' => 'Completado', 'fecha' => $futura]);
    igual(0, (int)$pdo->query('SELECT COUNT(*) FROM incidencias WHERE id = ' . $id . ' AND ' . sqlOrdenProgramada())->fetchColumn());
});

prueba('L-25: «cerradas en 30 días» cuenta por la fecha de cierre, no por la de reporte', function () {
    global $pdo;
    $id = ordenR(['estatus' => 'Cerrado sin factura', 'fecha' => date('Y-m-d', strtotime('-90 days'))]);
    registrarAuditoria('incidencias', $id, 'UPDATE', ['estatus' => 'Completado'], ['estatus' => 'Cerrado sin factura']);
    igual(1, perfilCerradasDesde($pdo, [['id' => $id, 'estatus' => 'Cerrado sin factura', 'fecha' => date('Y-m-d', strtotime('-90 days'))]], date('Y-m-d', strtotime('-30 days'))));
});

prueba('L-20 · RP-18: en solo lectura, consultar no marca cotizaciones vencidas', function () use ($empR, $adminR, $cliR) {
    global $pdo;
    $pdo->prepare("INSERT INTO cotizaciones (folio, folio_base, version, cliente_id, cliente_nombre, fecha, vigencia_hasta, estatus, moneda, tipo_cambio)
                   VALUES ('CQ-L20', 'CQ-L20', 1, ?, 'Cliente R3', CURDATE(), ?, 'enviada', 'MXN', 1)")->execute([$cliR, date('Y-m-d', strtotime('-3 days'))]);
    $pdo->prepare("UPDATE empresas SET estado = 'activa', pagado_hasta = ? WHERE id = ?")->execute([date('Y-m-d', strtotime('-11 days')), $empR]);
    establecerEmpresa($empR);
    verdadero(!empresaAdmiteEscrituras(), 'la empresa debería estar en solo lectura');
    cotMarcarVencidas($pdo);
    igual('enviada', $pdo->query("SELECT estatus FROM cotizaciones WHERE folio = 'CQ-L20'")->fetchColumn());
    $pdo->prepare("UPDATE empresas SET pagado_hasta = ? WHERE id = ?")->execute([date('Y-m-d', strtotime('+30 days')), $empR]);
    establecerEmpresa($empR);
});
