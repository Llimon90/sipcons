<?php
// Inventario ligado a órdenes de servicio: eliminar una orden y devolver
// refacciones (RP-14, RP-16). Hallazgos H-12 y C-H01 (auditorias/2026-11-10-*).
require_once __DIR__ . '/../backend/lib/inventario_helpers.php';

[$empI, $adminI] = empresaTemporal('Inventario Ordenes');
como($empI, $adminI, 'Administrador');

function articuloPrueba(float $existencia): int {
    global $pdo;
    $pdo->prepare("INSERT INTO inventario_articulos (sku, nombre, categoria, existencia, costo_unitario) VALUES (?, 'Refacción prueba', 'Refacción', ?, 10)")
        ->execute(['I' . bin2hex(random_bytes(4)), $existencia]);
    return (int)$pdo->lastInsertId();
}
function ordenInv(): int {
    global $pdo;
    $pdo->prepare("INSERT INTO incidencias (numero_incidente, cliente, contacto, fecha, categoria, estatus, falla) VALUES (?, 'C', 'x', CURDATE(), 'General', 'Asignado', 'x')")
        ->execute(['I-' . bin2hex(random_bytes(3))]);
    return (int)$pdo->lastInsertId();
}
function consumir(int $art, int $orden, float $cant): void {
    global $pdo;
    $pdo->beginTransaction();
    invRegistrarMovimiento($pdo, $art, -$cant, 'salida', 'Consumo de prueba', 'incidencia', $orden, 'I');
    $pdo->commit();
}
function existencia(int $art): float {
    global $pdo;
    return (float)$pdo->query("SELECT existencia FROM inventario_articulos WHERE id = $art")->fetchColumn();
}
function ordenExiste(int $id): bool {
    global $pdo;
    return (bool)$pdo->query("SELECT COUNT(*) FROM incidencias WHERE id = $id")->fetchColumn();
}

grupo('Eliminar una orden (RP-14)');

prueba('RP-14: al eliminar una orden, sus refacciones regresan al inventario', function () use ($empI, $adminI) {
    $art = articuloPrueba(10);
    $orden = ordenInv();
    consumir($art, $orden, 3);
    $r = llamar($empI, $adminI, 'Administrador', 'POST', 'backend/eliminar-incidencia.php', ['json' => ['id' => $orden]]);
    igual(200, $r['http'], 'respuesta (' . mb_substr($r['texto'], 0, 100) . '):');
    verdadero(!ordenExiste($orden), 'la orden sigue existiendo');
    igual(10.0, existencia($art), 'existencia después de eliminar:');
});

prueba('H-12 · RP-14: si las refacciones no se pueden devolver, la orden no se elimina a medias', function () use ($empI, $adminI) {
    global $pdo;
    $otro = articuloPrueba(5);
    $art = articuloPrueba(1);
    $orden = ordenInv();
    consumir($otro, $orden, 2);   // este sí podría regresar: el rollback debe deshacerlo
    consumir($art, $orden, 1);
    // Con existencia 0 el artículo se puede volver "servicio" (sin existencias)
    $pdo->prepare('UPDATE inventario_articulos SET controla_existencia = 0 WHERE id = ?')->execute([$art]);
    $r = llamar($empI, $adminI, 'Administrador', 'POST', 'backend/eliminar-incidencia.php', ['json' => ['id' => $orden]]);
    $devuelto = (float)$pdo->query("SELECT COALESCE(SUM(cantidad), 0) FROM inventario_movimientos WHERE referencia_tipo = 'incidencia' AND referencia_id = $orden AND cantidad > 0")->fetchColumn();
    igual(409, $r['http'], 'respuesta:');
    verdadero(ordenExiste($orden), 'la orden se eliminó aunque su refacción no regresó al inventario');
    igual(0.0, $devuelto, 'movimientos de devolución que quedaron:');
    igual(3.0, existencia($otro), 'el otro artículo no debe recibir una devolución a medias:');
});

prueba('RP-14: solo un Administrador elimina órdenes y solo por POST', function () use ($empI, $adminI) {
    $orden = ordenInv();
    $sup = usuarioTemporal($empI, 'Supervisor');
    igual(403, llamar($empI, $sup, 'Supervisor', 'POST', 'backend/eliminar-incidencia.php', ['json' => ['id' => $orden]])['http'], 'Supervisor:');
    igual(405, llamar($empI, $adminI, 'Administrador', 'GET', 'backend/eliminar-incidencia.php', ['json' => ['id' => $orden]])['http'], 'GET:');
    como($empI, $adminI, 'Administrador');
    verdadero(ordenExiste($orden), 'la orden se eliminó');
});

grupo('Devolver refacciones (RP-16)');

prueba('C-H01 · RP-16: devoluciones simultáneas no regresan más de lo consumido', function () use ($empI, $adminI) {
    $art = articuloPrueba(10);
    $orden = ordenInv();
    consumir($art, $orden, 2);
    llamarSimultaneo(8, $empI, $adminI, 'Administrador', 'POST', 'backend/inventario.php',
        ['get' => ['accion' => 'devolver_incidencia'], 'json' => ['incidencia_id' => $orden, 'articulo_id' => $art, 'cantidad' => 2]]);
    igual(10.0, existencia($art), 'existencia final (consumió 2, debió regresar a 10):');
});

prueba('C-H08 · RP-16: no se registran refacciones en una orden que se está eliminando', function () use ($empI, $adminI) {
    $art = articuloPrueba(10);
    $orden = ordenInv();
    consumir($art, $orden, 1);
    llamar($empI, $adminI, 'Administrador', 'POST', 'backend/eliminar-incidencia.php', ['json' => ['id' => $orden]]);
    $r = llamar($empI, $adminI, 'Administrador', 'POST', 'backend/inventario.php',
        ['get' => ['accion' => 'consumir_incidencia'], 'json' => ['incidencia_id' => $orden, 'articulo_id' => $art, 'cantidad' => 1]]);
    como($empI, $adminI, 'Administrador');
    verdadero(empty($r['json']['exito']), 'se registró un consumo en una orden eliminada');
    igual(10.0, existencia($art), 'existencia:');
});

prueba('Inventario: una devolución suma existencias aunque el artículo esté en negativo', function () {
    global $pdo;
    $art = articuloPrueba(0);
    $orden = ordenInv();
    $pdo->beginTransaction();
    invRegistrarMovimiento($pdo, $art, -3, 'salida', 'Salida autorizada en negativo', 'incidencia', $orden, 'I', true);
    invRegistrarMovimiento($pdo, $art, 2, 'entrada', 'Devolución', 'incidencia', $orden, 'I');
    $pdo->commit();
    igual(-1.0, existencia($art), 'existencia:');
});
