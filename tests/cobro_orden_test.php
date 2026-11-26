<?php
// D-49: una orden de servicio se cobra por UN solo camino. Sin cotización aprobada, la
// cuenta sale de la orden con el importe de sus conceptos aplicados ya cargado; con
// cotización aprobada, sale de la cotización. Nunca de los dos.
require_once __DIR__ . '/../backend/lib/finanzas_helpers.php';
require_once __DIR__ . '/../backend/lib/cotizaciones_helpers.php';
require_once __DIR__ . '/../backend/lib/inventario_helpers.php';

[$empO, $adminO] = empresaTemporal('Cobro Orden');
como($empO, $adminO, 'Administrador');
clienteCatalogo('Cliente Cobro');
$cliO = (int)$pdo->query("SELECT id FROM clientes WHERE nombre = 'Cliente Cobro'")->fetchColumn();

function articuloO(string $nombre, string $cat, float $existencia, float $precio): int {
    global $pdo;
    $sin = in_array($cat, INV_CATEGORIAS_SIN_EXISTENCIA, true);
    $pdo->prepare("INSERT INTO inventario_articulos (sku, nombre, categoria, controla_existencia, unidad, existencia, costo_unitario, precio_venta) VALUES (?, ?, ?, ?, 'pza', ?, 10, ?)")
        ->execute(['O' . bin2hex(random_bytes(4)), $nombre, $cat, $sin ? 0 : 1, $existencia, $precio]);
    return (int)$pdo->lastInsertId();
}
function ordenO(string $estatus = 'Cerrado con factura'): int {
    global $pdo;
    $pdo->prepare("INSERT INTO incidencias (numero_incidente, cliente, contacto, sucursal, fecha, categoria, estatus, falla, tecnico) VALUES (?, 'Cliente Cobro', 'x', '', CURDATE(), 'General', ?, 'x', '')")
        ->execute(['CO-' . bin2hex(random_bytes(3)), $estatus]);
    return (int)$pdo->lastInsertId();
}
function aplicarO(int $emp, int $u, int $orden, int $art, float $cant, bool $servicio = false): void {
    llamar($emp, $u, 'Administrador', 'POST', 'backend/inventario.php', ['get' => ['accion' => $servicio ? 'aplicar_servicio' : 'consumir_incidencia'],
        'json' => ['incidencia_id' => $orden, 'articulo_id' => $art, 'cantidad' => $cant]]);
    como($emp, $u, 'Administrador');
}
function aprobarCotO(int $orden): int {
    global $pdo;
    $cot = cotCrearDesdeIncidencia($pdo, $orden, true);
    cotCambiarEstatus($pdo, (int)$cot['id'], 'enviada');
    cotCambiarEstatus($pdo, (int)$cot['id'], 'aprobada', ['aprobada_por' => 'Cliente']);
    return (int)$cot['id'];
}

grupo('Cobro de la orden de servicio (D-49)');

prueba('D-49: sin cotización, la cuenta de la orden trae cargado el importe de sus conceptos aplicados', function () use ($empO, $adminO) {
    global $pdo;
    $ref = articuloO('Filtro', 'Refacción', 10, 150);
    $mo = articuloO('Mano de obra', 'Mano de obra', 0, 400);
    $orden = ordenO();
    aplicarO($empO, $adminO, $orden, $ref, 2);
    aplicarO($empO, $adminO, $orden, $mo, 1.5, true);
    $o = finOrigen($pdo, 'incidencia', $orden);
    igual('incidencia', $o['tipo'], 'origen:');
    igual(900.0, (float)$o['subtotal'], 'subtotal (2 × 150 + 1.5 × 400):');
    igual(144.0, (float)$o['iva'], 'IVA 16%:');
    igual(2, count($o['detalle'] ?? []), 'conceptos en el detalle:');
});

prueba('D-49: con cotización aprobada, crear la cuenta desde la orden usa la cotización', function () use ($empO, $adminO) {
    global $pdo;
    $mo = articuloO('Visita', 'Servicio', 0, 500);
    $orden = ordenO();
    aplicarO($empO, $adminO, $orden, $mo, 1, true);
    $cot = aprobarCotO($orden);
    $o = finOrigen($pdo, 'incidencia', $orden);
    igual('cotizacion', $o['tipo'], 'origen:');
    igual($cot, (int)$o['id'], 'cotización:');
});

prueba('D-49: si la orden ya tiene su cuenta, su cotización aprobada ya no se cobra aparte', function () use ($empO, $adminO, $cliO) {
    global $pdo;
    $mo = articuloO('Diagnóstico', 'Servicio', 0, 300);
    $orden = ordenO();
    aplicarO($empO, $adminO, $orden, $mo, 1, true);
    finCrearCuenta($pdo, ['cliente_id' => $cliO, 'concepto' => 'Orden', 'subtotal' => 300, 'iva' => 48, 'origen_tipo' => 'incidencia', 'origen_id' => $orden]);
    $cot = aprobarCotO($orden);
    lanza(fn() => finCrearCuenta($pdo, ['cliente_id' => $cliO, 'concepto' => 'Cot', 'subtotal' => 300, 'iva' => 48, 'origen_tipo' => 'cotizacion', 'origen_id' => $cot]),
        FinanzasException::class, 'ya tiene');
    $pend = array_filter(finPendientesDeRegistrar($pdo, 1000), fn($p) => $p['tipo'] === 'cotizacion' && $p['id'] === $cot);
    igual([], array_values($pend), 'la cotización sigue como pendiente:');
});

prueba('D-49: una orden cerrada con cotización aprobada no aparece como pendiente aparte (solo su cotización)', function () use ($empO, $adminO) {
    global $pdo;
    $mo = articuloO('Mantenimiento', 'Servicio', 0, 800);
    $orden = ordenO();
    aplicarO($empO, $adminO, $orden, $mo, 1, true);
    $cot = aprobarCotO($orden);
    $pend = finPendientesDeRegistrar($pdo, 1000);
    igual([], array_values(array_filter($pend, fn($p) => $p['tipo'] === 'incidencia' && $p['id'] === $orden)), 'la orden aparece como pendiente:');
    igual(1, count(array_filter($pend, fn($p) => $p['tipo'] === 'cotizacion' && $p['id'] === $cot)), 'la cotización como pendiente:');
});

prueba('D-49: la orden cerrada sin cotización aparece como pendiente con el importe de sus conceptos', function () use ($empO, $adminO) {
    global $pdo;
    $mo = articuloO('Revisión', 'Servicio', 0, 250);
    $orden = ordenO();
    aplicarO($empO, $adminO, $orden, $mo, 2, true);
    $p = array_values(array_filter(finPendientesDeRegistrar($pdo, 1000), fn($p) => $p['tipo'] === 'incidencia' && $p['id'] === $orden))[0] ?? [];
    igual(580.0, (float)($p['importe'] ?? 0), 'importe con IVA (2 × 250 × 1.16):');
});

// ---------------------------------------------------------------- auditoría integral 2026-11-24
function ventaO(): int {
    global $pdo;
    $pdo->prepare("INSERT INTO ventas (folio, cliente, fecha_registro) VALUES (?, 'Cliente Cobro', CURDATE())")->execute(['VO-' . bin2hex(random_bytes(3))]);
    return (int)$pdo->lastInsertId();
}
function cuentaDatosO(int $cli, string $tipo, int $id, array $extra = []): array {
    return $extra + ['cliente_id' => $cli, 'concepto' => 'Cuenta', 'subtotal' => 100, 'iva' => 16, 'origen_tipo' => $tipo, 'origen_id' => $id];
}

prueba('L-1 · D-49: una orden con cuenta vigente no genera una segunda cuenta sin confirmar', function () use ($empO, $adminO, $cliO) {
    global $pdo;
    $orden = ordenO();
    finCrearCuenta($pdo, cuentaDatosO($cliO, 'incidencia', $orden));
    lanza(fn() => finCrearCuenta($pdo, cuentaDatosO($cliO, 'incidencia', $orden)), FinanzasException::class, 'ya tiene la cuenta');
    // Un cargo adicional legítimo se registra confirmando
    finCrearCuenta($pdo, cuentaDatosO($cliO, 'incidencia', $orden, ['forzar' => 1]));
    igual(2, (int)$pdo->query("SELECT COUNT(*) FROM cuentas_cobrar WHERE origen_tipo = 'incidencia' AND origen_id = $orden")->fetchColumn(), 'cuentas de la orden:');
});

prueba('L-1 · D-49: una venta con cuenta vigente no genera una segunda cuenta sin confirmar', function () use ($cliO) {
    global $pdo;
    $venta = ventaO();
    finCrearCuenta($pdo, cuentaDatosO($cliO, 'venta', $venta));
    lanza(fn() => finCrearCuenta($pdo, cuentaDatosO($cliO, 'venta', $venta)), FinanzasException::class, 'ya tiene la cuenta');
});

prueba('L-1 · D-49: crear la cuenta de una orden con doble clic simultáneo deja una sola cuenta', function () use ($empO, $adminO, $cliO) {
    global $pdo;
    $orden = ordenO();
    llamarSimultaneo(4, $empO, $adminO, 'Administrador', 'POST', 'backend/finanzas.php', ['get' => ['accion' => 'crear_cuenta'], 'json' => cuentaDatosO($cliO, 'incidencia', $orden)]);
    como($empO, $adminO, 'Administrador');
    igual(1, (int)$pdo->query("SELECT COUNT(*) FROM cuentas_cobrar WHERE origen_tipo = 'incidencia' AND origen_id = $orden")->fetchColumn(), 'cuentas de la orden:');
});

prueba('L-2 · H-03 · D-55: la venta de una cotización comparte lo aprobado: ya cobrada la cotización, la venta no se cobra (y al revés)', function () use ($empO, $adminO, $cliO) {
    global $pdo;
    $mo = articuloO('Equipo vendido', 'Servicio', 0, 1000);
    $orden = ordenO();
    aplicarO($empO, $adminO, $orden, $mo, 1, true);
    $cot = aprobarCotO($orden);
    $venta = ventaO();
    $pdo->prepare("INSERT INTO cotizacion_ventas (cotizacion_id, descripcion, cantidad, estado, venta_id) VALUES (?, 'Equipo', 1, 'registrada', ?)")->execute([$cot, $venta]);
    finCrearCuenta($pdo, cuentaDatosO($cliO, 'cotizacion', $cot, ['subtotal' => 1000, 'iva' => 160]));
    lanza(fn() => finCrearCuenta($pdo, cuentaDatosO($cliO, 'venta', $venta)), FinanzasException::class, 'solo faltan');
    // Al revés: la venta se cobró primero, la cotización ya no
    $orden2 = ordenO();
    aplicarO($empO, $adminO, $orden2, $mo, 1, true);
    $cot2 = aprobarCotO($orden2);
    $venta2 = ventaO();
    $pdo->prepare("INSERT INTO cotizacion_ventas (cotizacion_id, descripcion, cantidad, estado, venta_id) VALUES (?, 'Equipo', 1, 'registrada', ?)")->execute([$cot2, $venta2]);
    finCrearCuenta($pdo, cuentaDatosO($cliO, 'venta', $venta2));
    lanza(fn() => finCrearCuenta($pdo, cuentaDatosO($cliO, 'cotizacion', $cot2, ['subtotal' => 1000, 'iva' => 160])), FinanzasException::class, 'solo faltan');
});

prueba('L-10 · D-51: el importe de una refacción aplicada se congela al precio del día en que se aplicó', function () use ($empO, $adminO) {
    global $pdo;
    $ref = articuloO('Sello', 'Refacción', 10, 1000);
    $orden = ordenO();
    aplicarO($empO, $adminO, $orden, $ref, 1);
    $pdo->prepare('UPDATE inventario_articulos SET precio_venta = 1200 WHERE id = ?')->execute([$ref]);
    aplicarO($empO, $adminO, $orden, $ref, 1);
    $o = finOrigen($pdo, 'incidencia', $orden);
    igual(2200.0, (float)$o['subtotal'], 'subtotal (1000 + 1200):');
});

// ---------------------------------------------------------------- auditoría Argon del bloque 1 (2026-11-24)
function devolverO(int $emp, int $u, int $orden, int $art, ?float $cant = null): array {
    $r = llamar($emp, $u, 'Administrador', 'POST', 'backend/inventario.php', ['get' => ['accion' => 'devolver_incidencia'],
        'json' => ['incidencia_id' => $orden, 'articulo_id' => $art] + ($cant !== null ? ['cantidad' => $cant] : [])]);
    como($emp, $u, 'Administrador');
    return $r;
}
function subtotalO(int $orden): float {
    global $pdo;
    return (float)(finOrigen($pdo, 'incidencia', $orden)['subtotal'] ?? 0);
}

prueba('H-01 · D-51: «Cotizar lo aplicado» usa el precio congelado e incluye artículos desactivados', function () use ($empO, $adminO) {
    global $pdo;
    $ref = articuloO('Bomba', 'Refacción', 10, 1000);
    $srv = articuloO('Instalación', 'Servicio', 0, 500);
    $orden = ordenO();
    aplicarO($empO, $adminO, $orden, $ref, 1);
    aplicarO($empO, $adminO, $orden, $srv, 1, true);
    $pdo->prepare('UPDATE inventario_articulos SET precio_venta = 1200 WHERE id = ?')->execute([$ref]);
    $pdo->prepare('UPDATE inventario_articulos SET precio_venta = 800, activo = 0 WHERE id = ?')->execute([$srv]);
    $cot = cotCrearDesdeIncidencia($pdo, $orden, true);
    $precios = [];
    foreach ($cot['partidas'] as $p) $precios[(int)$p['articulo_id']] = (float)$p['precio_unitario'];
    igual(1000.0, $precios[$ref] ?? null, 'refacción:');
    igual(500.0, $precios[$srv] ?? null, 'servicio desactivado:');
    $r = llamar($empO, $adminO, 'Administrador', 'GET', 'backend/inventario.php', ['get' => ['accion' => 'consumos_incidencia', 'incidencia_id' => $orden]]);
    como($empO, $adminO, 'Administrador');
    igual(1000.0, (float)($r['json']['consumos'][0]['importe'] ?? 0), 'importe en Conceptos aplicados:');
});

prueba('H-02 · D-51: devolver todo y volver a aplicar deja el importe exacto aunque el precio haya cambiado', function () use ($empO, $adminO) {
    global $pdo;
    $ref = articuloO('Sello B', 'Refacción', 10, 1000);
    $orden = ordenO();
    aplicarO($empO, $adminO, $orden, $ref, 1);
    $pdo->prepare('UPDATE inventario_articulos SET precio_venta = 1200 WHERE id = ?')->execute([$ref]);
    aplicarO($empO, $adminO, $orden, $ref, 1);
    devolverO($empO, $adminO, $orden, $ref);          // las 2 piezas
    aplicarO($empO, $adminO, $orden, $ref, 1);         // 1 pieza nueva a 1200
    igual(1200.0, subtotalO($orden), 'subtotal tras devolver todo y aplicar 1:');
});

prueba('H-02 · D-51: dos devoluciones parciales regresan cada pieza a su precio', function () use ($empO, $adminO) {
    global $pdo;
    $ref = articuloO('Sello C', 'Refacción', 10, 1000);
    $orden = ordenO();
    aplicarO($empO, $adminO, $orden, $ref, 1);
    $pdo->prepare('UPDATE inventario_articulos SET precio_venta = 1200 WHERE id = ?')->execute([$ref]);
    aplicarO($empO, $adminO, $orden, $ref, 1);
    devolverO($empO, $adminO, $orden, $ref, 1);        // sale la última (1200): queda 1000
    igual(1000.0, subtotalO($orden), 'tras la 1.ª devolución:');
    devolverO($empO, $adminO, $orden, $ref, 1);
    igual(0.0, subtotalO($orden), 'tras la 2.ª devolución:');
});

prueba('H-02 · D-51: una salida anterior a la migración (sin precio) y su devolución se valúan igual', function () use ($empO, $adminO) {
    global $pdo;
    $ref = articuloO('Sello D', 'Refacción', 10, 1000);
    $orden = ordenO();
    aplicarO($empO, $adminO, $orden, $ref, 1);
    $pdo->prepare("UPDATE inventario_movimientos SET precio_unitario = NULL WHERE referencia_tipo = 'incidencia' AND referencia_id = ?")->execute([$orden]);
    devolverO($empO, $adminO, $orden, $ref);
    $pdo->prepare('UPDATE inventario_articulos SET precio_venta = 1500 WHERE id = ?')->execute([$ref]);
    aplicarO($empO, $adminO, $orden, $ref, 1);
    igual(1500.0, subtotalO($orden), 'subtotal:');
});

prueba('H-04 · D-55: la venta de una cotización cuya orden ya tiene cuenta no se cobra otra vez (y al revés)', function () use ($empO, $adminO, $cliO) {
    global $pdo;
    $srv = articuloO('Equipo H4', 'Servicio', 0, 900);
    $orden = ordenO();
    aplicarO($empO, $adminO, $orden, $srv, 1, true);
    finCrearCuenta($pdo, cuentaDatosO($cliO, 'incidencia', $orden));
    $cot = cotCrearDesdeIncidencia($pdo, $orden, true);
    $venta = ventaO();
    $pdo->prepare("INSERT INTO cotizacion_ventas (cotizacion_id, descripcion, cantidad, estado, venta_id) VALUES (?, 'Equipo', 1, 'registrada', ?)")->execute([(int)$cot['id'], $venta]);
    lanza(fn() => finCrearCuenta($pdo, cuentaDatosO($cliO, 'venta', $venta)), FinanzasException::class, 'ya tiene su cuenta');
    // Al revés: la venta se cobró primero; la orden ya no se cobra por sus conceptos
    $orden2 = ordenO();
    aplicarO($empO, $adminO, $orden2, $srv, 1, true);
    $cot2 = cotCrearDesdeIncidencia($pdo, $orden2, true);
    $venta2 = ventaO();
    $pdo->prepare("INSERT INTO cotizacion_ventas (cotizacion_id, descripcion, cantidad, estado, venta_id) VALUES (?, 'Equipo', 1, 'registrada', ?)")->execute([(int)$cot2['id'], $venta2]);
    finCrearCuenta($pdo, cuentaDatosO($cliO, 'venta', $venta2));
    lanza(fn() => finCrearCuenta($pdo, cuentaDatosO($cliO, 'incidencia', $orden2)), FinanzasException::class, 'ya se cobra');
});

prueba('H-05 · D-55: una cotización cobrada por su venta ya no aparece como pendiente', function () use ($empO, $adminO, $cliO) {
    global $pdo;
    $srv = articuloO('Equipo H5', 'Servicio', 0, 700);
    $orden = ordenO();
    aplicarO($empO, $adminO, $orden, $srv, 1, true);
    $cot = aprobarCotO($orden);
    $venta = ventaO();
    $pdo->prepare("INSERT INTO cotizacion_ventas (cotizacion_id, descripcion, cantidad, estado, venta_id) VALUES (?, 'Equipo', 1, 'registrada', ?)")->execute([$cot, $venta]);
    finCrearCuenta($pdo, cuentaDatosO($cliO, 'venta', $venta, ['subtotal' => 700, 'iva' => 112]));
    $pend = array_filter(finPendientesDeRegistrar($pdo, 1000), fn($p) => $p['tipo'] === 'cotizacion' && $p['id'] === $cot);
    igual([], array_values($pend), 'la cotización sigue como pendiente:');
});

prueba('H-06 · D-55: la cuenta adicional confirmada queda marcada en el historial', function () use ($cliO) {
    global $pdo;
    $orden = ordenO();
    finCrearCuenta($pdo, cuentaDatosO($cliO, 'incidencia', $orden));
    $c = finCrearCuenta($pdo, cuentaDatosO($cliO, 'incidencia', $orden, ['forzar' => 1]));
    $st = $pdo->prepare("SELECT datos_nuevos FROM auditoria WHERE tabla = 'cuentas_cobrar' AND registro_id = ? AND accion = 'CREATE'");
    $st->execute([(string)$c['id']]);
    verdadero(strpos((string)$st->fetchColumn(), 'cuenta_adicional_confirmada') !== false, 'sin constancia en el historial');
});

// ---------------------------------------------------------------- auditoría Argon, bloques 1 y 2 (2026-11-24)
prueba('H-07 · D-55: la rentabilidad cuenta el ingreso de la orden cobrada por la venta de su cotización', function () use ($empO, $adminO, $cliO) {
    global $pdo;
    $srv = articuloO('Equipo H7', 'Servicio', 0, 1000);
    $orden = ordenO();
    aplicarO($empO, $adminO, $orden, $srv, 1, true);
    $cot = aprobarCotO($orden);
    $venta = ventaO();
    $pdo->prepare("INSERT INTO cotizacion_ventas (cotizacion_id, descripcion, cantidad, estado, venta_id) VALUES (?, 'Equipo', 1, 'registrada', ?)")->execute([$cot, $venta]);
    finCrearCuenta($pdo, cuentaDatosO($cliO, 'venta', $venta, ['subtotal' => 1000, 'iva' => 160]));
    $fila = array_values(array_filter(finRentabilidad($pdo, date('Y-m-01', strtotime('-1 month')), date('Y-m-d'))['ordenes'], fn($o) => (int)$o['id'] === $orden))[0] ?? [];
    igual(1000.0, (float)($fila['ingreso'] ?? 0), 'ingreso de la orden:');
});

prueba('H-08 · D-55: no se elimina una venta ni una orden con cuenta por cobrar vigente', function () use ($empO, $adminO, $cliO) {
    global $pdo;
    $venta = ventaO();
    finCrearCuenta($pdo, cuentaDatosO($cliO, 'venta', $venta));
    $r = llamar($empO, $adminO, 'Administrador', 'POST', 'backend/eliminar_venta.php', ['json' => ['id' => $venta]]);
    como($empO, $adminO, 'Administrador');
    igual(409, $r['http'], 'eliminar venta con cuenta:');
    $orden = ordenO();
    finCrearCuenta($pdo, cuentaDatosO($cliO, 'incidencia', $orden));
    $r = llamar($empO, $adminO, 'Administrador', 'POST', 'backend/eliminar-incidencia.php', ['post' => ['id' => $orden], 'json' => ['id' => $orden]]);
    como($empO, $adminO, 'Administrador');
    igual(409, $r['http'], 'eliminar orden con cuenta: ' . $r['texto']);
    igual(1, (int)$pdo->query("SELECT COUNT(*) FROM incidencias WHERE id = $orden")->fetchColumn(), 'la orden se borró:');
});

prueba('H-09 · D-55: una orden con cuenta propia acepta un cargo adicional confirmado aunque después se apruebe su cotización', function () use ($empO, $adminO, $cliO) {
    global $pdo;
    $srv = articuloO('Servicio H9', 'Servicio', 0, 400);
    $orden = ordenO();
    aplicarO($empO, $adminO, $orden, $srv, 1, true);
    finCrearCuenta($pdo, cuentaDatosO($cliO, 'incidencia', $orden));
    aprobarCotO($orden);
    lanza(fn() => finCrearCuenta($pdo, cuentaDatosO($cliO, 'incidencia', $orden)), FinanzasException::class, 'ya tiene la cuenta');
    finCrearCuenta($pdo, cuentaDatosO($cliO, 'incidencia', $orden, ['forzar' => 1]));
    igual(2, (int)$pdo->query("SELECT COUNT(*) FROM cuentas_cobrar WHERE origen_tipo = 'incidencia' AND origen_id = $orden")->fetchColumn(), 'cuentas de la orden:');
});

prueba('H-10 · D-51: «Cotizar lo aplicado» no promedia precios distintos (sin descuadre de centavos)', function () use ($empO, $adminO) {
    global $pdo;
    $ref = articuloO('Filtro H10', 'Refacción', 10, 100);
    $orden = ordenO();
    aplicarO($empO, $adminO, $orden, $ref, 2);
    $pdo->prepare('UPDATE inventario_articulos SET precio_venta = 110 WHERE id = ?')->execute([$ref]);
    aplicarO($empO, $adminO, $orden, $ref, 1);
    $cot = cotCrearDesdeIncidencia($pdo, $orden, true);
    igual(310.0, (float)$cot['subtotal'], 'subtotal de la cotización:');
});

prueba('H-03 · D-55: cobrar la venta de una cotización deja cobrar el resto de lo aprobado', function () use ($empO, $adminO, $cliO) {
    global $pdo;
    $srv = articuloO('Equipo H3', 'Servicio', 0, 1000);
    $orden = ordenO();
    aplicarO($empO, $adminO, $orden, $srv, 1, true);
    $cot = aprobarCotO($orden);
    $venta = ventaO();
    $pdo->prepare("INSERT INTO cotizacion_ventas (cotizacion_id, descripcion, cantidad, estado, venta_id) VALUES (?, 'Equipo', 1, 'registrada', ?)")->execute([$cot, $venta]);
    finCrearCuenta($pdo, cuentaDatosO($cliO, 'venta', $venta, ['subtotal' => 500, 'iva' => 80]));
    $o = finOrigen($pdo, 'incidencia', $orden);
    igual(580.0, (float)$o['registrado'], 'registrado de la cotización (incluye la venta):');
    finCrearCuenta($pdo, cuentaDatosO($cliO, 'cotizacion', $cot, ['subtotal' => 500, 'iva' => 80]));
    lanza(fn() => finCrearCuenta($pdo, cuentaDatosO($cliO, 'cotizacion', $cot, ['subtotal' => 1, 'iva' => 0.16])), FinanzasException::class, 'solo faltan');
});
