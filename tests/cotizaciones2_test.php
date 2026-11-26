<?php
// Auditoría integral 2026-11-24, bloque 4: cotizaciones.
// L-9 lo que incluye un servicio se congela al enviar, L-14 serie al cargar refacciones,
// L-15 aviso de crédito en USD, L-19 aprobar una vencida, L-27 marca de la versión sustituida.
require_once __DIR__ . '/../backend/lib/cotizaciones_helpers.php';
require_once __DIR__ . '/../backend/lib/cotizacion_pdf.php';

[$empQ, $adminQ] = empresaTemporal('Cotizaciones 2');
como($empQ, $adminQ, 'Administrador');

function clienteQ(?float $limite = null): int {
    global $pdo;
    $pdo->prepare("INSERT INTO clientes (nombre, rfc, direccion, telefono, contactos, email, limite_credito) VALUES (?, '', '', '', 'Ana', '', ?)")
        ->execute(['Cliente Q ' . bin2hex(random_bytes(3)), $limite]);
    return (int)$pdo->lastInsertId();
}
function articuloQ(string $cat, float $precio, string $descripcion = '', int $serie = 0): int {
    global $pdo;
    $sin = in_array($cat, INV_CATEGORIAS_SIN_EXISTENCIA, true);
    $pdo->prepare('INSERT INTO inventario_articulos (sku, nombre, descripcion, categoria, controla_existencia, controla_serie, unidad, existencia, costo_unitario, precio_venta)
                   VALUES (?, ?, ?, ?, ?, ?, ?, 10, 10, ?)')
        ->execute(['Q' . bin2hex(random_bytes(4)), "Artículo $cat", $descripcion ?: null, $cat, $sin ? 0 : 1, $serie, $sin ? 'servicio' : 'pza', $precio]);
    return (int)$pdo->lastInsertId();
}
function cotizacionQ(int $cliente, array $partidas, array $extra = []): array {
    global $pdo;
    return cotGuardar($pdo, 0, $extra + ['cliente_id' => $cliente, 'sucursal' => '', 'fecha' => date('Y-m-d'),
        'vigencia_hasta' => date('Y-m-d', strtotime('+15 days')), 'moneda' => 'MXN', 'precios_incluyen_iva' => 0], $partidas);
}

grupo('Cotizaciones (auditoría integral, bloque 4)');

prueba('L-9: lo que incluye un servicio se congela al enviar la cotización', function () {
    global $pdo;
    $srv = articuloQ('Servicio', 1500, 'Limpieza y gas');
    $cot = cotizacionQ(clienteQ(), [['articulo_id' => $srv, 'cantidad' => 1]]);
    cotCambiarEstatus($pdo, (int)$cot['id'], 'enviada');
    $pdo->prepare('UPDATE inventario_articulos SET descripcion = ? WHERE id = ?')->execute(['Solo limpieza', $srv]);
    igual('Limpieza y gas', cotObtener($pdo, (int)$cot['id'])['partidas'][0]['incluye'], 'incluye de la enviada:');
    // El borrador sigue el catálogo vivo
    $borrador = cotizacionQ(clienteQ(), [['articulo_id' => $srv, 'cantidad' => 1]]);
    igual('Solo limpieza', cotObtener($pdo, (int)$borrador['id'])['partidas'][0]['incluye'], 'incluye del borrador:');
});

prueba('L-15 · D-48: el aviso de crédito convierte a pesos una cotización en dólares', function () {
    global $pdo;
    $cli = clienteQ(100000);
    $eq = articuloQ('Equipo', 10000);
    $cot = cotizacionQ($cli, [['articulo_id' => $eq, 'cantidad' => 1, 'precio_unitario' => 10000]], ['moneda' => 'USD', 'tipo_cambio' => 18]);
    verdadero(cotObtener($pdo, (int)$cot['id'])['aviso_credito'] !== null, 'no avisa: 10,000 USD ≈ $180,000 rebasa $100,000');
});

prueba('L-19: una cotización vencida se aprueba solo si el cliente la aprobó dentro de la vigencia', function () {
    global $pdo;
    $eq = articuloQ('Equipo', 5000);
    $cot = cotizacionQ(clienteQ(), [['articulo_id' => $eq, 'cantidad' => 1]]);
    cotCambiarEstatus($pdo, (int)$cot['id'], 'enviada');
    $pdo->prepare("UPDATE cotizaciones SET estatus = 'vencida', vigencia_hasta = ? WHERE id = ?")->execute([date('Y-m-d', strtotime('-1 day')), $cot['id']]);
    lanza(fn() => cotCambiarEstatus($pdo, (int)$cot['id'], 'aprobada', ['aprobada_por' => 'Cliente']), CotizacionException::class, 'Venció');
    [$aprobada] = cotCambiarEstatus($pdo, (int)$cot['id'], 'aprobada', ['aprobada_por' => 'Cliente', 'aprobada_en_vigencia' => 1]);
    igual('aprobada', $aprobada['estatus']);
});

prueba('L-14 · D-45: cargar refacciones desde la cotización no descuenta sin serie las que la controlan', function () use ($empQ, $adminQ) {
    global $pdo;
    $pdo->exec("INSERT INTO incidencias (numero_incidente, cliente, contacto, sucursal, fecha, categoria, estatus, falla, tecnico) VALUES ('CQ-14', 'x', 'x', '', CURDATE(), 'General', 'Abierto', 'x', '')");
    $orden = (int)$pdo->lastInsertId();
    $conSerie = articuloQ('Refacción', 900, '', 1);
    $cot = cotizacionQ(clienteQ(), [['articulo_id' => $conSerie, 'cantidad' => 1]], ['incidencia_id' => $orden]);
    $pdo->prepare('UPDATE cotizaciones SET incidencia_id = ? WHERE id = ?')->execute([$orden, $cot['id']]);
    cotCambiarEstatus($pdo, (int)$cot['id'], 'enviada');
    cotCambiarEstatus($pdo, (int)$cot['id'], 'aprobada', ['aprobada_por' => 'Cliente']);
    lanza(fn() => cotCargarRefaccionesIncidencia($pdo, (int)$cot['id']), CotizacionException::class, 'serie');
    igual(10.0, (float)$pdo->query("SELECT existencia FROM inventario_articulos WHERE id = $conSerie")->fetchColumn(), 'existencia:');
});

prueba('L-27: el PDF de una versión sustituida lleva la marca SUSTITUIDA', function () {
    global $pdo;
    $eq = articuloQ('Equipo', 5000);
    $cot = cotizacionQ(clienteQ(), [['articulo_id' => $eq, 'cantidad' => 1]]);
    cotCambiarEstatus($pdo, (int)$cot['id'], 'enviada');
    cotNuevaVersion($pdo, (int)$cot['id']);
    $v1 = cotObtener($pdo, (int)$cot['id']);
    verdadero(str_contains(cotPdfHtml($pdo, $v1, $v1['partidas']), 'SUSTITUIDA'), 'sin marca de sustituida');
});
