<?php
// Auditoría integral 2026-11-25: reauditoría de lógica (Fase 4) del cambio completo.
require_once __DIR__ . '/../backend/lib/finanzas_helpers.php';
require_once __DIR__ . '/../backend/lib/cotizaciones_helpers.php';
require_once __DIR__ . '/../backend/lib/clientes_helpers.php';
require_once __DIR__ . '/../config/solid_link.php';
require_once __DIR__ . '/../config/csv.php';

[$empQ4, $adminQ4] = empresaTemporal('Ronda 4');
como($empQ4, $adminQ4, 'Administrador');
$pdo->exec("INSERT INTO clientes (nombre, rfc, direccion, telefono, contactos, email) VALUES ('Cliente Q4', '', '', '', 'A', '')");
$cliQ4 = (int)$pdo->lastInsertId();

function artQ4(string $cat, float $precio, float $costo = 1): int {
    global $pdo;
    $sin = in_array($cat, INV_CATEGORIAS_SIN_EXISTENCIA, true);
    $pdo->prepare('INSERT INTO inventario_articulos (sku, nombre, categoria, controla_existencia, unidad, existencia, costo_unitario, precio_venta) VALUES (?, ?, ?, ?, ?, 10, ?, ?)')
        ->execute(['Q4' . bin2hex(random_bytes(4)), "Art $cat", $cat, $sin ? 0 : 1, $sin ? 'servicio' : 'pza', $costo, $precio]);
    return (int)$pdo->lastInsertId();
}
function ordenQ4(): int {
    global $pdo;
    $pdo->prepare("INSERT INTO incidencias (numero_incidente, cliente, contacto, sucursal, fecha, categoria, estatus, falla, tecnico) VALUES (?, 'Cliente Q4', 'x', '', CURDATE(), 'General', 'Asignado', 'x', 'Alguien')")
        ->execute(['Q4-' . bin2hex(random_bytes(3))]);
    return (int)$pdo->lastInsertId();
}
function cotQ4(int $cli, array $partidas, ?int $orden = null): int {
    global $pdo;
    $cot = cotGuardar($pdo, 0, ['cliente_id' => $cli, 'sucursal' => '', 'fecha' => date('Y-m-d'), 'vigencia_hasta' => date('Y-m-d', strtotime('+9 days')), 'moneda' => 'MXN', 'precios_incluyen_iva' => 0], $partidas);
    if ($orden) $pdo->prepare('UPDATE cotizaciones SET incidencia_id = ? WHERE id = ?')->execute([$orden, $cot['id']]);
    cotCambiarEstatus($pdo, (int)$cot['id'], 'enviada');
    cotCambiarEstatus($pdo, (int)$cot['id'], 'aprobada', ['aprobada_por' => 'Cliente']);
    return (int)$cot['id'];
}

grupo('Reauditoría de lógica (Fase 4)');

prueba('L2-1 · D-56: activar sin vence una prueba ya vencida no la deja suspendida por la fecha vieja', function () use ($pdo) {
    putenv('SOLID_LINK_TOKEN=token-solid-de-prueba-0123456789');
    try {
        [$emp] = empresaTemporal('Activar vencida');
        solidAplicar($pdo, empresaPorId($pdo, $emp), ['estado' => 'prueba', 'vence' => date('Y-m-d', strtotime('-40 days'))]);
        solidAplicar($pdo, empresaPorId($pdo, $emp), ['estado' => 'activa']);
        igual('total', situacionEmpresa(empresaPorId($pdo, $emp))['acceso']);
    } finally { putenv('SOLID_LINK_TOKEN'); }
});

prueba('L2-2 · D-55: la rentabilidad de la orden cobrada por la venta de su cotización resta el costo del equipo', function () use ($cliQ4) {
    global $pdo;
    $eq = artQ4('Equipo', 10000, 7000);
    $orden = ordenQ4();
    $cot = cotQ4($cliQ4, [['articulo_id' => $eq, 'cantidad' => 1]], $orden);
    $pdo->exec("INSERT INTO ventas (folio, cliente, fecha_registro) VALUES ('V-Q4-2', 'Cliente Q4', CURDATE())");
    $venta = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO cotizacion_ventas (cotizacion_id, articulo_id, descripcion, cantidad, estado, venta_id) VALUES (?, ?, 'Equipo', 1, 'registrada', ?)")->execute([$cot, $eq, $venta]);
    invRegistrarMovimiento($pdo, $eq, -1, 'salida', 'Venta V-Q4-2', 'venta', $venta, 'V-Q4-2');
    finCrearCuenta($pdo, ['cliente_id' => $cliQ4, 'concepto' => 'Venta', 'subtotal' => 10000, 'iva' => 1600, 'origen_tipo' => 'venta', 'origen_id' => $venta]);
    $fila = array_values(array_filter(finRentabilidad($pdo, date('Y-m-01', strtotime('-1 month')), date('Y-m-d'))['ordenes'], fn($o) => (int)$o['id'] === $orden))[0] ?? [];
    igual([10000.0, 7000.0, 3000.0], [(float)($fila['ingreso'] ?? 0), (float)($fila['refacciones'] ?? 0), (float)($fila['utilidad'] ?? 0)]);
});

prueba('L2-3 · L2-4 · D-57: renombrar con un duplicado heredado no arrastra al otro; un correo heredado repetido no impide editar lo demás', function () use ($empQ4, $adminQ4) {
    global $pdo;
    $pdo->exec("INSERT INTO clientes (nombre, rfc, direccion, telefono, contactos, email) VALUES ('ACME Q4', '', '', '', 'A', 'dup@q4.test'), ('Acme Q4', '', '', '', 'A', 'dup@q4.test')");
    $id = (int)$pdo->query("SELECT id FROM clientes WHERE nombre = 'ACME Q4'")->fetchColumn();
    $pdo->exec("INSERT INTO ventas (folio, cliente, fecha_registro) VALUES ('V-Q4-3', 'Acme Q4', CURDATE())");
    $editar = fn($nombre, $tel) => llamar($empQ4, $adminQ4, 'Administrador', 'POST', 'backend/actualiza-cliente.php', ['post' => [
        'id' => $id, 'nombre' => $nombre, 'rfc' => '', 'direccion' => '', 'telefono' => $tel, 'email' => 'dup@q4.test', 'contactos' => 'A']]);
    $r = $editar('ACME Q4 Norte', '');  // H-03 (fase 4): se renombra sin arrastrar el historial compartido
    igual(200, $r['http'], 'renombrar con duplicado heredado:');
    verdadero(str_contains((string)($r['json']['aviso'] ?? ''), 'mismo nombre'), 'sin aviso: ' . $r['texto']);
    como($empQ4, $adminQ4, 'Administrador');
    igual('Acme Q4', $pdo->query("SELECT cliente FROM ventas WHERE folio = 'V-Q4-3'")->fetchColumn(), 'la venta del otro cliente:');
    igual(200, $editar('ACME Q4 Norte', '6641112233')['http'], 'editar el teléfono con el correo heredado:');
    como($empQ4, $adminQ4, 'Administrador');
});

prueba('L2-5 · S-5: los números negativos no se convierten en texto en los CSV', function () {
    igual(['-2.00', -3, "'-texto", "'=1+1"], csvFilaSegura(['-2.00', -3, '-texto', '=1+1']));
});

prueba('L2-6 · L2-7 · D-47: aplicar desde la orden marca solo lo que cubre y descarta la venta por registrar del equipo instalado', function () use ($empQ4, $adminQ4, $cliQ4) {
    global $pdo;
    $ref = artQ4('Refacción', 300);
    $orden = ordenQ4();
    $c1 = cotQ4($cliQ4, [['articulo_id' => $ref, 'cantidad' => 1]], $orden);
    $c2 = cotQ4($cliQ4, [['articulo_id' => $ref, 'cantidad' => 1]], $orden);
    llamar($empQ4, $adminQ4, 'Administrador', 'POST', 'backend/inventario.php', ['get' => ['accion' => 'consumir_incidencia'], 'json' => ['incidencia_id' => $orden, 'articulo_id' => $ref, 'cantidad' => 1]]);
    como($empQ4, $adminQ4, 'Administrador');
    igual(1, (int)$pdo->query("SELECT SUM(cargada_incidencia) FROM cotizacion_partidas WHERE cotizacion_id IN ($c1, $c2)")->fetchColumn(), 'partidas marcadas con 1 pieza aplicada:');
    // Equipo instalado como reemplazo desde la orden
    $eq = artQ4('Equipo', 5000);
    $orden2 = ordenQ4();
    $c3 = cotQ4($cliQ4, [['articulo_id' => $eq, 'cantidad' => 1]], $orden2);
    igual(1, (int)$pdo->query("SELECT COUNT(*) FROM cotizacion_ventas WHERE cotizacion_id = $c3 AND estado = 'por_registrar'")->fetchColumn(), 'venta por registrar al aprobar:');
    llamar($empQ4, $adminQ4, 'Administrador', 'POST', 'backend/inventario.php', ['get' => ['accion' => 'consumir_incidencia'],
        'json' => ['incidencia_id' => $orden2, 'articulo_id' => $eq, 'cantidad' => 1, 'reemplazo' => ['numero_serie' => 'Q4-RZ-' . bin2hex(random_bytes(2)), 'equipo' => 'Equipo', 'marca' => 'M', 'modelo' => 'X']]]);
    como($empQ4, $adminQ4, 'Administrador');
    igual(0, (int)$pdo->query("SELECT COUNT(*) FROM cotizacion_ventas WHERE cotizacion_id = $c3 AND estado = 'por_registrar'")->fetchColumn(), 'venta por registrar tras instalarlo:');
});

prueba('L2-8 · L-19: una cotización enviada con la vigencia vencida pide confirmación aunque no la hayan marcado vencida', function () use ($cliQ4) {
    global $pdo;
    $eq = artQ4('Equipo', 100);
    $cot = cotGuardar($pdo, 0, ['cliente_id' => $cliQ4, 'sucursal' => '', 'fecha' => date('Y-m-d'), 'vigencia_hasta' => date('Y-m-d', strtotime('+2 days')), 'moneda' => 'MXN', 'precios_incluyen_iva' => 0], [['articulo_id' => $eq, 'cantidad' => 1]]);
    cotCambiarEstatus($pdo, (int)$cot['id'], 'enviada');
    $pdo->prepare('UPDATE cotizaciones SET vigencia_hasta = ? WHERE id = ?')->execute([date('Y-m-d', strtotime('-1 day')), $cot['id']]);
    lanza(fn() => cotCambiarEstatus($pdo, (int)$cot['id'], 'aprobada', ['aprobada_por' => 'Cliente']), CotizacionException::class, 'Venció');
});

prueba('L2-9 · H-06: la cuenta de una venta de cotización no se marca como «adicional confirmada»', function () use ($cliQ4) {
    global $pdo;
    $srv = artQ4('Servicio', 1000);
    $cot = cotQ4($cliQ4, [['articulo_id' => $srv, 'cantidad' => 1]]);
    $pdo->exec("INSERT INTO ventas (folio, cliente, fecha_registro) VALUES ('V-Q4-9', 'Cliente Q4', CURDATE())");
    $venta = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO cotizacion_ventas (cotizacion_id, descripcion, cantidad, estado, venta_id) VALUES (?, 'x', 1, 'registrada', ?)")->execute([$cot, $venta]);
    finCrearCuenta($pdo, ['cliente_id' => $cliQ4, 'concepto' => 'Anticipo', 'subtotal' => 500, 'iva' => 80, 'origen_tipo' => 'cotizacion', 'origen_id' => $cot]);
    $c = finCrearCuenta($pdo, ['cliente_id' => $cliQ4, 'concepto' => 'Venta', 'subtotal' => 500, 'iva' => 80, 'origen_tipo' => 'venta', 'origen_id' => $venta]);
    $st = $pdo->prepare("SELECT datos_nuevos FROM auditoria WHERE tabla = 'cuentas_cobrar' AND registro_id = ? AND accion = 'CREATE'");
    $st->execute([(string)$c['id']]);
    verdadero(!str_contains((string)$st->fetchColumn(), 'cuenta_adicional_confirmada'), 'marcada sin confirmación');
});

// ---------------------------------------------------------------- auditoría final de Gemini (2026-11-25) y seguridad
require_once __DIR__ . '/../backend/lib/cotizacion_pdf.php';
require_once __DIR__ . '/../backend/lib/perfil_helpers.php';
require_once __DIR__ . '/../backend/lib/incidencias_helpers.php';

prueba('H-01 (final) · D-55: editar la cuenta de una venta de cotización no rebasa lo aprobado', function () use ($cliQ4) {
    global $pdo;
    $srv = artQ4('Servicio', 1000);
    $cot = cotQ4($cliQ4, [['articulo_id' => $srv, 'cantidad' => 1]]);
    $pdo->exec("INSERT INTO ventas (folio, cliente, fecha_registro) VALUES ('V-Q4-F1', 'Cliente Q4', CURDATE())");
    $venta = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO cotizacion_ventas (cotizacion_id, descripcion, cantidad, estado, venta_id) VALUES (?, 'x', 1, 'registrada', ?)")->execute([$cot, $venta]);
    $c = finCrearCuenta($pdo, ['cliente_id' => $cliQ4, 'concepto' => 'Venta', 'subtotal' => 100, 'iva' => 16, 'origen_tipo' => 'venta', 'origen_id' => $venta]);
    lanza(fn() => finEditarCuenta($pdo, (int)$c['id'], ['subtotal' => 50000, 'iva' => 8000]), FinanzasException::class, 'rebasa');
});

prueba('H-02 (final): el PDF de una enviada con la vigencia pasada lleva la marca VENCIDA', function () use ($cliQ4) {
    global $pdo;
    $eq = artQ4('Equipo', 100);
    $cot = cotGuardar($pdo, 0, ['cliente_id' => $cliQ4, 'sucursal' => '', 'fecha' => date('Y-m-d'), 'vigencia_hasta' => date('Y-m-d', strtotime('+2 days')), 'moneda' => 'MXN', 'precios_incluyen_iva' => 0], [['articulo_id' => $eq, 'cantidad' => 1]]);
    cotCambiarEstatus($pdo, (int)$cot['id'], 'enviada');
    $pdo->prepare('UPDATE cotizaciones SET vigencia_hasta = ? WHERE id = ?')->execute([date('Y-m-d', strtotime('-1 day')), $cot['id']]);
    $c = cotObtener($pdo, (int)$cot['id']);
    verdadero(str_contains(cotPdfHtml($pdo, $c, $c['partidas']), 'VENCIDA'), 'sin la marca');
});

prueba('H-03 (final) · D-47: un equipo instalado antes de aprobar la cotización no genera venta por registrar', function () use ($empQ4, $adminQ4, $cliQ4) {
    global $pdo;
    $eq = artQ4('Equipo', 5000);
    $orden = ordenQ4();
    llamar($empQ4, $adminQ4, 'Administrador', 'POST', 'backend/inventario.php', ['get' => ['accion' => 'consumir_incidencia'],
        'json' => ['incidencia_id' => $orden, 'articulo_id' => $eq, 'cantidad' => 1, 'reemplazo' => ['numero_serie' => 'Q4-F3-' . bin2hex(random_bytes(2)), 'equipo' => 'Equipo', 'marca' => 'M', 'modelo' => 'X']]]);
    como($empQ4, $adminQ4, 'Administrador');
    $cot = cotQ4($cliQ4, [['articulo_id' => $eq, 'cantidad' => 1]], $orden);
    igual(0, (int)$pdo->query("SELECT COUNT(*) FROM cotizacion_ventas WHERE cotizacion_id = $cot AND estado = 'por_registrar'")->fetchColumn(), 'ventas por registrar:');
});

prueba('H-04 (final) · L-34: el perfil no cuenta como programada una orden Completado con fecha futura', function () {
    global $pdo;
    $u = $pdo->query("SELECT id FROM usuarios WHERE empresa_id = " . (int)EmpresaContexto::$id . " LIMIT 1")->fetchColumn();
    $nombre = (string)$pdo->query("SELECT nombre FROM usuarios WHERE id = $u")->fetchColumn();
    $pdo->prepare("INSERT INTO incidencias (numero_incidente, cliente, contacto, sucursal, fecha, categoria, estatus, falla, tecnico) VALUES (?, 'Cliente Q4', 'x', '', ?, 'General', 'Completado', 'x', ?)")
        ->execute(['Q4-F4', date('Y-m-d', strtotime('+5 days')), $nombre]);
    igual(0, perfilVer($pdo, (int)$u)['metricas']['programadas']);
});

prueba('H-05 (final) · D-57: la fecha de un equipo vendido no cambia desde el padrón y una serie no se repite', function () use ($empQ4, $adminQ4) {
    global $pdo;
    $pdo->exec("INSERT INTO ventas (folio, cliente, fecha_registro) VALUES ('V-Q4-F5', 'Cliente Q4', CURDATE())");
    $venta = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO padron_equipos (cliente, sucursal, equipo, marca, modelo, numero_serie, garantia, origen, venta_id, fecha_registro) VALUES ('Cliente Q4', '', 'Eq', 'M', 'X', 'Q4-F5-A', 0, 'Venta propia', ?, CURDATE())")->execute([$venta]);
    $vendido = (int)$pdo->lastInsertId();
    $pdo->exec("INSERT INTO padron_equipos (cliente, sucursal, equipo, marca, modelo, numero_serie, garantia, origen, fecha_registro) VALUES ('Cliente Q4', '', 'Eq', 'M', 'X', 'Q4-F5-B', 0, 'Externo', CURDATE())");
    $externo = (int)$pdo->lastInsertId();
    $editar = fn($id, $serie, $fecha) => llamar($empQ4, $adminQ4, 'Administrador', 'POST', 'backend/actualiza_equipo_padron.php', ['post' => [
        'id' => $id, 'sucursal' => '', 'equipo' => 'Eq', 'marca' => 'M', 'modelo' => 'X', 'numero_serie' => $serie, 'garantia' => 0, 'fecha_registro' => $fecha]]);
    igual(409, $editar($vendido, 'Q4-F5-A', date('Y-m-d', strtotime('-200 days')))['http'], 'cambiar la fecha del vendido:');
    igual(409, $editar($externo, 'Q4-F5-A', date('Y-m-d'))['http'], 'repetir una serie:');
    como($empQ4, $adminQ4, 'Administrador');
});

prueba('H-06 (final) · L-17: cambiar el cliente de una orden con conceptos aplicados pide confirmación', function () use ($empQ4, $adminQ4) {
    global $pdo;
    $pdo->exec("INSERT INTO clientes (nombre, rfc, direccion, telefono, contactos, email) VALUES ('Otro Q4', '', '', '', 'A', '')");
    $ref = artQ4('Refacción', 50);
    $orden = ordenQ4();
    llamar($empQ4, $adminQ4, 'Administrador', 'POST', 'backend/inventario.php', ['get' => ['accion' => 'consumir_incidencia'], 'json' => ['incidencia_id' => $orden, 'articulo_id' => $ref, 'cantidad' => 1]]);
    $f = $pdo->query("SELECT * FROM incidencias WHERE id = $orden")->fetch(PDO::FETCH_ASSOC);
    $r = llamar($empQ4, $adminQ4, 'Administrador', 'POST', 'backend/actualiza.php', ['post' => [
        'id' => $orden, 'version' => versionIncidencia($f), 'reporte_cliente' => '', 'cliente' => 'Otro Q4', 'contacto' => 'x', 'sucursal' => '', 'categoria' => 'General',
        'fecha' => date('Y-m-d'), 'tecnico' => 'Alguien', 'estatus' => 'Asignado', 'falla' => 'x', 'accion' => '', 'notas' => '', 'motivo_estatus' => '']]);
    como($empQ4, $adminQ4, 'Administrador');
    igual(409, $r['http'], 'cambiar el cliente: ' . substr($r['texto'], 0, 100));
});

prueba('H-08 (final) · D-54: una versión sustituida no se comparte', function () use ($empQ4, $adminQ4, $cliQ4) {
    global $pdo;
    $eq = artQ4('Equipo', 100);
    $cot = cotGuardar($pdo, 0, ['cliente_id' => $cliQ4, 'sucursal' => '', 'fecha' => date('Y-m-d'), 'vigencia_hasta' => date('Y-m-d', strtotime('+5 days')), 'moneda' => 'MXN', 'precios_incluyen_iva' => 0], [['articulo_id' => $eq, 'cantidad' => 1]]);
    cotCambiarEstatus($pdo, (int)$cot['id'], 'enviada');
    cotNuevaVersion($pdo, (int)$cot['id']);
    $r = llamar($empQ4, $adminQ4, 'Administrador', 'GET', 'backend/cotizaciones.php', ['get' => ['accion' => 'enlace', 'id' => $cot['id']]]);
    como($empQ4, $adminQ4, 'Administrador');
    verdadero($r['http'] >= 400, 'compartió una versión sustituida');
});

prueba('H-09 (final) · L-25: editar después una orden cerrada hace tiempo no la cuenta como cerrada reciente', function () {
    global $pdo;
    $id = ordenQ4();
    $pdo->prepare("UPDATE incidencias SET estatus = 'Cerrado sin factura' WHERE id = ?")->execute([$id]);
    registrarAuditoria('incidencias', $id, 'UPDATE', ['estatus' => 'Cerrado sin factura', 'notas' => 'a'], ['estatus' => 'Cerrado sin factura', 'notas' => 'b']);
    igual(0, perfilCerradasDesde($pdo, [['id' => $id, 'estatus' => 'Cerrado sin factura', 'fecha' => date('Y-m-d', strtotime('-90 days'))]], date('Y-m-d', strtotime('-30 days'))));
});

prueba('H-10 (final) · L-18: una fecha con texto de más se rechaza (no da 500)', function () {
    verdadero(errorFechaOrden(date('Y-m-d') . 'xyz') !== null, 'la aceptó');
});

prueba('H-11 (final) · D-51: una devolución regresa también al costo con que salió la pieza', function () use ($empQ4, $adminQ4) {
    global $pdo;
    $ref = artQ4('Refacción', 1000, 500);
    $orden = ordenQ4();
    $aplicar = fn() => llamar($empQ4, $adminQ4, 'Administrador', 'POST', 'backend/inventario.php', ['get' => ['accion' => 'consumir_incidencia'], 'json' => ['incidencia_id' => $orden, 'articulo_id' => $ref, 'cantidad' => 1]]);
    $aplicar(); $aplicar();
    $pdo->prepare('UPDATE inventario_articulos SET costo_unitario = 800 WHERE id = ?')->execute([$ref]);
    llamar($empQ4, $adminQ4, 'Administrador', 'POST', 'backend/inventario.php', ['get' => ['accion' => 'devolver_incidencia'], 'json' => ['incidencia_id' => $orden, 'articulo_id' => $ref, 'cantidad' => 1]]);
    como($empQ4, $adminQ4, 'Administrador');
    igual(500.0, (float)$pdo->query("SELECT SUM(-cantidad * costo_unitario) FROM inventario_movimientos WHERE referencia_tipo = 'incidencia' AND referencia_id = $orden")->fetchColumn(), 'costo neto de lo instalado:');
});

prueba('S2-3 · L-13: con la contraseña temporal no se usa contratar', function () {
    global $pdo;
    [$emp, $admin] = empresaTemporal('Temporal contratar');
    $pdo->prepare('UPDATE usuarios SET debe_cambiar_password = 1 WHERE id = ?')->execute([$admin]);
    igual(403, llamar($emp, $admin, 'Administrador', 'GET', 'auth/contratar.php', ['get' => ['accion' => 'estado']])['http']);
    como($GLOBALS['empQ4'], $GLOBALS['adminQ4'], 'Administrador');
});

// ---------------------------------------------------------------- Gemini sobre la Fase 4 (2026-11-25)
prueba('H-02 (fase 4) · D-47: lo aplicado se asigna primero a la cotización aprobada, y una devolución la desmarca', function () use ($empQ4, $adminQ4, $cliQ4) {
    global $pdo;
    como($empQ4, $adminQ4, 'Administrador');
    $ref = artQ4('Refacción', 80);
    $orden = ordenQ4();
    // Un borrador viejo con la misma refacción, que no debe quedarse con lo aplicado
    $borrador = cotGuardar($pdo, 0, ['cliente_id' => $cliQ4, 'sucursal' => '', 'fecha' => date('Y-m-d'), 'vigencia_hasta' => date('Y-m-d', strtotime('+9 days')), 'moneda' => 'MXN', 'precios_incluyen_iva' => 0], [['articulo_id' => $ref, 'cantidad' => 1]]);
    $pdo->prepare('UPDATE cotizaciones SET incidencia_id = ? WHERE id = ?')->execute([$orden, $borrador['id']]);
    $inv = fn($accion, $cant) => llamar($empQ4, $adminQ4, 'Administrador', 'POST', 'backend/inventario.php', ['get' => ['accion' => $accion], 'json' => ['incidencia_id' => $orden, 'articulo_id' => $ref, 'cantidad' => $cant]]);
    $inv('consumir_incidencia', 1);
    como($empQ4, $adminQ4, 'Administrador');
    $aprobada = cotQ4($cliQ4, [['articulo_id' => $ref, 'cantidad' => 1]], $orden);
    igual(1, (int)$pdo->query("SELECT cargada_incidencia FROM cotizacion_partidas WHERE cotizacion_id = $aprobada")->fetchColumn(), 'la aprobada queda cubierta:');
    $inv('devolver_incidencia', 1);
    como($empQ4, $adminQ4, 'Administrador');
    igual(0, (int)$pdo->query("SELECT SUM(cargada_incidencia) FROM cotizacion_partidas WHERE cotizacion_id IN ($aprobada, {$borrador['id']})")->fetchColumn(), 'tras devolver:');
});

prueba('H-04 (fase 4) · D-56: reactivar desde suspendida sin vence no deja la fecha vieja aplicándose', function () use ($pdo) {
    putenv('SOLID_LINK_TOKEN=token-solid-de-prueba-0123456789');
    try {
        [$emp] = empresaTemporal('Reactivar suspendida');
        solidAplicar($pdo, empresaPorId($pdo, $emp), ['estado' => 'activa', 'vence' => date('Y-m-d', strtotime('-30 days'))]);
        solidAplicar($pdo, empresaPorId($pdo, $emp), ['estado' => 'suspendida']);
        solidAplicar($pdo, empresaPorId($pdo, $emp), ['estado' => 'activa']);
        igual('total', situacionEmpresa(empresaPorId($pdo, $emp))['acceso']);
    } finally { putenv('SOLID_LINK_TOKEN'); }
});

prueba('H-05 (fase 4) · D-57: una serie duplicada heredada no impide cambiar la sucursal; sin fecha se conserva la que tenía', function () use ($empQ4, $adminQ4) {
    global $pdo;
    como($empQ4, $adminQ4, 'Administrador');
    $pdo->exec("INSERT INTO padron_equipos (cliente, sucursal, equipo, marca, modelo, numero_serie, garantia, origen, fecha_registro) VALUES
                ('Cliente Q4', '', 'Eq', 'M', 'X', 'Q4-DUP', 0, 'Externo', '2025-03-10'), ('Cliente Q4', '', 'Eq', 'M', 'X', 'Q4-DUP', 0, 'Externo', '2025-03-10')");
    $id = (int)$pdo->query("SELECT MAX(id) FROM padron_equipos WHERE numero_serie = 'Q4-DUP'")->fetchColumn();
    $r = llamar($empQ4, $adminQ4, 'Administrador', 'POST', 'backend/actualiza_equipo_padron.php', ['post' => [
        'id' => $id, 'sucursal' => 'Norte', 'equipo' => 'Eq', 'marca' => 'M', 'modelo' => 'X', 'numero_serie' => 'Q4-DUP', 'garantia' => 0]]);
    como($empQ4, $adminQ4, 'Administrador');
    igual(200, $r['http'], 'cambiar la sucursal: ' . substr($r['texto'], 0, 100));
    igual('2025-03-10', substr((string)$pdo->query("SELECT fecha_registro FROM padron_equipos WHERE id = $id")->fetchColumn(), 0, 10), 'fecha conservada:');
});

// ---------------------------------------------------------------- segunda pasada de Gemini sobre la Fase 4 (2026-11-25)
prueba('H-01 (fase 4 b) · D-50: un PUT parcial que repite el estado no saca de solo lectura a una cuenta vencida', function () use ($pdo) {
    putenv('SOLID_LINK_TOKEN=token-solid-de-prueba-0123456789');
    try {
        [$emp] = empresaTemporal('PUT parcial');
        solidAplicar($pdo, empresaPorId($pdo, $emp), ['estado' => 'activa', 'vence' => date('Y-m-d', strtotime('-3 days'))]);
        solidAplicar($pdo, empresaPorId($pdo, $emp), ['estado' => 'activa', 'plan' => 'servidesk-pro']);
        igual('lectura', situacionEmpresa(empresaPorId($pdo, $emp))['acceso']);
    } finally { putenv('SOLID_LINK_TOKEN'); }
});

prueba('H-02 (fase 4 b) · D-47: un equipo devuelto al almacén vuelve a quedar por registrar en su venta', function () use ($empQ4, $adminQ4, $cliQ4) {
    global $pdo;
    como($empQ4, $adminQ4, 'Administrador');
    $eq = artQ4('Equipo', 5000);
    $orden = ordenQ4();
    $cot = cotQ4($cliQ4, [['articulo_id' => $eq, 'cantidad' => 1]], $orden);
    $serie = 'Q4-F4B-' . bin2hex(random_bytes(2));
    llamar($empQ4, $adminQ4, 'Administrador', 'POST', 'backend/inventario.php', ['get' => ['accion' => 'consumir_incidencia'],
        'json' => ['incidencia_id' => $orden, 'articulo_id' => $eq, 'cantidad' => 1, 'reemplazo' => ['numero_serie' => $serie, 'equipo' => 'Equipo', 'marca' => 'M', 'modelo' => 'X']]]);
    como($empQ4, $adminQ4, 'Administrador');
    igual(0, (int)$pdo->query("SELECT COUNT(*) FROM cotizacion_ventas WHERE cotizacion_id = $cot AND estado = 'por_registrar'")->fetchColumn(), 'tras instalarlo:');
    $pdo->prepare('DELETE FROM padron_equipos WHERE numero_serie = ?')->execute([$serie]);
    llamar($empQ4, $adminQ4, 'Administrador', 'POST', 'backend/inventario.php', ['get' => ['accion' => 'devolver_incidencia'], 'json' => ['incidencia_id' => $orden, 'articulo_id' => $eq, 'cantidad' => 1]]);
    como($empQ4, $adminQ4, 'Administrador');
    igual(1, (int)$pdo->query("SELECT COUNT(*) FROM cotizacion_ventas WHERE cotizacion_id = $cot AND estado = 'por_registrar'")->fetchColumn(), 'tras devolverlo:');
});

prueba('H-04 (fase 4 b) · CV-21: cambiar la sucursal de un equipo no reprograma su próximo mantenimiento', function () use ($empQ4, $adminQ4) {
    global $pdo;
    como($empQ4, $adminQ4, 'Administrador');
    $prox = date('Y-m-d', strtotime('+40 days'));
    $pdo->prepare("INSERT INTO padron_equipos (cliente, sucursal, equipo, marca, modelo, numero_serie, garantia, calibracion, origen, fecha_registro, proxima_calibracion)
                   VALUES ('Cliente Q4', '', 'Eq', 'M', 'X', ?, 0, 6, 'Externo', '2024-01-15', ?)")->execute(['Q4-F4B-P-' . bin2hex(random_bytes(2)), $prox]);
    $id = (int)$pdo->lastInsertId();
    $serie = (string)$pdo->query("SELECT numero_serie FROM padron_equipos WHERE id = $id")->fetchColumn();
    llamar($empQ4, $adminQ4, 'Administrador', 'POST', 'backend/actualiza_equipo_padron.php', ['post' => [
        'id' => $id, 'sucursal' => 'Sur', 'equipo' => 'Eq', 'marca' => 'M', 'modelo' => 'X', 'numero_serie' => $serie, 'garantia' => 0, 'calibracion' => 6, 'fecha_registro' => '2024-01-15']]);
    como($empQ4, $adminQ4, 'Administrador');
    igual($prox, (string)$pdo->query("SELECT proxima_calibracion FROM padron_equipos WHERE id = $id")->fetchColumn());
});

// ---------------------------------------------------------------- última pasada de Gemini (2026-11-25)
prueba('H-01 (fase 4 c) · D-47: al aprobar parcialmente, lo no aceptado no queda como registrado', function () use ($empQ4, $adminQ4, $cliQ4) {
    global $pdo;
    como($empQ4, $adminQ4, 'Administrador');
    $ref = artQ4('Refacción', 60);
    $orden = ordenQ4();
    llamar($empQ4, $adminQ4, 'Administrador', 'POST', 'backend/inventario.php', ['get' => ['accion' => 'consumir_incidencia'], 'json' => ['incidencia_id' => $orden, 'articulo_id' => $ref, 'cantidad' => 1]]);
    como($empQ4, $adminQ4, 'Administrador');
    $otro = artQ4('Servicio', 100);
    $cot = cotGuardar($pdo, 0, ['cliente_id' => $cliQ4, 'sucursal' => '', 'fecha' => date('Y-m-d'), 'vigencia_hasta' => date('Y-m-d', strtotime('+9 days')), 'moneda' => 'MXN', 'precios_incluyen_iva' => 0],
        [['articulo_id' => $ref, 'cantidad' => 1], ['articulo_id' => $otro, 'cantidad' => 1]], '', false, ['incidencia_id' => $orden, 'instaladas' => [$ref]]);
    $pdo->prepare('UPDATE cotizaciones SET incidencia_id = ? WHERE id = ?')->execute([$orden, $cot['id']]);
    cotCambiarEstatus($pdo, (int)$cot['id'], 'enviada');
    $soloOtro = (int)$pdo->query("SELECT id FROM cotizacion_partidas WHERE cotizacion_id = {$cot['id']} AND articulo_id = $otro")->fetchColumn();
    cotCambiarEstatus($pdo, (int)$cot['id'], 'aprobada', ['aprobada_por' => 'Cliente', 'aceptadas' => [$soloOtro]]);
    igual(0, (int)$pdo->query("SELECT cargada_incidencia FROM cotizacion_partidas WHERE cotizacion_id = {$cot['id']} AND articulo_id = $ref")->fetchColumn());
});

prueba('H-05 (fase 4 c) · D-56: reactivar desde suspendida usa la fecha del estado al que vuelve', function () use ($pdo) {
    putenv('SOLID_LINK_TOKEN=token-solid-de-prueba-0123456789');
    try {
        [$emp] = empresaTemporal('Reactivar mezcla');
        $futura = date('Y-m-d', strtotime('+20 days'));
        $pdo->prepare("UPDATE empresas SET estado = 'suspendida', prueba_hasta = ?, pagado_hasta = ? WHERE id = ?")->execute([date('Y-m-d', strtotime('+90 days')), $futura, $emp]);
        solidAplicar($pdo, empresaPorId($pdo, $emp), ['estado' => 'activa']);
        igual($futura, empresaPorId($pdo, $emp)['pagado_hasta']);
    } finally { putenv('SOLID_LINK_TOKEN'); }
});
