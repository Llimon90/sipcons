<?php
// Pruebas del módulo de cotizaciones (backend/lib/cotizaciones_helpers.php).
require_once __DIR__ . '/../backend/lib/cotizaciones_helpers.php';

// ---------------------------------------------------------------- datos de apoyo

[$empA, $adminA] = empresaTemporal('Empresa A');
[$empB, $adminB] = empresaTemporal('Empresa B');

/** Cliente, artículos y servicio de la empresa activa. */
function catalogoPrueba(): array {
    global $pdo;
    $pdo->exec("INSERT INTO clientes (nombre, rfc, direccion, telefono, contactos, email) VALUES ('Hotel de Prueba', 'XAXX010101000', 'Calle 1', '555', 'Ana', 'a@x.test')");
    $cliente = (int)$pdo->lastInsertId();
    $pdo->prepare('INSERT INTO cliente_contactos (cliente_id, nombre) VALUES (?, ?)')->execute([$cliente, 'Ana Pérez']);
    $contacto = (int)$pdo->lastInsertId();
    $art = function (string $sku, string $nombre, string $cat, float $costo, float $precio, float $existencia, string $modelo = '') use ($pdo) {
        $pdo->prepare('INSERT INTO inventario_articulos (sku, nombre, categoria, controla_existencia, modelo, unidad, existencia, stock_minimo, costo_unitario, precio_venta)
                       VALUES (?, ?, ?, ?, ?, ?, ?, 0, ?, ?)')
            ->execute([$sku, $nombre, $cat, in_array($cat, INV_CATEGORIAS_SIN_EXISTENCIA, true) ? 0 : 1, $modelo ?: null,
                       in_array($cat, INV_CATEGORIAS_SIN_EXISTENCIA, true) ? 'servicio' : 'pza', $existencia, $costo, $precio]);
        return (int)$pdo->lastInsertId();
    };
    return [
        'cliente' => $cliente, 'contacto' => $contacto,
        'ms2' => $art('MS-2TR', 'Minisplit 2 TR', 'Equipo', 14200, 19800, 5, '42KHQ024'),
        'ms15' => $art('MS-15TR', 'Minisplit 1.5 TR', 'Equipo', 11800, 16500, 4, '42KHQ018'),
        'cap' => $art('CAP-355', 'Capacitor 35/5', 'Refacción', 185, 450, 10),
        'mo' => $art('MO-INST', 'Instalación de minisplit', 'Mano de obra', 900, 2500, 0),
    ];
}

function encabezado(array $c, array $extra = []): array {
    return $extra + ['cliente_id' => $c['cliente'], 'contacto_id' => $c['contacto'], 'sucursal' => 'Matriz',
        'fecha' => date('Y-m-d'), 'vigencia_hasta' => date('Y-m-d', strtotime('+15 days')), 'moneda' => 'MXN', 'precios_incluyen_iva' => 0];
}

como($empA, $adminA, 'Administrador', 'Admin A');
$cA = catalogoPrueba();
como($empB, $adminB, 'Administrador', 'Admin B');
$cB = catalogoPrueba();
como($empA, $adminA, 'Administrador', 'Admin A');

// ---------------------------------------------------------------- cálculo

grupo('Cálculo de totales');

prueba('IVA 16% con descuento por partida', function () {
    $t = cotCalcularTotales([['cantidad' => 2, 'precio_unitario' => 1000, 'descuento_pct' => 10, 'tasa_iva' => 0.16]], false);
    igual(2000.0, $t['subtotal']); igual(200.0, $t['descuento_total']); igual(288.0, $t['iva_total']); igual(2088.0, $t['total']);
});

prueba('IVA 8% (zona fronteriza)', function () {
    $t = cotCalcularTotales([['cantidad' => 1, 'precio_unitario' => 1000, 'tasa_iva' => 0.08]], false);
    igual(80.0, $t['iva_total']); igual(1080.0, $t['total']); igual('IVA 8%', $t['iva_por_tasa'][0]['etiqueta']);
});

prueba('Tasa 0% y exento no causan IVA y se distinguen en el desglose', function () {
    $t = cotCalcularTotales([
        ['cantidad' => 1, 'precio_unitario' => 500, 'tasa_iva' => 0.0],
        ['cantidad' => 1, 'precio_unitario' => 300, 'tasa_iva' => 0.16, 'es_exento' => 1],
    ], false);
    igual(0.0, $t['iva_total']); igual(800.0, $t['total']);
    $etiquetas = array_column($t['iva_por_tasa'], 'etiqueta');
    verdadero(in_array('Exento', $etiquetas, true) && in_array('IVA 0%', $etiquetas, true), 'desglose: ' . implode(', ', $etiquetas));
});

prueba('Precios con IVA incluido respetan el total capturado', function () {
    $t = cotCalcularTotales([['cantidad' => 1, 'precio_unitario' => 1160, 'tasa_iva' => 0.16]], true);
    igual(1000.0, $t['partidas'][0]['importe']); igual(160.0, $t['iva_total']); igual(1160.0, $t['total']);
    $t = cotCalcularTotales([['cantidad' => 1, 'precio_unitario' => 1160, 'descuento_pct' => 10, 'tasa_iva' => 0.16]], true);
    igual(1044.0, $t['total']); igual(900.0, $t['partidas'][0]['importe']); igual(144.0, $t['iva_total']);
});

prueba('Redondeo por partida y luego suma', function () {
    // 0.03 × 16% = 0.0048 → 0.00 por partida; sumar antes de redondear daría 0.01
    $p = ['cantidad' => 1, 'precio_unitario' => 0.03, 'tasa_iva' => 0.16];
    $t = cotCalcularTotales([$p, $p, $p], false);
    igual(0.0, $t['iva_total']); igual(0.09, $t['total']);
    $t = cotCalcularTotales([['cantidad' => 3, 'precio_unitario' => 33.333, 'tasa_iva' => 0.16]], false);
    igual(99.99, $t['subtotal']); igual(16.0, $t['iva_total']);
});

prueba('Varias tasas se desglosan por separado', function () {
    $t = cotCalcularTotales([['cantidad' => 1, 'precio_unitario' => 100, 'tasa_iva' => 0.16], ['cantidad' => 1, 'precio_unitario' => 100, 'tasa_iva' => 0.08]], false);
    igual(2, count($t['iva_por_tasa'])); igual(24.0, $t['iva_total']);
});

prueba('Tasa de IVA no permitida se rechaza', function () {
    lanza(fn() => cotCalcularTotales([['cantidad' => 1, 'precio_unitario' => 100, 'tasa_iva' => 0.10]], false), CotizacionException::class, 'Tasa de IVA');
});

prueba('USD: artículo MXN en cotización USD se convierte con el tipo de cambio', function () use ($cA) {
    global $pdo;
    // ms2: precio_venta=19800 MXN, costo=14200 MXN; cotización en USD tc=20 → 990 USD, 710 USD
    $p = cotPrepararPartidas($pdo, [['articulo_id' => $cA['ms2'], 'cantidad' => 1]], [], 20.0, 0.16, [], 'USD');
    igual(990.0, $p[0]['precio_unitario']); igual(710.0, $p[0]['costo_unitario']);
});

prueba('Margen interno: costo, margen y porcentaje', function () {
    $t = cotCalcularTotales([['cantidad' => 2, 'precio_unitario' => 1000, 'costo_unitario' => 700, 'tasa_iva' => 0.16]], false);
    igual(1400.0, $t['costo_total']); igual(600.0, $t['margen']); igual(30.0, $t['margen_pct']);
});

// ---------------------------------------------------------------- folios y aislamiento

grupo('Folios y aislamiento multiempresa');

$cot1 = $cot2 = null;
prueba('Folios consecutivos por empresa: dos empresas no comparten numeración', function () use ($empA, $empB, $adminA, $adminB, $cA, $cB, &$cot1, &$cot2) {
    global $pdo;
    como($empA, $adminA, 'Administrador');
    $cot1 = cotGuardar($pdo, 0, encabezado($cA), [['articulo_id' => $cA['ms2'], 'cantidad' => 1]]);
    $cot2 = cotGuardar($pdo, 0, encabezado($cA), [['articulo_id' => $cA['cap'], 'cantidad' => 2]]);
    igual('COT-0001', $cot1['folio']); igual('COT-0002', $cot2['folio']);
    como($empB, $adminB, 'Administrador');
    $b = cotGuardar($pdo, 0, encabezado($cB), [['articulo_id' => $cB['cap'], 'cantidad' => 1]]);
    igual('COT-0001', $b['folio']);
});

prueba('Una empresa no ve las cotizaciones de otra', function () use ($empB, $adminB, &$cot2) {
    global $pdo;
    como($empB, $adminB, 'Administrador');
    // En la empresa B, el id de la COT-0002 de A no existe (otra tabla)
    $enB = cotFila($pdo, (int)$cot2['id']);
    verdadero($enB === null || $enB['folio'] !== 'COT-0002', 'la empresa B vio una cotización de A');
});

prueba('El prefijo configurable cambia los folios nuevos', function () use ($empA, $adminA, $cA) {
    global $pdo;
    $marca = json_decode($pdo->query("SELECT marca FROM empresas WHERE id = $empA")->fetchColumn(), true);
    $marca['cotizaciones'] = ['prefijo' => 'PRE'];
    $pdo->prepare('UPDATE empresas SET marca = ? WHERE id = ?')->execute([json_encode($marca), $empA]);
    como($empA, $adminA, 'Administrador');
    $c = cotGuardar($pdo, 0, encabezado($cA), []);
    igual('PRE-0003', $c['folio']);
    unset($marca['cotizaciones']);
    $pdo->prepare('UPDATE empresas SET marca = ? WHERE id = ?')->execute([json_encode($marca), $empA]);
    como($empA, $adminA, 'Administrador');
    cotEliminar($pdo, (int)$c['id']);
});

// ---------------------------------------------------------------- estatus y versiones

grupo('Estatus, edición y versiones');

prueba('Transiciones inválidas se rechazan', function () use (&$cot1) {
    global $pdo;
    lanza(fn() => cotCambiarEstatus($pdo, (int)$cot1['id'], 'aprobada', ['aprobada_por' => 'X']), CotizacionException::class, 'No se puede pasar');
    lanza(fn() => cotCambiarEstatus($pdo, (int)$cot1['id'], 'convertida'), CotizacionException::class, 'Estatus no válido');
});

prueba('No se envía una cotización sin partidas', function () use ($cA) {
    global $pdo;
    $vacia = cotGuardar($pdo, 0, encabezado($cA), []);
    lanza(fn() => cotCambiarEstatus($pdo, (int)$vacia['id'], 'enviada'), CotizacionException::class, 'al menos una partida');
    cotEliminar($pdo, (int)$vacia['id']);
});

prueba('Enviada: ya no se edita y no se elimina', function () use ($cA, &$cot1) {
    global $pdo;
    [$c] = cotCambiarEstatus($pdo, (int)$cot1['id'], 'enviada');
    igual('enviada', $c['estatus']);
    lanza(fn() => cotGuardar($pdo, (int)$cot1['id'], encabezado($cA), [['articulo_id' => $cA['cap'], 'cantidad' => 1]]), CotizacionException::class, 'nueva versión');
    lanza(fn() => cotEliminar($pdo, (int)$cot1['id']), CotizacionException::class, 'Solo se eliminan borradores');
});

prueba('Nueva versión: -v2 enlazada y la anterior queda de consulta', function () use (&$cot1) {
    global $pdo;
    $v2 = cotNuevaVersion($pdo, (int)$cot1['id']);
    igual('COT-0001-v2', $v2['folio']); igual(2, (int)$v2['version']); igual('borrador', $v2['estatus']);
    igual((int)$cot1['id'], (int)$v2['cotizacion_origen_id']);
    igual(count($cot1['partidas']), count($v2['partidas']), 'partidas copiadas');
    $anterior = cotFila($pdo, (int)$cot1['id']);
    igual((int)$v2['id'], (int)$anterior['reemplazada_por']);
    lanza(fn() => cotNuevaVersion($pdo, (int)$cot1['id']), CotizacionException::class, 'sustituida');
    lanza(fn() => cotCambiarEstatus($pdo, (int)$cot1['id'], 'aprobada', ['aprobada_por' => 'X']), CotizacionException::class, 'sustituida');
});

prueba('Rechazo exige motivo', function () use ($cA) {
    global $pdo;
    $c = cotGuardar($pdo, 0, encabezado($cA), [['articulo_id' => $cA['cap'], 'cantidad' => 1]]);
    cotCambiarEstatus($pdo, (int)$c['id'], 'enviada');
    lanza(fn() => cotCambiarEstatus($pdo, (int)$c['id'], 'rechazada', []), CotizacionException::class, 'motivo');
    [$r] = cotCambiarEstatus($pdo, (int)$c['id'], 'rechazada', ['motivo' => 'Precio alto']);
    igual('rechazada', $r['estatus']); igual('Precio alto', $r['motivo_rechazo']);
});

prueba('Al consultar, una enviada con vigencia pasada queda vencida', function () use ($cA) {
    global $pdo;
    $c = cotGuardar($pdo, 0, encabezado($cA), [['articulo_id' => $cA['cap'], 'cantidad' => 1]]);
    cotCambiarEstatus($pdo, (int)$c['id'], 'enviada');
    $pdo->prepare('UPDATE cotizaciones SET fecha = DATE_SUB(CURDATE(), INTERVAL 20 DAY), vigencia_hasta = DATE_SUB(CURDATE(), INTERVAL 1 DAY) WHERE id = ?')->execute([$c['id']]);
    cotMarcarVencidas($pdo);
    igual('vencida', cotFila($pdo, (int)$c['id'])['estatus']);
});

prueba('Edición concurrente: una versión vieja recibe 409', function () use ($cA) {
    global $pdo;
    $c = cotGuardar($pdo, 0, encabezado($cA), [['articulo_id' => $cA['cap'], 'cantidad' => 1]]);
    $viejaVersion = $c['version_huella'];
    cotGuardar($pdo, (int)$c['id'], encabezado($cA, ['sucursal' => 'Otra']), [['id' => $c['partidas'][0]['id'], 'cantidad' => 2]], $viejaVersion);
    $e = lanza(fn() => cotGuardar($pdo, (int)$c['id'], encabezado($cA), [['id' => $c['partidas'][0]['id'], 'cantidad' => 3]], $viejaVersion), CotizacionException::class, 'conflicto');
    igual(409, $e->http);
    $ok = cotGuardar($pdo, (int)$c['id'], encabezado($cA), [['id' => $c['partidas'][0]['id'], 'cantidad' => 3]], $viejaVersion, true);
    igual(3.0, (float)$ok['partidas'][0]['cantidad']);
});

// ---------------------------------------------------------------- permisos

grupo('Permisos por rol y descuentos');

prueba('Técnico: sin acceso al módulo no crea cotizaciones sueltas', function () use ($empA, $cA) {
    global $pdo;
    $tec = usuarioTemporal($empA, 'Técnico');
    como($empA, $tec, 'Técnico');
    lanza(fn() => cotGuardar($pdo, 0, encabezado($cA), []), CotizacionException::class, 'sin_permiso');
});

prueba('Técnico: crea un borrador desde su incidencia, lo edita, pero no lo envía', function () use ($empA, $adminA, $cA) {
    global $pdo;
    como($empA, $adminA, 'Administrador');
    $pdo->exec("INSERT INTO incidencias (numero_incidente, cliente, contacto, sucursal, fecha, tecnico, falla, categoria, estatus)
                VALUES ('SD-0001', 'Hotel de Prueba', 'Ana Pérez', 'Matriz', CURDATE(), 'Técnico de prueba', 'No enfría', 'Otros', 'Pendiente')");
    $inc = (int)$pdo->lastInsertId();
    $tec = usuarioTemporal($empA, 'Técnico', ['nombre' => 'Técnico de prueba']);
    como($empA, $tec, 'Técnico', 'Técnico de prueba');
    $base = cotDatosDesdeIncidencia($pdo, $inc);
    igual($cA['contacto'], $base['datos']['contacto_id'], 'contacto tomado de la incidencia');
    $c = cotGuardar($pdo, 0, $base['datos'], [], '', false, ['incidencia_id' => $inc]);
    $c = cotGuardar($pdo, (int)$c['id'], $base['datos'], [['articulo_id' => $cA['cap'], 'cantidad' => 1]]);
    igual(1, count($c['partidas']));
    verdadero(!isset($c['partidas'][0]['costo_unitario']), 'el técnico no debe ver costos');
    lanza(fn() => cotCambiarEstatus($pdo, (int)$c['id'], 'enviada'), CotizacionException::class, 'sin_permiso');
    // Otro técnico no puede editar ese borrador
    $otro = usuarioTemporal($empA, 'Técnico');
    como($empA, $otro, 'Técnico');
    lanza(fn() => cotGuardar($pdo, (int)$c['id'], $base['datos'], []), CotizacionException::class, 'sin_permiso');
});

prueba('Cotización posterior al servicio: trae lo utilizado y no lo descuenta dos veces', function () use ($empA, $adminA, $cA) {
    global $pdo;
    como($empA, $adminA, 'Administrador');
    $pdo->exec("INSERT INTO incidencias (numero_incidente, cliente, contacto, sucursal, fecha, tecnico, falla, accion, categoria, estatus)
                VALUES ('SD-0002', 'Hotel de Prueba', 'Ana Pérez', 'Matriz', CURDATE(), 'Técnico de prueba', 'No enfría', 'Se cambió el capacitor', 'Otros', 'Cerrado')");
    $inc = (int)$pdo->lastInsertId();
    lanza(fn() => cotCrearDesdeIncidencia($pdo, $inc, true), CotizacionException::class, 'conceptos aplicados');
    $existencia = fn() => (float)$pdo->query('SELECT existencia FROM inventario_articulos WHERE id = ' . $cA['cap'])->fetchColumn();
    $antes = $existencia();
    invRegistrarMovimiento($pdo, $cA['cap'], -3, 'salida', 'Utilizado', 'incidencia', $inc, 'SD-0002');
    invRegistrarMovimiento($pdo, $cA['cap'], 1, 'entrada', 'Devuelto', 'incidencia', $inc, 'SD-0002');

    $c = cotCrearDesdeIncidencia($pdo, $inc, true);
    igual(1, count($c['partidas']));
    igual(2.0, (float)$c['partidas'][0]['cantidad'], 'cantidad neta de devoluciones');
    igual(1, (int)$c['partidas'][0]['cargada_incidencia'], 'nace como ya instalada');
    verdadero(str_contains((string)$c['notas_cliente'], 'Se cambió el capacitor'), 'el trabajo realizado va en las notas para el cliente');

    cotCambiarEstatus($pdo, (int)$c['id'], 'enviada');
    [, $avisos] = cotCambiarEstatus($pdo, (int)$c['id'], 'rechazada', ['motivo' => 'Precio alto']);
    igual(1, $avisos['refacciones_instaladas'], 'al rechazar avisa que ya estaba instalado');
    $v2 = cotNuevaVersion($pdo, (int)$c['id']);
    igual(1, (int)$v2['partidas'][0]['cargada_incidencia'], 'la nueva versión conserva lo instalado');
    cotCambiarEstatus($pdo, (int)$v2['id'], 'enviada');
    cotCambiarEstatus($pdo, (int)$v2['id'], 'aprobada', ['aprobada_por' => 'Gerente distrital']);
    lanza(fn() => cotCargarRefaccionesIncidencia($pdo, (int)$v2['id']), CotizacionException::class, 'No hay refacciones');
    igual($antes - 2, $existencia(), 'el inventario se descontó una sola vez');
});

prueba('Eliminar: solo el Administrador y solo borradores', function () use ($empA, $adminA, $cA) {
    global $pdo;
    como($empA, $adminA, 'Administrador');
    $c = cotGuardar($pdo, 0, encabezado($cA), []);
    $sup = usuarioTemporal($empA, 'Supervisor');
    como($empA, $sup, 'Supervisor');
    lanza(fn() => cotEliminar($pdo, (int)$c['id']), CotizacionException::class, 'administrador');
    como($empA, $adminA, 'Administrador');
    cotEliminar($pdo, (int)$c['id']);
    igual(null, cotFila($pdo, (int)$c['id']));
});

prueba('Descuento máximo: sin permiso se rechaza; con "Puede autorizar descuentos" pasa', function () use ($empA, $adminA, $cA) {
    global $pdo;
    $partida = [['articulo_id' => $cA['cap'], 'cantidad' => 1, 'descuento_pct' => 20]];
    $sup = usuarioTemporal($empA, 'Supervisor');
    como($empA, $sup, 'Supervisor');
    $e = lanza(fn() => cotGuardar($pdo, 0, encabezado($cA), $partida), CotizacionException::class, 'descuento');
    igual(403, $e->http);
    $pdo->prepare('UPDATE usuarios SET puede_autorizar_descuentos = 1 WHERE id = ?')->execute([$sup]);
    $c = cotGuardar($pdo, 0, encabezado($cA), $partida);
    igual(20.0, (float)$c['partidas'][0]['descuento_pct']);
    como($empA, $adminA, 'Administrador');
    verdadero(cotPuedeAutorizarDescuentos(), 'el Administrador siempre autoriza');
});

prueba('Margen: lo ven Administrador y Administrativo; Supervisor no', function () use ($empA, $adminA) {
    como($empA, $adminA, 'Administrador'); verdadero(cotVeMargen(), 'Administrador');
    como($empA, 1, 'Administrativo'); verdadero(cotVeMargen(), 'Administrativo');
    como($empA, 1, 'Supervisor'); verdadero(!cotVeMargen(), 'Supervisor no debería ver margen');
});

// ---------------------------------------------------------------- inventario y conversión

grupo('Inventario y conversión a ventas');

prueba('Cotizar y aprobar no altera existencias', function () use ($empA, $adminA, $cA) {
    global $pdo;
    como($empA, $adminA, 'Administrador');
    $antes = $pdo->query('SELECT id, existencia FROM inventario_articulos ORDER BY id')->fetchAll(PDO::FETCH_KEY_PAIR);
    $c = cotGuardar($pdo, 0, encabezado($cA), [['articulo_id' => $cA['ms2'], 'cantidad' => 2], ['articulo_id' => $cA['cap'], 'cantidad' => 3]]);
    cotCambiarEstatus($pdo, (int)$c['id'], 'enviada');
    cotCambiarEstatus($pdo, (int)$c['id'], 'aprobada', ['aprobada_por' => 'Cliente']);
    igual($antes, $pdo->query('SELECT id, existencia FROM inventario_articulos ORDER BY id')->fetchAll(PDO::FETCH_KEY_PAIR));
});

prueba('Cambiar precio o costo en el catálogo no altera cotizaciones existentes', function () use ($cA) {
    global $pdo;
    $c = cotGuardar($pdo, 0, encabezado($cA), [['articulo_id' => $cA['ms15'], 'cantidad' => 1]]);
    $pdo->prepare('UPDATE inventario_articulos SET precio_venta = 99999, costo_unitario = 88888 WHERE id = ?')->execute([$cA['ms15']]);
    $despues = cotObtener($pdo, (int)$c['id']);
    igual(16500.0, (float)$despues['partidas'][0]['precio_unitario']);
    igual(11800.0, (float)$despues['partidas'][0]['costo_unitario']);
    igual((float)$c['total'], (float)$despues['total']);
    $pdo->prepare('UPDATE inventario_articulos SET precio_venta = 16500, costo_unitario = 11800 WHERE id = ?')->execute([$cA['ms15']]);
});

prueba('Los servicios no llevan existencias', function () use ($cA) {
    global $pdo;
    $pdo->beginTransaction();
    lanza(fn() => invRegistrarMovimiento($pdo, $cA['mo'], -1, 'salida'), InventarioException::class, 'servicio');
});

prueba('Aprobación parcial: equipos iguales en una venta, distintos por separado, sin mano de obra', function () use ($cA) {
    global $pdo;
    $c = cotGuardar($pdo, 0, encabezado($cA), [
        ['articulo_id' => $cA['ms2'], 'cantidad' => 1],
        ['articulo_id' => $cA['ms2'], 'cantidad' => 2],   // mismo modelo → misma venta
        ['articulo_id' => $cA['ms15'], 'cantidad' => 1],  // otro modelo → otra venta
        ['articulo_id' => $cA['mo'], 'cantidad' => 3],    // mano de obra: el cliente no la acepta
    ]);
    cotCambiarEstatus($pdo, (int)$c['id'], 'enviada');
    $ids = array_column($c['partidas'], 'id');
    [$ap, $avisos] = cotCambiarEstatus($pdo, (int)$c['id'], 'aprobada', ['aprobada_por' => 'Lic. Ana', 'aceptadas' => array_slice($ids, 0, 3)]);
    igual(1, (int)$ap['aprobacion_parcial']);
    igual(2, $avisos['ventas_por_registrar']);
    $ventas = $ap['ventas'];
    $porArticulo = array_column($ventas, 'cantidad', 'articulo_id');
    igual(3.0, (float)$porArticulo[$cA['ms2']], 'equipos iguales juntos');
    igual(1.0, (float)$porArticulo[$cA['ms15']]);
    $esperado = cotCalcularTotales(array_slice($c['partidas'], 0, 3), false)['total'];
    igual($esperado, (float)$ap['total_aprobado'], 'total aprobado sin la mano de obra');

    // Registrar las ventas: con la última, la cotización queda convertida
    foreach ($ventas as $i => $v) {
        $pdo->beginTransaction();
        $pdo->prepare('INSERT INTO ventas (folio, cliente, fecha_registro) VALUES (?, ?, CURDATE())')->execute(['VT-9000' . $i, 'Hotel de Prueba']);
        $ventaId = (int)$pdo->lastInsertId();
        cotMarcarVentaRegistrada($pdo, (int)$v['id'], $ventaId, 'VT-9000' . $i);
        $pdo->commit();
        $estatus = cotFila($pdo, (int)$c['id'])['estatus'];
        igual($i === count($ventas) - 1 ? 'convertida' : 'aprobada', $estatus, "después de la venta $i");
    }
    lanza(fn() => cotVentaPorRegistrar($pdo, (int)$ventas[0]['id']), CotizacionException::class, 'ya se registró');
});

prueba('Duplicar crea otro folio en borrador con los mismos precios', function () use ($cA) {
    global $pdo;
    $c = cotGuardar($pdo, 0, encabezado($cA), [['articulo_id' => $cA['cap'], 'cantidad' => 2, 'precio_unitario' => 500]]);
    $d = cotDuplicar($pdo, (int)$c['id']);
    verdadero($d['folio'] !== $c['folio'], 'otro folio');
    igual('borrador', $d['estatus']); igual(500.0, (float)$d['partidas'][0]['precio_unitario']); igual((float)$c['total'], (float)$d['total']);
});

prueba('Historial: se registran creación, estatus, versión y conversión', function () {
    global $pdo;
    $acciones = $pdo->query("SELECT DISTINCT accion FROM auditoria WHERE tabla = 'cotizaciones'")->fetchAll(PDO::FETCH_COLUMN);
    foreach (['CREATE', 'UPDATE', 'ESTATUS', 'VERSION', 'CONVERSION', 'DELETE'] as $a) verdadero(in_array($a, $acciones, true), "falta $a en el historial");
});

// ---------------------------------------------------------------- moneda de artículos (migración 024)

grupo('Moneda de artículos en cotizaciones');

prueba('INV-MON-1: artículo en USD cotizado en USD queda a precio USD sin convertir', function () use ($empA, $adminA, $cA) {
    global $pdo;
    como($empA, $adminA, 'Administrador');
    // Requires moneda column (migration 024); fails before that
    $pdo->prepare("UPDATE inventario_articulos SET moneda = 'USD', precio_venta = 50.00 WHERE id = ?")->execute([$cA['cap']]);
    $cot = cotGuardar($pdo, 0,
        ['cliente_id' => $cA['cliente'], 'fecha' => date('Y-m-d'), 'vigencia_hasta' => date('Y-m-d', strtotime('+15 days')),
         'moneda' => 'USD', 'tipo_cambio' => 17.0, 'precios_incluyen_iva' => 0],
        [['articulo_id' => $cA['cap'], 'cantidad' => 1]]);
    igual(50.0, (float)$cot['partidas'][0]['precio_unitario'], 'artículo USD en cotización USD debe quedar $50 USD');
});

prueba('INV-MON-2: artículo en USD cotizado en MXN se multiplica por tipo_cambio', function () use ($empA, $adminA, $cA) {
    global $pdo;
    como($empA, $adminA, 'Administrador');
    // cap.moneda fue puesto en 'USD' por INV-MON-1; precio_venta=50
    $cot = cotGuardar($pdo, 0,
        ['cliente_id' => $cA['cliente'], 'fecha' => date('Y-m-d'), 'vigencia_hasta' => date('Y-m-d', strtotime('+15 days')),
         'moneda' => 'MXN', 'tipo_cambio' => 17.0, 'precios_incluyen_iva' => 0],
        [['articulo_id' => $cA['cap'], 'cantidad' => 1]]);
    igual(850.0, (float)$cot['partidas'][0]['precio_unitario'], 'artículo USD en cotización MXN con tc=17 debe quedar $850 MXN');
});

prueba('INV-IVA-1: artículo con tasa_iva=0.08 en cotización usa 8% de IVA al cargarlo del catálogo', function () use ($empA, $adminA, $cA) {
    global $pdo;
    como($empA, $adminA, 'Administrador');
    // Requires tasa_iva column (migration 024); fails before that
    $pdo->prepare("UPDATE inventario_articulos SET tasa_iva = 0.08 WHERE id = ?")->execute([$cA['cap']]);
    $cot = cotGuardar($pdo, 0,
        ['cliente_id' => $cA['cliente'], 'fecha' => date('Y-m-d'), 'vigencia_hasta' => date('Y-m-d', strtotime('+15 days')),
         'moneda' => 'MXN', 'precios_incluyen_iva' => 0],
        [['articulo_id' => $cA['cap'], 'cantidad' => 1, 'precio_unitario' => 1000]]);
    igual(0.08, (float)$cot['partidas'][0]['tasa_iva'], 'partida debe usar tasa_iva del artículo (8%)');
    igual(80.0, (float)$cot['iva_total'], 'IVA total debe ser $80 (8% de $1000)');
});
