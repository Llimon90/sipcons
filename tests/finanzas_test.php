<?php
// Pruebas del módulo de finanzas (backend/lib/finanzas_helpers.php).
require_once __DIR__ . '/../backend/lib/finanzas_helpers.php';
require_once __DIR__ . '/../backend/lib/cotizaciones_helpers.php';

[$empF, $adminF] = empresaTemporal('Finanzas A');
[$empG, $adminG] = empresaTemporal('Finanzas B');

function clienteFin(string $nombre, int $credito = 0): int {
    global $pdo;
    $pdo->prepare("INSERT INTO clientes (nombre, rfc, direccion, telefono, contactos, email, dias_credito) VALUES (?, 'XAXX010101000', '', '', '', '', ?)")
        ->execute([$nombre, $credito]);
    return (int)$pdo->lastInsertId();
}

grupo('Finanzas: cuentas por cobrar y cobros');

prueba('El vencimiento sale de los días de crédito del cliente y se pueden recordar', function () use ($empF, $adminF) {
    global $pdo;
    como($empF, $adminF, 'Administrador');
    $cli = clienteFin('Hotel Crédito', 30);
    $c = finCrearCuenta($pdo, ['cliente_id' => $cli, 'concepto' => 'Mantenimiento', 'fecha' => '2026-11-01', 'subtotal' => 1000, 'iva' => 160]);
    igual('2026-12-01', $c['vencimiento']);
    igual(1160.0, (float)$c['total']);
    igual('pendiente', $c['estatus']);
    verdadero(str_starts_with($c['folio'], 'CXC-'), 'folio con prefijo');
    finCrearCuenta($pdo, ['cliente_id' => $cli, 'concepto' => 'Otro', 'fecha' => '2026-11-01', 'subtotal' => 100, 'dias_credito' => 15, 'guardar_credito' => 1]);
    igual(15, (int)finCliente($pdo, $cli)['dias_credito'], 'los días quedan en el cliente');
});

prueba('Retenciones restan del total; total cero o negativo no se acepta', function () use ($empF, $adminF) {
    global $pdo;
    como($empF, $adminF, 'Administrador');
    $cli = clienteFin('Corporativo Retiene');
    $c = finCrearCuenta($pdo, ['cliente_id' => $cli, 'concepto' => 'Servicio', 'subtotal' => 1000, 'iva' => 160, 'retenciones' => 106.67]);
    igual(1053.33, (float)$c['total']);
    lanza(fn() => finCrearCuenta($pdo, ['cliente_id' => $cli, 'concepto' => 'X', 'subtotal' => 0]), FinanzasException::class, 'mayor a cero');
});

prueba('Cobros parciales, completo, de más y cancelación recalculan el saldo', function () use ($empF, $adminF) {
    global $pdo;
    como($empF, $adminF, 'Administrador');
    $cli = clienteFin('Clínica Cobros');
    $c = finCrearCuenta($pdo, ['cliente_id' => $cli, 'concepto' => 'Póliza', 'subtotal' => 1000, 'iva' => 160]);
    $c = finRegistrarCobro($pdo, (int)$c['id'], ['monto' => 500, 'forma' => 'transferencia', 'referencia' => 'SPEI 123']);
    igual('parcial', $c['estatus']); igual(660.0, $c['saldo']);
    lanza(fn() => finRegistrarCobro($pdo, (int)$c['id'], ['monto' => 700]), FinanzasException::class, 'mayor al saldo');
    $c = finRegistrarCobro($pdo, (int)$c['id'], ['monto' => 660, 'forma' => 'efectivo']);
    igual('pagada', $c['estatus']); igual(0.0, $c['saldo']);
    lanza(fn() => finRegistrarCobro($pdo, (int)$c['id'], ['monto' => 1]), FinanzasException::class, 'ya está pagada');
    lanza(fn() => finCancelarCuenta($pdo, (int)$c['id'], 'error'), FinanzasException::class, 'cobros registrados');
    $c = finCancelarCobro($pdo, (int)$c['cobros'][1]['id'], 'Cheque rebotado');
    igual('parcial', $c['estatus']); igual(660.0, $c['saldo']);
});

prueba('Solo el Administrador cancela; el Administrativo registra', function () use ($empF, $adminF) {
    global $pdo;
    como($empF, $adminF, 'Administrador');
    $cli = clienteFin('Escuela Permisos');
    $adm = usuarioTemporal($empF, 'Administrativo');
    como($empF, $adm, 'Administrativo');
    $c = finCrearCuenta($pdo, ['cliente_id' => $cli, 'concepto' => 'Visita', 'subtotal' => 500]);
    lanza(fn() => finCancelarCuenta($pdo, (int)$c['id'], 'duplicada'), FinanzasException::class, 'administrador');
    como($empF, $adminF, 'Administrador');
    lanza(fn() => finCancelarCuenta($pdo, (int)$c['id'], ''), FinanzasException::class, 'motivo');
    igual('cancelada', finCancelarCuenta($pdo, (int)$c['id'], 'Duplicada')['estatus']);
});

prueba('Una cotización aprobada no se cobra por más de lo aprobado (anticipo + resto sí)', function () use ($empF, $adminF) {
    global $pdo;
    como($empF, $adminF, 'Administrador');
    $cli = clienteFin('Restaurante Cotiza');
    $pdo->prepare("INSERT INTO inventario_articulos (sku, nombre, categoria, controla_existencia, unidad, existencia, costo_unitario, precio_venta)
                   VALUES ('MO-1', 'Mano de obra', 'Mano de obra', 0, 'servicio', 0, 300, 1000)")->execute();
    $art = (int)$pdo->lastInsertId();
    $cot = cotGuardar($pdo, 0, ['cliente_id' => $cli, 'fecha' => date('Y-m-d'), 'vigencia_hasta' => date('Y-m-d', strtotime('+15 days')), 'moneda' => 'MXN'],
        [['articulo_id' => $art, 'cantidad' => 2]]);
    cotCambiarEstatus($pdo, (int)$cot['id'], 'enviada');
    cotCambiarEstatus($pdo, (int)$cot['id'], 'aprobada', ['aprobada_por' => 'Gerente']);
    $o = finOrigen($pdo, 'cotizacion', (int)$cot['id']);
    igual(2000.0, $o['subtotal']); igual(320.0, $o['iva']); igual(2320.0, $o['limite']);
    verdadero(in_array((int)$cot['id'], array_column(array_filter(finPendientesDeRegistrar($pdo), fn($p) => $p['tipo'] === 'cotizacion'), 'id'), true), 'aparece por registrar');
    finCrearCuenta($pdo, ['cliente_id' => $cli, 'concepto' => 'Anticipo 50%', 'origen_tipo' => 'cotizacion', 'origen_id' => $cot['id'], 'subtotal' => 1000, 'iva' => 160]);
    lanza(fn() => finCrearCuenta($pdo, ['cliente_id' => $cli, 'concepto' => 'Resto', 'origen_tipo' => 'cotizacion', 'origen_id' => $cot['id'], 'subtotal' => 1500, 'iva' => 240]),
        FinanzasException::class, 'solo faltan');
    finCrearCuenta($pdo, ['cliente_id' => $cli, 'concepto' => 'Resto', 'origen_tipo' => 'cotizacion', 'origen_id' => $cot['id'], 'subtotal' => 1000, 'iva' => 160]);
    verdadero(!in_array((int)$cot['id'], array_column(array_filter(finPendientesDeRegistrar($pdo), fn($p) => $p['tipo'] === 'cotizacion'), 'id'), true), 'ya no está pendiente');
});

grupo('Finanzas: gastos, rentabilidad y aislamiento');

prueba('Rentabilidad de una orden: ingreso sin IVA − refacciones a costo − gastos sin IVA', function () use ($empF, $adminF) {
    global $pdo;
    como($empF, $adminF, 'Administrador');
    $cli = clienteFin('Plaza Rentable');
    $pdo->exec("INSERT INTO incidencias (numero_incidente, cliente, fecha, falla, categoria, estatus) VALUES ('OS-9001', 'Plaza Rentable', CURDATE(), 'No enfría', 'Otros', 'Cerrado con factura')");
    $inc = (int)$pdo->lastInsertId();
    verdadero(in_array($inc, array_column(finPendientesDeRegistrar($pdo), 'id'), true), 'orden cerrada con factura sin cuenta aparece pendiente');
    $pdo->exec("INSERT INTO inventario_articulos (sku, nombre, categoria, unidad, existencia, costo_unitario, precio_venta) VALUES ('CAP-1', 'Capacitor', 'Refacción', 'pza', 10, 200, 450)");
    invRegistrarMovimiento($pdo, (int)$pdo->lastInsertId(), -2, 'salida', 'Utilizado', 'incidencia', $inc, 'OS-9001');
    finCrearCuenta($pdo, ['cliente_id' => $cli, 'concepto' => 'Servicio', 'origen_tipo' => 'incidencia', 'origen_id' => $inc, 'subtotal' => 2000, 'iva' => 320]);
    finGuardarGasto($pdo, 0, ['categoria' => 'Viáticos', 'concepto' => 'Casetas', 'monto' => 116, 'iva' => 16, 'incidencia_folio' => 'OS-9001']);
    $r = finRentabilidad($pdo, date('Y-m-01'), date('Y-m-d'));
    $fila = array_values(array_filter($r['ordenes'], fn($o) => (int)$o['id'] === $inc))[0];
    igual(2000.0, $fila['ingreso']); igual(400.0, $fila['refacciones']); igual(100.0, $fila['gastos']); igual(1500.0, $fila['utilidad']);
    igual(75.0, $fila['margen_pct']);
    lanza(fn() => finGuardarGasto($pdo, 0, ['categoria' => 'X', 'concepto' => 'Y', 'monto' => 10, 'incidencia_folio' => 'NO-EXISTE']), FinanzasException::class, 'No existe');
});

prueba('Gastos: el IVA no rebasa el total y cancelar requiere Administrador', function () use ($empF, $adminF) {
    global $pdo;
    como($empF, $adminF, 'Administrador');
    lanza(fn() => finGuardarGasto($pdo, 0, ['categoria' => 'Renta', 'concepto' => 'Local', 'monto' => 100, 'iva' => 100]), FinanzasException::class, 'IVA');
    $g = finGuardarGasto($pdo, 0, ['categoria' => 'Renta', 'concepto' => 'Local noviembre', 'monto' => 8000]);
    $g = finGuardarGasto($pdo, (int)$g['id'], ['categoria' => 'Renta', 'concepto' => 'Local noviembre', 'monto' => 8500]);
    igual(8500.0, (float)$g['monto']);
    $adm = usuarioTemporal($empF, 'Administrativo');
    como($empF, $adm, 'Administrativo');
    lanza(fn() => finCancelarGasto($pdo, (int)$g['id'], 'error'), FinanzasException::class, 'administrador');
});

prueba('Cada empresa ve solo sus cuentas y su tablero', function () use ($empF, $adminF, $empG, $adminG) {
    global $pdo;
    como($empG, $adminG, 'Administrador');
    igual(0, count(finListarCuentas($pdo, ['estado' => 'todas'])), 'la empresa B no ve cuentas de A');
    igual(0.0, finResumen($pdo)['por_cobrar']);
    como($empF, $adminF, 'Administrador');
    verdadero(finResumen($pdo)['por_cobrar'] > 0, 'la empresa A sí tiene saldo');
});

grupo('Comprobante de un cobro ya registrado (O-1)');

prueba('O-1: a un cobro registrado sin comprobante se le adjunta uno, y queda en el historial', function () use ($empF, $adminF) {
    global $pdo;
    como($empF, $adminF, 'Administrador');
    $cli = clienteFin('Cliente Comprobante ' . bin2hex(random_bytes(2)));
    $c = finCrearCuenta($pdo, ['cliente_id' => $cli, 'concepto' => 'Servicio', 'subtotal' => 100, 'iva' => 16]);
    $c = finRegistrarCobro($pdo, (int)$c['id'], ['monto' => 116, 'forma' => 'transferencia']);
    $cobro = (int)$c['cobros'][0]['id'];
    finAdjuntarComprobanteCobro($pdo, $cobro, 'uploads/e' . $empF . '/finanzas/cobro_a.pdf');
    igual('uploads/e' . $empF . '/finanzas/cobro_a.pdf', $pdo->query("SELECT comprobante FROM cobros WHERE id = $cobro")->fetchColumn(), 'comprobante:');
    $h = $pdo->query("SELECT accion FROM auditoria WHERE tabla = 'cobros' AND registro_id = '$cobro' ORDER BY id DESC LIMIT 1")->fetchColumn();
    igual('COMPROBANTE', $h, 'acción en el historial:');
});

prueba('O-1: reemplazar el comprobante conserva el anterior en el historial', function () use ($empF, $adminF) {
    global $pdo;
    como($empF, $adminF, 'Administrador');
    $cli = clienteFin('Cliente Reemplazo ' . bin2hex(random_bytes(2)));
    $c = finCrearCuenta($pdo, ['cliente_id' => $cli, 'concepto' => 'Servicio', 'subtotal' => 100]);
    $c = finRegistrarCobro($pdo, (int)$c['id'], ['monto' => 100, 'forma' => 'efectivo'], 'uploads/e' . $empF . '/finanzas/viejo.pdf');
    $cobro = (int)$c['cobros'][0]['id'];
    finAdjuntarComprobanteCobro($pdo, $cobro, 'uploads/e' . $empF . '/finanzas/nuevo.pdf');
    $antes = (string)$pdo->query("SELECT datos_anteriores FROM auditoria WHERE tabla = 'cobros' AND registro_id = '$cobro' AND accion = 'COMPROBANTE' ORDER BY id DESC LIMIT 1")->fetchColumn();
    verdadero(str_contains($antes, 'viejo.pdf'), 'el historial no conserva el comprobante anterior');
});

prueba('O-1: no se adjunta comprobante a un cobro cancelado ni a uno que no existe', function () use ($empF, $adminF) {
    global $pdo;
    como($empF, $adminF, 'Administrador');
    $cli = clienteFin('Cliente Cancelado ' . bin2hex(random_bytes(2)));
    $c = finCrearCuenta($pdo, ['cliente_id' => $cli, 'concepto' => 'Servicio', 'subtotal' => 100]);
    $c = finRegistrarCobro($pdo, (int)$c['id'], ['monto' => 100, 'forma' => 'efectivo']);
    $cobro = (int)$c['cobros'][0]['id'];
    finCancelarCobro($pdo, $cobro, 'Error de captura');
    lanza(fn() => finAdjuntarComprobanteCobro($pdo, $cobro, 'uploads/x.pdf'), FinanzasException::class);
    lanza(fn() => finAdjuntarComprobanteCobro($pdo, 999999, 'uploads/x.pdf'), FinanzasException::class);
});

grupo('IVA de una cuenta que viene de una cotización (O-16)');

/** Cotización aprobada con una partida al 16 % (1000) y una exenta (500). */
function cotizacionMixtaFin(int $cli): array {
    global $pdo;
    $pdo->prepare("INSERT INTO inventario_articulos (sku, nombre, categoria, controla_existencia, unidad, existencia, costo_unitario, precio_venta)
                   VALUES (?, 'Servicio gravado', 'Mano de obra', 0, 'servicio', 0, 100, 1000), (?, 'Servicio exento', 'Mano de obra', 0, 'servicio', 0, 100, 500)")
        ->execute(['G-' . bin2hex(random_bytes(3)), 'X-' . bin2hex(random_bytes(3))]);
    $gravado = (int)$pdo->lastInsertId();  // en un INSERT de varias filas es el id de la primera
    $exento = $gravado + 1;
    $cot = cotGuardar($pdo, 0, ['cliente_id' => $cli, 'fecha' => date('Y-m-d'), 'vigencia_hasta' => date('Y-m-d', strtotime('+15 days')), 'moneda' => 'MXN'],
        [['articulo_id' => $gravado, 'cantidad' => 1, 'tasa_iva' => 0.16], ['articulo_id' => $exento, 'cantidad' => 1, 'tasa_iva' => 0.16, 'es_exento' => 1]]);
    cotCambiarEstatus($pdo, (int)$cot['id'], 'enviada');
    cotCambiarEstatus($pdo, (int)$cot['id'], 'aprobada', ['aprobada_por' => 'Gerente']);
    return finOrigen($pdo, 'cotizacion', (int)$cot['id']);
}

prueba('O-16: la cuenta completa de una cotización con tasas mixtas suma lo aprobado al centavo', function () use ($empF, $adminF) {
    global $pdo;
    como($empF, $adminF, 'Administrador');
    $cli = clienteFin('Cliente Mixto ' . bin2hex(random_bytes(2)));
    $o = cotizacionMixtaFin($cli);
    igual(1660.0, $o['limite'], 'aprobado (1000 + 160 + 500):');
    $c = finCrearCuenta($pdo, ['cliente_id' => $cli, 'concepto' => 'Total', 'origen_tipo' => 'cotizacion', 'origen_id' => $o['id'], 'subtotal' => $o['subtotal'], 'iva' => $o['iva']]);
    igual(1660.0, (float)$c['total'], 'total de la cuenta:');
});

prueba('O-16: el IVA de una cuenta de cotización debe seguir la proporción aprobada (no el 16 % del subtotal)', function () use ($empF, $adminF) {
    global $pdo;
    como($empF, $adminF, 'Administrador');
    $cli = clienteFin('Cliente Atajo ' . bin2hex(random_bytes(2)));
    $o = cotizacionMixtaFin($cli);
    // El atajo 16 % daba 1500 × 0.16 = 240 en vez de 160
    lanza(fn() => finCrearCuenta($pdo, ['cliente_id' => $cli, 'concepto' => 'Anticipo', 'origen_tipo' => 'cotizacion', 'origen_id' => $o['id'], 'subtotal' => 750, 'iva' => 120]),
        FinanzasException::class);
    // Un anticipo del 50 % con la proporción de la cotización sí pasa: 750 × 160 / 1500 = 80
    $c = finCrearCuenta($pdo, ['cliente_id' => $cli, 'concepto' => 'Anticipo', 'origen_tipo' => 'cotizacion', 'origen_id' => $o['id'], 'subtotal' => 750, 'iva' => 80]);
    igual(830.0, (float)$c['total'], 'total del anticipo:');
});

// ---------------------------------------------------------------- IVA por artículo en CXC de OS (migración 024)

grupo('IVA por artículo en CXC de orden de servicio');

prueba('D-IVA-OS-1: OS con artículo al 8% genera CXC con IVA al 8% (no al 16%)', function () use ($empF, $adminF) {
    global $pdo;
    como($empF, $adminF, 'Administrador');
    // Requires tasa_iva column (migration 024); fails before that
    $pdo->prepare("INSERT INTO inventario_articulos (sku, nombre, categoria, controla_existencia, unidad, existencia, costo_unitario, precio_venta, tasa_iva)
                   VALUES ('PART-8PCT', 'Parte al 8%', 'Refacción', 1, 'pza', 10, 400, 1000, 0.08)")->execute();
    $artId = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO incidencias (numero_incidente, cliente, contacto, sucursal, fecha, tecnico, falla, categoria, estatus)
                   VALUES (?, 'Cliente 8pct', '', 'Matriz', CURDATE(), 'Técnico', 'Falla test', 'Otros', 'Pendiente')")
        ->execute(['SD-TEST-' . bin2hex(random_bytes(2))]);
    $incId = (int)$pdo->lastInsertId();
    $pdo->beginTransaction();
    invRegistrarMovimiento($pdo, $artId, -1, 'salida', null, 'incidencia', $incId, 'SD-TEST');
    $pdo->commit();
    $o = finOrigen($pdo, 'incidencia', $incId);
    igual(1000.0, $o['subtotal'], 'subtotal: precio_venta del artículo');
    igual(80.0, $o['iva'], 'IVA debe ser $80 (8% de $1000), no $160 (16%)');
});

prueba('D-IVA-OS-2: OS con artículo exento (tasa_iva=0) no genera IVA', function () use ($empF, $adminF) {
    global $pdo;
    como($empF, $adminF, 'Administrador');
    $pdo->prepare("INSERT INTO inventario_articulos (sku, nombre, categoria, controla_existencia, unidad, existencia, costo_unitario, precio_venta, tasa_iva)
                   VALUES ('PART-EXT', 'Parte exenta', 'Refacción', 1, 'pza', 10, 200, 500, 0.00)")->execute();
    $artId = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO incidencias (numero_incidente, cliente, contacto, sucursal, fecha, tecnico, falla, categoria, estatus)
                   VALUES (?, 'Cliente Exento', '', 'Matriz', CURDATE(), 'Técnico', 'Falla exenta', 'Otros', 'Pendiente')")
        ->execute(['SD-EX-' . bin2hex(random_bytes(2))]);
    $incId = (int)$pdo->lastInsertId();
    $pdo->beginTransaction();
    invRegistrarMovimiento($pdo, $artId, -1, 'salida', null, 'incidencia', $incId, 'SD-EX');
    $pdo->commit();
    $o = finOrigen($pdo, 'incidencia', $incId);
    igual(500.0, $o['subtotal'], 'subtotal correcto');
    igual(0.0, $o['iva'], 'IVA debe ser $0 (artículo al 0%)');
});

prueba('D-IVA-OS-3: OS con mezcla de tasas (16% + 0%) genera IVA correcto, no aplica 16% al total', function () use ($empF, $adminF) {
    global $pdo;
    como($empF, $adminF, 'Administrador');
    $pdo->prepare("INSERT INTO inventario_articulos (sku, nombre, categoria, controla_existencia, unidad, existencia, costo_unitario, precio_venta, tasa_iva)
                   VALUES ('PART-16B', 'Parte al 16%', 'Refacción', 1, 'pza', 10, 500, 1000, 0.16)")->execute();
    $art16 = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO inventario_articulos (sku, nombre, categoria, controla_existencia, unidad, existencia, costo_unitario, precio_venta, tasa_iva)
                   VALUES ('PART-0B', 'Parte al 0%', 'Refacción', 1, 'pza', 10, 250, 500, 0.00)")->execute();
    $art0 = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO incidencias (numero_incidente, cliente, contacto, sucursal, fecha, tecnico, falla, categoria, estatus)
                   VALUES (?, 'Cliente Mixto OS', '', 'Matriz', CURDATE(), 'Técnico', 'Falla mixta', 'Otros', 'Pendiente')")
        ->execute(['SD-MIX-' . bin2hex(random_bytes(2))]);
    $incId = (int)$pdo->lastInsertId();
    $pdo->beginTransaction();
    invRegistrarMovimiento($pdo, $art16, -1, 'salida', null, 'incidencia', $incId, 'SD-MIX');
    invRegistrarMovimiento($pdo, $art0, -1, 'salida', null, 'incidencia', $incId, 'SD-MIX');
    $pdo->commit();
    $o = finOrigen($pdo, 'incidencia', $incId);
    igual(1500.0, $o['subtotal'], 'subtotal: 1000 + 500');
    igual(160.0, $o['iva'], 'IVA debe ser $160 (16% de $1000 + 0% de $500), no $240 (16% de $1500)');
});
