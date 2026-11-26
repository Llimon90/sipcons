<?php
// Conceptos aplicados en la orden (D-23 punto 2): servicios sin existencias, refacciones y
// equipos de reemplazo que se dan de alta en el padrón del cliente. Además, alta de
// contacto (O-6) y solicitud de alta de refacción (O-7) desde la orden.
require_once __DIR__ . '/../backend/lib/inventario_helpers.php';
require_once __DIR__ . '/../backend/lib/cotizaciones_helpers.php';

[$empC, $adminC] = empresaTemporal('Conceptos');
$sxC = bin2hex(random_bytes(2));
$nomC = "Tecnico Conceptos $sxC";
$tecC = usuarioTemporal($empC, 'Técnico', ['nombre' => $nomC]);
como($empC, $adminC, 'Administrador');
clienteCatalogo('Cliente Conceptos');
$cliC = (int)$pdo->query("SELECT id FROM clientes WHERE nombre = 'Cliente Conceptos'")->fetchColumn();

function articuloC(string $nombre, string $cat, float $existencia, float $precio = 500): int {
    global $pdo;
    $sin = in_array($cat, INV_CATEGORIAS_SIN_EXISTENCIA, true);
    $pdo->prepare("INSERT INTO inventario_articulos (sku, nombre, categoria, controla_existencia, marca, modelo, unidad, existencia, costo_unitario, precio_venta)
                   VALUES (?, ?, ?, ?, 'Marca C', 'Modelo C', ?, ?, 100, ?)")
        ->execute(['C' . bin2hex(random_bytes(4)), $nombre, $cat, $sin ? 0 : 1, $sin ? 'servicio' : 'pza', $existencia, $precio]);
    return (int)$pdo->lastInsertId();
}
function ordenC(string $tecnico = ''): int {
    global $pdo;
    $pdo->prepare("INSERT INTO incidencias (numero_incidente, cliente, contacto, sucursal, fecha, categoria, estatus, falla, tecnico) VALUES (?, 'Cliente Conceptos', 'x', 'Matriz', CURDATE(), 'General', ?, 'x', ?)")
        ->execute(['CC-' . bin2hex(random_bytes(3)), $tecnico ? 'Asignado' : 'Abierto', $tecnico]);
    return (int)$pdo->lastInsertId();
}
function invC(int $emp, int $u, string $rol, string $accion, array $json): array {
    return llamar($emp, $u, $rol, 'POST', 'backend/inventario.php', ['get' => ['accion' => $accion], 'json' => $json]);
}
function existenciaC(int $art): float {
    global $pdo;
    return (float)$pdo->query("SELECT existencia FROM inventario_articulos WHERE id = $art")->fetchColumn();
}

grupo('Servicios y mano de obra (D-23)');

prueba('D-23: un servicio se aplica a la orden sin tocar existencias y aparece en sus conceptos', function () use ($empC, $adminC) {
    $mo = articuloC('Mano de obra instalación', 'Mano de obra', 0, 800);
    $orden = ordenC();
    $r = invC($empC, $adminC, 'Administrador', 'aplicar_servicio', ['incidencia_id' => $orden, 'articulo_id' => $mo, 'cantidad' => 2]);
    igual(200, $r['http'], 'aplicar (' . mb_substr($r['texto'], 0, 120) . '):');
    $c = llamar($empC, $adminC, 'Administrador', 'GET', 'backend/inventario.php', ['get' => ['accion' => 'consumos_incidencia', 'incidencia_id' => $orden]]);
    $s = $c['json']['servicios'][0] ?? [];
    igual('Mano de obra instalación', $s['descripcion'] ?? null, 'servicio aplicado:');
    igual(2.0, (float)($s['cantidad'] ?? 0), 'cantidad:');
    igual(0.0, existenciaC($mo), 'existencia del servicio:');
    como($empC, $adminC, 'Administrador');
});

prueba('D-23: un servicio aplicado se puede quitar', function () use ($empC, $adminC) {
    global $pdo;
    $mo = articuloC('Diagnóstico', 'Servicio', 0, 300);
    $orden = ordenC();
    invC($empC, $adminC, 'Administrador', 'aplicar_servicio', ['incidencia_id' => $orden, 'articulo_id' => $mo, 'cantidad' => 1]);
    $id = (int)$pdo->query("SELECT id FROM incidencia_servicios WHERE incidencia_id = $orden")->fetchColumn();
    $r = invC($empC, $adminC, 'Administrador', 'quitar_servicio', ['incidencia_id' => $orden, 'id' => $id]);
    como($empC, $adminC, 'Administrador');
    igual(200, $r['http'], 'quitar:');
    igual(0, (int)$pdo->query("SELECT COUNT(*) FROM incidencia_servicios WHERE incidencia_id = $orden")->fetchColumn(), 'servicios que quedan:');
});

prueba('D-23 · D-9: el Técnico aplica servicios en sus órdenes, no en las ajenas', function () use ($empC, $tecC, $nomC, $adminC) {
    $mo = articuloC('Visita', 'Servicio', 0, 200);
    $mia = ordenC($nomC);
    $ajena = ordenC('Otro Tecnico');
    igual(200, invC($empC, $tecC, 'Técnico', 'aplicar_servicio', ['incidencia_id' => $mia, 'articulo_id' => $mo, 'cantidad' => 1])['http'], 'en su orden:');
    $r = invC($empC, $tecC, 'Técnico', 'aplicar_servicio', ['incidencia_id' => $ajena, 'articulo_id' => $mo, 'cantidad' => 1]);
    verdadero(empty($r['json']['exito']), 'aplicó un servicio en una orden ajena');
    como($empC, $adminC, 'Administrador');
});

prueba('D-23: aplicar un artículo con existencias como servicio se rechaza', function () use ($empC, $adminC) {
    $ref = articuloC('Capacitor', 'Refacción', 5);
    $r = invC($empC, $adminC, 'Administrador', 'aplicar_servicio', ['incidencia_id' => ordenC(), 'articulo_id' => $ref, 'cantidad' => 1]);
    verdadero(empty($r['json']['exito']), 'se aplicó una refacción como servicio');
    como($empC, $adminC, 'Administrador');
});

grupo('Equipo de reemplazo (D-23)');

prueba('D-23: el equipo de reemplazo descuenta existencias y se da de alta en el padrón del cliente', function () use ($empC, $adminC) {
    global $pdo;
    $eq = articuloC('Minisplit 1 TR', 'Equipo', 3, 9000);
    $orden = ordenC();
    $serie = 'RS-' . bin2hex(random_bytes(3));
    $r = invC($empC, $adminC, 'Administrador', 'consumir_incidencia', ['incidencia_id' => $orden, 'articulo_id' => $eq, 'cantidad' => 1, 'reemplazo' => ['numero_serie' => $serie]]);
    como($empC, $adminC, 'Administrador');
    igual(200, $r['http'], 'registrar (' . mb_substr($r['texto'], 0, 120) . '):');
    igual(2.0, existenciaC($eq), 'existencia:');
    $p = $pdo->query("SELECT * FROM padron_equipos WHERE numero_serie = '$serie'")->fetch(PDO::FETCH_ASSOC) ?: [];
    igual('Cliente Conceptos', $p['cliente'] ?? null, 'cliente en el padrón:');
    igual('Minisplit 1 TR', $p['equipo'] ?? null, 'equipo:');
    igual($orden, (int)($p['reemplazo_incidencia_id'] ?? 0), 'orden de origen:');
});

prueba('D-23: el equipo de reemplazo exige una serie que no esté ya en el padrón, y una sola pieza', function () use ($empC, $adminC) {
    $eq = articuloC('Bomba 1 HP', 'Equipo', 5, 4000);
    $orden = ordenC();
    $serie = 'RS-' . bin2hex(random_bytes(3));
    verdadero(empty(invC($empC, $adminC, 'Administrador', 'consumir_incidencia', ['incidencia_id' => $orden, 'articulo_id' => $eq, 'cantidad' => 1, 'reemplazo' => ['numero_serie' => '']])['json']['exito']), 'sin serie');
    verdadero(empty(invC($empC, $adminC, 'Administrador', 'consumir_incidencia', ['incidencia_id' => $orden, 'articulo_id' => $eq, 'cantidad' => 2, 'reemplazo' => ['numero_serie' => $serie]])['json']['exito']), 'con dos piezas');
    invC($empC, $adminC, 'Administrador', 'consumir_incidencia', ['incidencia_id' => $orden, 'articulo_id' => $eq, 'cantidad' => 1, 'reemplazo' => ['numero_serie' => $serie]]);
    verdadero(empty(invC($empC, $adminC, 'Administrador', 'consumir_incidencia', ['incidencia_id' => ordenC(), 'articulo_id' => $eq, 'cantidad' => 1, 'reemplazo' => ['numero_serie' => $serie]])['json']['exito']), 'serie repetida');
    como($empC, $adminC, 'Administrador');
    igual(4.0, existenciaC($eq), 'solo se descontó el que sí se registró:');
});

prueba('D-23: un equipo de reemplazo no se devuelve mientras siga en el padrón del cliente', function () use ($empC, $adminC) {
    $eq = articuloC('Compresor 5 HP', 'Equipo', 2, 12000);
    $orden = ordenC();
    invC($empC, $adminC, 'Administrador', 'consumir_incidencia', ['incidencia_id' => $orden, 'articulo_id' => $eq, 'cantidad' => 1, 'reemplazo' => ['numero_serie' => 'RS-' . bin2hex(random_bytes(3))]]);
    $r = invC($empC, $adminC, 'Administrador', 'devolver_incidencia', ['incidencia_id' => $orden, 'articulo_id' => $eq]);
    como($empC, $adminC, 'Administrador');
    verdadero(empty($r['json']['exito']), 'se devolvió un equipo que sigue en el padrón');
    igual(1.0, existenciaC($eq), 'existencia:');
});

prueba('D-23 · RP-14: al eliminar la orden se quitan sus servicios y el equipo de reemplazo del padrón, y la existencia regresa', function () use ($empC, $adminC) {
    global $pdo;
    $eq = articuloC('Evaporador', 'Equipo', 2, 7000);
    $mo = articuloC('Mano de obra cambio', 'Mano de obra', 0, 900);
    $orden = ordenC();
    $serie = 'RS-' . bin2hex(random_bytes(3));
    invC($empC, $adminC, 'Administrador', 'consumir_incidencia', ['incidencia_id' => $orden, 'articulo_id' => $eq, 'cantidad' => 1, 'reemplazo' => ['numero_serie' => $serie]]);
    invC($empC, $adminC, 'Administrador', 'aplicar_servicio', ['incidencia_id' => $orden, 'articulo_id' => $mo, 'cantidad' => 1]);
    $r = llamar($empC, $adminC, 'Administrador', 'POST', 'backend/eliminar-incidencia.php', ['json' => ['id' => $orden]]);
    como($empC, $adminC, 'Administrador');
    igual(200, $r['http'], 'eliminar (' . mb_substr($r['texto'], 0, 120) . '):');
    igual(0, (int)$pdo->query("SELECT COUNT(*) FROM incidencia_servicios WHERE incidencia_id = $orden")->fetchColumn(), 'servicios:');
    igual(0, (int)$pdo->query("SELECT COUNT(*) FROM padron_equipos WHERE numero_serie = '$serie'")->fetchColumn(), 'equipo en el padrón:');
    igual(2.0, existenciaC($eq), 'existencia:');
});

grupo('Cotizar lo aplicado (D-23)');

prueba('D-23: "cotizar lo aplicado" incluye los servicios además de las refacciones', function () use ($empC, $adminC) {
    global $pdo;
    $ref = articuloC('Filtro', 'Refacción', 5, 150);
    $mo = articuloC('Mano de obra mantenimiento', 'Mano de obra', 0, 600);
    $orden = ordenC();
    invC($empC, $adminC, 'Administrador', 'consumir_incidencia', ['incidencia_id' => $orden, 'articulo_id' => $ref, 'cantidad' => 1]);
    invC($empC, $adminC, 'Administrador', 'aplicar_servicio', ['incidencia_id' => $orden, 'articulo_id' => $mo, 'cantidad' => 2]);
    como($empC, $adminC, 'Administrador');
    $cot = cotCrearDesdeIncidencia($pdo, $orden, true);
    $porArticulo = array_column($cot['partidas'], 'cantidad', 'articulo_id');
    igual(1.0, (float)($porArticulo[$ref] ?? 0), 'refacción en la cotización:');
    igual(2.0, (float)($porArticulo[$mo] ?? 0), 'servicio en la cotización:');
});

prueba('D-47: el equipo de reemplazo cotizado "con lo aplicado" no genera venta por registrar al aprobar', function () use ($empC, $adminC) {
    global $pdo;
    $eq = articuloC('Báscula de reemplazo', 'Equipo', 3, 9000);
    $orden = ordenC();
    $serie = 'DD-' . bin2hex(random_bytes(3));
    invC($empC, $adminC, 'Administrador', 'consumir_incidencia', ['incidencia_id' => $orden, 'articulo_id' => $eq, 'cantidad' => 1, 'reemplazo' => ['numero_serie' => $serie]]);
    como($empC, $adminC, 'Administrador');
    $cot = cotCrearDesdeIncidencia($pdo, $orden, true);
    $partida = array_values(array_filter($cot['partidas'], fn($p) => (int)$p['articulo_id'] === $eq))[0] ?? [];
    igual(1, (int)($partida['cargada_incidencia'] ?? 0), 'el equipo nace marcado como ya instalado:');
    $id = (int)$cot['id'];
    cotCambiarEstatus($pdo, $id, 'enviada');
    [, $avisos] = cotCambiarEstatus($pdo, $id, 'aprobada', ['aprobada_por' => 'Cliente']);
    igual(0, (int)($avisos['ventas_por_registrar'] ?? 0), 'ventas por registrar:');
    igual(0, (int)$pdo->query("SELECT COUNT(*) FROM cotizacion_ventas WHERE cotizacion_id = $id")->fetchColumn(), 'filas de ventas por registrar:');
    igual(2.0, existenciaC($eq), 'existencia (solo la salida de la orden):');
});

prueba('D-47: al aprobar una cotización normal, avisa qué refacciones ya se aplicaron en la orden', function () use ($empC, $adminC) {
    global $pdo;
    $ref = articuloC('Celda de carga', 'Refacción', 5, 1200);
    $orden = ordenC();
    invC($empC, $adminC, 'Administrador', 'consumir_incidencia', ['incidencia_id' => $orden, 'articulo_id' => $ref, 'cantidad' => 1]);
    como($empC, $adminC, 'Administrador');
    $datos = cotDatosDesdeIncidencia($pdo, $orden)['datos'];
    $id = (int)cotGuardar($pdo, 0, $datos, [['articulo_id' => $ref, 'cantidad' => 1]], '', false, ['incidencia_id' => $orden])['id'];
    cotCambiarEstatus($pdo, $id, 'enviada');
    [, $avisos] = cotCambiarEstatus($pdo, $id, 'aprobada', ['aprobada_por' => 'Cliente']);
    igual(['Celda de carga'], $avisos['refacciones_ya_aplicadas'] ?? null, 'aviso de refacciones ya aplicadas:');
});

prueba('D-47: "Duplicar" no copia el vínculo con la orden de servicio; "Nueva versión" sí', function () use ($empC, $adminC) {
    global $pdo;
    $mo = articuloC('Visita técnica', 'Servicio', 0, 500);
    $orden = ordenC();
    invC($empC, $adminC, 'Administrador', 'aplicar_servicio', ['incidencia_id' => $orden, 'articulo_id' => $mo, 'cantidad' => 1]);
    como($empC, $adminC, 'Administrador');
    $cot = cotCrearDesdeIncidencia($pdo, $orden, true);
    $id = (int)$cot['id'];
    $dup = cotDuplicar($pdo, $id);
    igual(null, $dup['incidencia_id'], 'orden de la duplicada:');
    cotCambiarEstatus($pdo, $id, 'enviada');
    $ver = cotNuevaVersion($pdo, $id);
    igual($orden, (int)$ver['incidencia_id'], 'orden de la nueva versión:');
});

grupo('Altas desde la orden (O-6, O-7)');

prueba('O-6: quien edita la orden agrega un contacto al cliente, sin repetirlo', function () use ($empC, $tecC, $nomC, $adminC, $cliC) {
    global $pdo;
    $orden = ordenC($nomC);
    $nombre = 'Contacto Nuevo ' . bin2hex(random_bytes(2));
    $r = llamar($empC, $tecC, 'Técnico', 'POST', 'backend/agregar-contacto.php', ['json' => ['incidencia_id' => $orden, 'nombre' => $nombre, 'telefono' => '555']]);
    igual(200, $r['http'], 'agregar (' . mb_substr($r['texto'], 0, 120) . '):');
    llamar($empC, $tecC, 'Técnico', 'POST', 'backend/agregar-contacto.php', ['json' => ['incidencia_id' => $orden, 'nombre' => mb_strtoupper($nombre)]]);
    como($empC, $adminC, 'Administrador');
    $st = $pdo->prepare('SELECT COUNT(*) FROM cliente_contactos WHERE cliente_id = ? AND nombre = ?');
    $st->execute([$cliC, $nombre]);
    igual(1, (int)$st->fetchColumn(), 'contactos con ese nombre:');
});

prueba('O-6 · D-9: el Técnico no agrega contactos desde una orden ajena', function () use ($empC, $tecC, $adminC) {
    $r = llamar($empC, $tecC, 'Técnico', 'POST', 'backend/agregar-contacto.php', ['json' => ['incidencia_id' => ordenC('Otro Tecnico'), 'nombre' => 'X']]);
    verdadero(empty($r['json']['exito']) && $r['http'] >= 400, "agregó un contacto desde una orden ajena (HTTP {$r['http']})");
    como($empC, $adminC, 'Administrador');
});

prueba('O-7: el Técnico solicita el alta de una refacción y queda como nota en su orden', function () use ($empC, $tecC, $nomC, $adminC) {
    global $pdo;
    $orden = ordenC($nomC);
    $r = invC($empC, $tecC, 'Técnico', 'solicitar_articulo', ['incidencia_id' => $orden, 'nombre' => 'Válvula de expansión R410', 'notas' => '2 piezas']);
    como($empC, $adminC, 'Administrador');
    igual(200, $r['http'], 'solicitar (' . mb_substr($r['texto'], 0, 120) . '):');
    $notas = (string)$pdo->query("SELECT notas FROM incidencias WHERE id = $orden")->fetchColumn();
    verdadero(str_contains($notas, 'Válvula de expansión R410'), 'la nota no quedó en la orden: ' . $notas);
});

prueba('O-7: el Técnico no da de alta artículos en el inventario directamente', function () use ($empC, $tecC, $adminC) {
    $r = invC($empC, $tecC, 'Técnico', 'guardar', ['sku' => 'NO-' . bin2hex(random_bytes(2)), 'nombre' => 'X', 'categoria' => 'Refacción']);
    verdadero(empty($r['json']['exito']) && $r['http'] === 403, "el Técnico creó un artículo (HTTP {$r['http']})");
    como($empC, $adminC, 'Administrador');
});
