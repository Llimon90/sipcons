<?php
// Equipos de la orden (D-41): una orden atiende varios equipos; una serie que no está en
// el padrón del cliente se da de alta desde la orden (también el Técnico, solo así).

[$empE, $adminE] = empresaTemporal('Equipos Orden');
$sxE = bin2hex(random_bytes(2));
$nomE = "Tecnico Equipos $sxE";
$tecE = usuarioTemporal($empE, 'Técnico', ['nombre' => $nomE]);
como($empE, $adminE, 'Administrador');
clienteCatalogo('Cliente Equipos');
clienteCatalogo('Cliente Ajeno Equipos');

function ordenEq(string $tecnico = ''): int {
    global $pdo;
    $pdo->prepare("INSERT INTO incidencias (numero_incidente, cliente, contacto, sucursal, fecha, categoria, estatus, falla, tecnico) VALUES (?, 'Cliente Equipos', 'x', 'Planta Este', CURDATE(), 'General', ?, 'x', ?)")
        ->execute(['EQ-' . bin2hex(random_bytes(3)), $tecnico ? 'Asignado' : 'Abierto', $tecnico]);
    return (int)$pdo->lastInsertId();
}
function equipoPadronEq(string $cliente, string $serie): int {
    global $pdo;
    $pdo->prepare("INSERT INTO padron_equipos (cliente, sucursal, equipo, marca, modelo, numero_serie, origen) VALUES (?, '', 'Báscula', 'Torrey', 'L-EQ', ?, 'Externo')")
        ->execute([$cliente, $serie]);
    return (int)$pdo->lastInsertId();
}
function apiEq(int $emp, int $u, string $rol, string $accion, array $datos): array {
    return llamar($emp, $u, $rol, $accion === 'listar' ? 'GET' : 'POST', 'backend/equipos_orden.php',
        $accion === 'listar' ? ['get' => ['accion' => $accion] + $datos] : ['get' => ['accion' => $accion], 'json' => $datos]);
}

grupo('Equipos de la orden (D-41)');

prueba('D-41: una serie que ya está en el padrón del cliente se liga sin crear otro equipo', function () use ($empE, $adminE) {
    global $pdo;
    $serie = 'SE-' . bin2hex(random_bytes(3));
    $padron = equipoPadronEq('Cliente Equipos', $serie);
    $orden = ordenEq();
    $r = apiEq($empE, $adminE, 'Administrador', 'agregar', ['incidencia_id' => $orden, 'numero_serie' => $serie]);
    como($empE, $adminE, 'Administrador');
    igual(200, $r['http'], 'agregar (' . mb_substr($r['texto'], 0, 150) . '):');
    igual($padron, (int)$pdo->query("SELECT padron_id FROM incidencia_equipos WHERE incidencia_id = $orden")->fetchColumn(), 'padron_id:');
    igual(1, (int)$pdo->query("SELECT COUNT(*) FROM padron_equipos WHERE numero_serie = '$serie'")->fetchColumn(), 'equipos con esa serie:');
});

prueba('D-41: una serie nueva pide equipo, marca y modelo, y se da de alta en el padrón con el cliente y la sucursal de la orden', function () use ($empE, $adminE) {
    global $pdo;
    $serie = 'SN-' . bin2hex(random_bytes(3));
    $orden = ordenEq();
    $r = apiEq($empE, $adminE, 'Administrador', 'agregar', ['incidencia_id' => $orden, 'numero_serie' => $serie]);
    igual(409, $r['http'], 'sin datos del equipo:');
    igual('equipo_nuevo', $r['json']['motivo'] ?? null, 'motivo:');
    $r = apiEq($empE, $adminE, 'Administrador', 'agregar', ['incidencia_id' => $orden, 'numero_serie' => $serie, 'equipo' => 'Minisplit', 'marca' => 'Mirage', 'modelo' => 'X12']);
    como($empE, $adminE, 'Administrador');
    igual(200, $r['http'], 'agregar con datos (' . mb_substr($r['texto'], 0, 150) . '):');
    $p = $pdo->query("SELECT * FROM padron_equipos WHERE numero_serie = '$serie'")->fetch(PDO::FETCH_ASSOC) ?: [];
    igual('Cliente Equipos', $p['cliente'] ?? null, 'cliente:');
    igual('Planta Este', $p['sucursal'] ?? null, 'sucursal:');
    igual('Minisplit', $p['equipo'] ?? null, 'equipo:');
    igual('Externo', $p['origen'] ?? null, 'origen:');
    igual((int)($p['id'] ?? 0), (int)$pdo->query("SELECT padron_id FROM incidencia_equipos WHERE incidencia_id = $orden")->fetchColumn(), 'ligado:');
});

prueba('D-41: no se liga una serie que está en el padrón de otro cliente', function () use ($empE, $adminE) {
    $serie = 'SA-' . bin2hex(random_bytes(3));
    equipoPadronEq('Cliente Ajeno Equipos', $serie);
    $r = apiEq($empE, $adminE, 'Administrador', 'agregar', ['incidencia_id' => ordenEq(), 'numero_serie' => $serie]);
    igual(400, $r['http'], 'serie de otro cliente:');
});

prueba('D-41 · D-9: el Técnico agrega equipos en su orden, no en una ajena', function () use ($empE, $tecE, $nomE) {
    $mia = ordenEq($nomE);
    $r = apiEq($empE, $tecE, 'Técnico', 'agregar', ['incidencia_id' => $mia, 'numero_serie' => 'ST-' . bin2hex(random_bytes(3)), 'equipo' => 'Báscula', 'marca' => 'Torrey', 'modelo' => 'L']);
    igual(200, $r['http'], 'en su orden (' . mb_substr($r['texto'], 0, 150) . '):');
    $r = apiEq($empE, $tecE, 'Técnico', 'agregar', ['incidencia_id' => ordenEq('Otro Tecnico'), 'numero_serie' => 'SX-' . bin2hex(random_bytes(3)), 'equipo' => 'B', 'marca' => 'T', 'modelo' => 'L']);
    verdadero($r['http'] === 404 || $r['http'] === 403, "agregó en una orden ajena (HTTP {$r['http']})");
});

prueba('D-41: la serie no se repite en la orden; quitarla de la orden no la borra del padrón', function () use ($empE, $adminE) {
    global $pdo;
    $serie = 'SR-' . bin2hex(random_bytes(3));
    equipoPadronEq('Cliente Equipos', $serie);
    $orden = ordenEq();
    apiEq($empE, $adminE, 'Administrador', 'agregar', ['incidencia_id' => $orden, 'numero_serie' => $serie]);
    $r = apiEq($empE, $adminE, 'Administrador', 'agregar', ['incidencia_id' => $orden, 'numero_serie' => strtolower($serie)]);
    igual(400, $r['http'], 'repetida:');
    $l = apiEq($empE, $adminE, 'Administrador', 'listar', ['incidencia_id' => $orden]);
    igual([$serie], array_column($l['json']['equipos'] ?? [], 'numero_serie'), 'equipos de la orden:');
    $id = (int)($l['json']['equipos'][0]['id'] ?? 0);
    $r = apiEq($empE, $adminE, 'Administrador', 'quitar', ['incidencia_id' => $orden, 'id' => $id]);
    como($empE, $adminE, 'Administrador');
    igual(200, $r['http'], 'quitar:');
    igual(0, (int)$pdo->query("SELECT COUNT(*) FROM incidencia_equipos WHERE incidencia_id = $orden")->fetchColumn(), 'equipos de la orden:');
    igual(1, (int)$pdo->query("SELECT COUNT(*) FROM padron_equipos WHERE numero_serie = '$serie'")->fetchColumn(), 'sigue en el padrón:');
});

prueba('D-41: la serie que se captura al guardar la orden queda también en sus equipos', function () use ($empE, $adminE) {
    global $pdo;
    $serie = 'SG-' . bin2hex(random_bytes(3));
    $padron = equipoPadronEq('Cliente Equipos', $serie);
    $r = llamar($empE, $adminE, 'Administrador', 'POST', 'backend/server.php', ['post' => [
        'reporte_cliente' => '', 'cliente' => 'Cliente Equipos', 'contacto' => 'x', 'sucursal' => '', 'fecha' => date('Y-m-d'),
        'tecnico' => '', 'status' => 'Abierto', 'falla' => 'x', 'notas' => '', 'numero_serie' => $serie]]);
    como($empE, $adminE, 'Administrador');
    $orden = (int)($r['json']['id'] ?? 0);
    igual($padron, (int)$pdo->query("SELECT padron_id FROM incidencia_equipos WHERE incidencia_id = $orden")->fetchColumn(), 'equipo de la orden:');
});

prueba('D-41: una serie capturada al guardar que no está en el padrón se puede dar de alta después desde la orden', function () use ($empE, $adminE) {
    global $pdo;
    $serie = 'SP-' . bin2hex(random_bytes(3));
    $r = llamar($empE, $adminE, 'Administrador', 'POST', 'backend/server.php', ['post' => [
        'reporte_cliente' => '', 'cliente' => 'Cliente Equipos', 'contacto' => 'x', 'sucursal' => '', 'fecha' => date('Y-m-d'),
        'tecnico' => '', 'status' => 'Abierto', 'falla' => 'x', 'notas' => '', 'numero_serie' => $serie]]);
    $orden = (int)($r['json']['id'] ?? 0);
    como($empE, $adminE, 'Administrador');
    igual(null, $pdo->query("SELECT padron_id FROM incidencia_equipos WHERE incidencia_id = $orden")->fetchColumn() ?: null, 'sin padrón al guardar:');
    $r = apiEq($empE, $adminE, 'Administrador', 'agregar', ['incidencia_id' => $orden, 'numero_serie' => $serie, 'equipo' => 'Báscula', 'marca' => 'Torrey', 'modelo' => 'L']);
    como($empE, $adminE, 'Administrador');
    igual(200, $r['http'], 'alta (' . mb_substr($r['texto'], 0, 120) . '):');
    igual(1, (int)$pdo->query("SELECT COUNT(*) FROM incidencia_equipos WHERE incidencia_id = $orden")->fetchColumn(), 'filas en la orden:');
    verdadero((int)$pdo->query("SELECT padron_id FROM incidencia_equipos WHERE incidencia_id = $orden")->fetchColumn() > 0, 'no quedó ligada al padrón');
});
