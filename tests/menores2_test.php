<?php
// Pendientes menores de los triages (segunda tanda): historial de manuales (D-28),
// atomicidad al editar un usuario (H-04 del paso 8) y refacciones devueltas en una
// cotización (cargada_incidencia).
require_once __DIR__ . '/../backend/lib/inventario_helpers.php';
require_once __DIR__ . '/../backend/lib/cotizaciones_helpers.php';

[$empN, $adminN] = empresaTemporal('Menores Dos');
$sxN = bin2hex(random_bytes(2));
$supN = usuarioTemporal($empN, 'Supervisor', ['nombre' => "Supervisor Menores Dos $sxN"]);
como($empN, $adminN, 'Administrador');

function ultimoHistorialN(string $tabla): ?array {
    global $pdo;
    $st = $pdo->prepare('SELECT accion, registro_id, datos_anteriores, datos_nuevos, usuario_id FROM auditoria WHERE tabla = ? ORDER BY id DESC LIMIT 1');
    $st->execute([$tabla]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

grupo('Historial de marcas, modelos y manuales (D-28)');

prueba('D-28: crear y borrar una marca queda en el historial con quién lo hizo', function () use ($empN, $supN, $adminN) {
    $nombre = 'Marca Historial ' . bin2hex(random_bytes(3));
    $r = llamar($empN, $supN, 'Supervisor', 'POST', 'backend/soporte_backend.php', ['get' => ['action' => 'add_marca'], 'json' => ['nombre' => $nombre]]);
    como($empN, $adminN, 'Administrador');
    verdadero(!empty($r['json']['success']), 'no se creó la marca: ' . mb_substr($r['texto'], 0, 120));
    $h = ultimoHistorialN('marcas');
    igual('CREATE', $h['accion'] ?? null, 'acción en el historial:');
    verdadero(str_contains((string)($h['datos_nuevos'] ?? ''), $nombre), 'el historial no trae el nombre de la marca');
    igual($supN, (int)($h['usuario_id'] ?? 0), 'usuario del historial:');

    $id = (int)$r['json']['id'];
    $b = llamar($empN, $supN, 'Supervisor', 'POST', 'backend/soporte_backend.php', ['get' => ['action' => 'delete_marca'], 'json' => ['marca_id' => $id]]);
    como($empN, $adminN, 'Administrador');
    verdadero(!empty($b['json']['success']), 'no se borró la marca: ' . mb_substr($b['texto'], 0, 120));
    $h = ultimoHistorialN('marcas');
    igual('DELETE', $h['accion'] ?? null, 'acción al borrar:');
    verdadero(str_contains((string)($h['datos_anteriores'] ?? ''), $nombre), 'el historial del borrado no trae la marca borrada');
});

prueba('D-28: editar un modelo queda en el historial con el antes y el después', function () use ($empN, $supN, $adminN) {
    global $pdo;
    $pdo->prepare('INSERT INTO marcas (nombre) VALUES (?)')->execute(['Marca Modelo ' . bin2hex(random_bytes(3))]);
    $marca = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO modelos (marca_id, nombre, tipo_equipo) VALUES (?, 'Modelo Viejo', 'Minisplit')")->execute([$marca]);
    $modelo = (int)$pdo->lastInsertId();
    $r = llamar($empN, $supN, 'Supervisor', 'POST', 'backend/soporte_backend.php', ['get' => ['action' => 'update_modelo'], 'post' => [
        'modelo_id' => $modelo, 'marca_id' => $marca, 'nombre' => 'Modelo Nuevo', 'tipo_equipo' => 'Minisplit']]);
    como($empN, $adminN, 'Administrador');
    verdadero(!empty($r['json']['success']), 'no se editó el modelo: ' . mb_substr($r['texto'], 0, 120));
    $h = ultimoHistorialN('modelos');
    igual('UPDATE', $h['accion'] ?? null, 'acción:');
    verdadero(str_contains((string)($h['datos_anteriores'] ?? ''), 'Modelo Viejo'), 'falta el nombre anterior');
    verdadero(str_contains((string)($h['datos_nuevos'] ?? ''), 'Modelo Nuevo'), 'falta el nombre nuevo');
});

prueba('D-28: una acción rechazada no deja historial', function () use ($empN, $adminN) {
    global $pdo;
    $antes = (int)$pdo->query("SELECT COUNT(*) FROM auditoria WHERE tabla = 'marcas'")->fetchColumn();
    llamar($empN, $adminN, 'Administrador', 'POST', 'backend/soporte_backend.php', ['get' => ['action' => 'add_marca'], 'json' => ['nombre' => '']]);
    como($empN, $adminN, 'Administrador');
    igual($antes, (int)$pdo->query("SELECT COUNT(*) FROM auditoria WHERE tabla = 'marcas'")->fetchColumn(), 'registros de historial:');
});

grupo('Editar un usuario es todo o nada (H-04 del paso 8)');

prueba('H-04: si falla ajustar sus órdenes, el nombre del usuario no cambia', function () use ($empN, $adminN) {
    global $pdo;
    $nombre = 'Tecnico Atomico ' . bin2hex(random_bytes(2));
    $u = usuarioTemporal($empN, 'Técnico', ['nombre' => $nombre]);
    $pdo->prepare("INSERT INTO incidencias (numero_incidente, cliente, contacto, fecha, categoria, estatus, falla, tecnico) VALUES (?, 'C', 'x', CURDATE(), 'General', 'Asignado', 'x', ?)")
        ->execute(['A-' . bin2hex(random_bytes(3)), $nombre]);
    // Un disparador hace fallar el ajuste de las órdenes cuando el técnico se renombra a "Forzar Falla"
    $tabla = 'e' . $empN . '_incidencias';
    $pdo->exec("DROP TRIGGER IF EXISTS prueba_falla_$empN");
    $pdo->exec("CREATE TRIGGER prueba_falla_$empN BEFORE UPDATE ON `$tabla` FOR EACH ROW
                BEGIN IF NEW.tecnico = 'Forzar Falla' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'falla de prueba'; END IF; END");
    try {
        $f = $pdo->query("SELECT correo FROM usuarios WHERE id = $u")->fetch(PDO::FETCH_ASSOC);
        $r = llamar($empN, $adminN, 'Administrador', 'POST', 'backend/actualiza-usuario.php', ['json' => [
            'id' => $u, 'nombre' => 'Forzar Falla', 'correo' => $f['correo'], 'telefono' => '', 'rol' => 'Técnico']]);
        como($empN, $adminN, 'Administrador');
        verdadero(empty($r['json']['success']), 'respondió éxito aunque falló');
        igual($nombre, (string)$pdo->query("SELECT nombre FROM usuarios WHERE id = $u")->fetchColumn(), 'nombre del usuario:');
    } finally {
        $pdo->exec("DROP TRIGGER IF EXISTS prueba_falla_$empN");
    }
});

grupo('Refacciones devueltas en una cotización (cargada_incidencia)');

prueba('Al devolver todas las refacciones de la orden, la cotización ya no las marca como instaladas', function () use ($empN, $adminN) {
    global $pdo;
    $pdo->exec("INSERT INTO clientes (nombre, rfc, direccion, telefono, contactos, email) VALUES ('CLIENTE DEVOLUCION', '', '', '', '', '')");
    $pdo->prepare("INSERT INTO inventario_articulos (sku, nombre, categoria, existencia, costo_unitario, precio_venta) VALUES (?, 'Capacitor', 'Refacción', 5, 10, 20)")
        ->execute(['D' . bin2hex(random_bytes(4))]);
    $art = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO incidencias (numero_incidente, cliente, contacto, fecha, categoria, estatus, falla, tecnico) VALUES (?, 'CLIENTE DEVOLUCION', 'x', CURDATE(), 'General', 'Completado', 'x', '')")
        ->execute(['D-' . bin2hex(random_bytes(3))]);
    $orden = (int)$pdo->lastInsertId();
    $pdo->beginTransaction();
    invRegistrarMovimiento($pdo, $art, -2, 'salida', 'Consumo', 'incidencia', $orden, 'D');
    $pdo->commit();
    $cot = cotCrearDesdeIncidencia($pdo, $orden, true);
    $cargada = fn() => (int)$pdo->query("SELECT MAX(cargada_incidencia) FROM cotizacion_partidas WHERE cotizacion_id = " . (int)$cot['id'] . " AND articulo_id = $art")->fetchColumn();
    igual(1, $cargada(), 'marcada como instalada antes de devolver:');
    $r = llamar($empN, $adminN, 'Administrador', 'POST', 'backend/inventario.php', ['get' => ['accion' => 'devolver_incidencia'], 'json' => ['incidencia_id' => $orden, 'articulo_id' => $art, 'cantidad' => 1]]);
    como($empN, $adminN, 'Administrador');
    igual(1, $cargada(), 'tras devolver una parte sigue instalada:');
    $r = llamar($empN, $adminN, 'Administrador', 'POST', 'backend/inventario.php', ['get' => ['accion' => 'devolver_incidencia'], 'json' => ['incidencia_id' => $orden, 'articulo_id' => $art, 'cantidad' => 1]]);
    como($empN, $adminN, 'Administrador');
    verdadero(!empty($r['json']['exito']), 'la devolución falló: ' . mb_substr($r['texto'], 0, 120));
    igual(0, $cargada(), 'tras devolver todo:');
});
