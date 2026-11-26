<?php
// Técnicos asignados válidos y su ciclo de vida (D-10, D-11, D-32, AS-7).
// Hallazgos H-10, H-11, A-H01, A-H04, A-H07 y A-H08 (auditorias/2026-11-10-*).
require_once __DIR__ . '/../backend/lib/incidencias_helpers.php';

[$empV, $adminV] = empresaTemporal('Tecnicos Validos');
[$empOtra] = empresaTemporal('Tecnicos Otra');
$sx = bin2hex(random_bytes(2));
$nomV = "Tecnico Valido $sx";
$tecV = usuarioTemporal($empV, 'Técnico', ['nombre' => $nomV]);
usuarioTemporal($empOtra, 'Técnico', ['nombre' => "Tecnico Ajeno $sx"]);
usuarioTemporal($empV, 'Administrativo', ['nombre' => "Administrativo $sx"]);
como($empV, $adminV, 'Administrador');
clienteCatalogo('C');

function altaV(int $emp, int $u, array $c): array {
    return llamar($emp, $u, 'Administrador', 'POST', 'backend/server.php', ['post' => $c + [
        'reporte_cliente' => '', 'cliente' => 'C', 'contacto' => 'x', 'sucursal' => '', 'fecha' => date('Y-m-d'),
        'tecnico' => '', 'status' => 'Abierto', 'falla' => 'x', 'notas' => '']]);
}
function ordenV(array $c): int {
    global $pdo;
    $f = $c + ['numero_incidente' => 'V-' . bin2hex(random_bytes(3)), 'cliente' => 'C', 'contacto' => 'x', 'fecha' => date('Y-m-d'),
        'categoria' => 'General', 'estatus' => 'Asignado', 'falla' => 'x', 'tecnico' => '', 'notas' => ''];
    $cols = implode(', ', array_map(fn($k) => "`$k`", array_keys($f)));
    $pdo->prepare("INSERT INTO incidencias ($cols) VALUES (" . implode(',', array_fill(0, count($f), '?')) . ')')->execute(array_values($f));
    return (int)$pdo->lastInsertId();
}
function filaV(int $id): array {
    global $pdo;
    return $pdo->query("SELECT * FROM incidencias WHERE id = $id")->fetch(PDO::FETCH_ASSOC);
}
function rechazadoV(array $r, string $msg): void {
    verdadero(empty($r['json']['success']) && $r['http'] >= 400 && $r['http'] < 500, "$msg (HTTP {$r['http']}: " . mb_substr($r['texto'], 0, 120) . ')');
}

grupo('Asignar solo técnicos válidos (D-10)');

prueba('D-10: se asigna un técnico válido de la empresa', function () use ($empV, $adminV, $nomV) {
    $r = altaV($empV, $adminV, ['tecnico' => $nomV, 'status' => 'Asignado']);
    igual(200, $r['http'], 'alta (' . mb_substr($r['texto'], 0, 100) . '):');
    igual($nomV, filaV((int)$r['json']['id'])['tecnico'], 'técnico guardado:');
});

prueba('H-10 · D-10: no se asigna un técnico que no existe', function () use ($empV, $adminV) {
    rechazadoV(altaV($empV, $adminV, ['tecnico' => 'Persona Inventada', 'status' => 'Asignado']), 'se asignó un inexistente');
});

prueba('A-H01 · D-10: no se asigna un técnico de otra empresa', function () use ($empV, $adminV, $sx) {
    rechazadoV(altaV($empV, $adminV, ['tecnico' => "Tecnico Ajeno $sx", 'status' => 'Asignado']), 'se asignó uno de otra empresa');
});

prueba('H-10 · D-10: no se asigna a un Administrativo (rol no asignable)', function () use ($empV, $adminV, $sx) {
    rechazadoV(altaV($empV, $adminV, ['tecnico' => "Administrativo $sx", 'status' => 'Asignado']), 'se asignó a un Administrativo');
});

prueba('D-10: los técnicos repetidos o con espacios se normalizan', function () use ($empV, $adminV, $nomV) {
    $r = altaV($empV, $adminV, ['tecnico' => " $nomV  /  " . mb_strtoupper($nomV) . ' ', 'status' => 'Asignado']);
    igual(200, $r['http'], 'alta:');
    igual($nomV, filaV((int)$r['json']['id'])['tecnico'], 'técnico guardado:');
});

prueba('D-10: al editar, se conserva un técnico histórico que ya estaba en la orden', function () use ($empV, $adminV, $nomV) {
    $id = ordenV(['tecnico' => "Ex Empleado/$nomV"]);
    $f = filaV($id);
    $r = llamar($empV, $adminV, 'Administrador', 'POST', 'backend/actualiza.php', ['post' => [
        'id' => $id, 'version' => versionIncidencia($f), 'reporte_cliente' => '', 'cliente' => 'C', 'contacto' => 'x', 'sucursal' => '',
        'categoria' => 'General', 'fecha' => substr($f['fecha'], 0, 10), 'tecnico' => "Ex Empleado/$nomV", 'estatus' => 'Asignado',
        'falla' => 'x', 'accion' => '', 'notas' => 'editada']]);
    igual(200, $r['http'], 'edición (' . mb_substr($r['texto'], 0, 100) . '):');
});

grupo('Baja, cambio de rol y de nombre (D-11, D-32)');

prueba('H-11 · D-11: al eliminar un técnico, sus órdenes activas quedan sin él y las cerradas lo conservan', function () use ($empV, $adminV, $nomV) {
    $nombre = 'Tecnico Baja ' . bin2hex(random_bytes(2));
    $baja = usuarioTemporal($empV, 'Técnico', ['nombre' => $nombre]);
    $solo = ordenV(['estatus' => 'Asignado', 'tecnico' => $nombre]);
    $compartida = ordenV(['estatus' => 'Pendiente', 'tecnico' => "$nombre/$nomV"]);
    $cerrada = ordenV(['estatus' => 'Cerrado sin factura', 'tecnico' => $nombre]);
    $r = llamar($empV, $adminV, 'Administrador', 'POST', 'backend/eliminar_usuario.php', ['json' => ['id' => $baja]]);
    como($empV, $adminV, 'Administrador');
    igual(200, $r['http'], 'baja:');
    igual('', (string)filaV($solo)['tecnico'], 'técnicos de la que solo era suya:');
    igual('Abierto', filaV($solo)['estatus'], 'estatus de la que solo era suya:');
    igual($nomV, filaV($compartida)['tecnico'], 'técnicos de la compartida:');
    igual('Pendiente', filaV($compartida)['estatus'], 'estatus de la compartida:');
    igual($nombre, filaV($cerrada)['tecnico'], 'la cerrada conserva el nombre:');
});

prueba('D-40: al eliminar un técnico, sus órdenes Completado conservan su nombre y su estatus', function () use ($empV, $adminV) {
    $nombre = 'Tecnico Completo ' . bin2hex(random_bytes(2));
    $baja = usuarioTemporal($empV, 'Técnico', ['nombre' => $nombre]);
    $completada = ordenV(['estatus' => 'Completado', 'tecnico' => $nombre]);
    $r = llamar($empV, $adminV, 'Administrador', 'POST', 'backend/eliminar_usuario.php', ['json' => ['id' => $baja]]);
    como($empV, $adminV, 'Administrador');
    igual(200, $r['http'], 'baja:');
    igual($nombre, filaV($completada)['tecnico'], 'técnico de la completada:');
    igual('Completado', filaV($completada)['estatus'], 'estatus de la completada:');
});

/** Edita un usuario por el endpoint real. */
function editarUsuarioV(int $emp, int $adm, int $u, array $cambios): array {
    global $pdo;
    $f = $pdo->query("SELECT nombre, correo, rol FROM usuarios WHERE id = $u")->fetch(PDO::FETCH_ASSOC);
    return llamar($emp, $adm, 'Administrador', 'POST', 'backend/actualiza-usuario.php', ['json' => $cambios + [
        'id' => $u, 'nombre' => $f['nombre'], 'correo' => $f['correo'], 'telefono' => '', 'rol' => $f['rol'], 'puede_cerrar' => 0]]);
}

prueba('A-H04 · D-32: si un técnico pasa a Administrativo, deja sus órdenes activas', function () use ($empV, $adminV) {
    $nombre = 'Tecnico Cambio ' . bin2hex(random_bytes(2));
    $u = usuarioTemporal($empV, 'Técnico', ['nombre' => $nombre]);
    $orden = ordenV(['tecnico' => $nombre, 'estatus' => 'Asignado']);
    $r = editarUsuarioV($empV, $adminV, $u, ['rol' => 'Administrativo']);
    como($empV, $adminV, 'Administrador');
    igual(200, $r['http'], 'edición (' . mb_substr($r['texto'], 0, 100) . '):');
    igual('', (string)filaV($orden)['tecnico'], 'técnicos de su orden activa:');
    igual('Abierto', filaV($orden)['estatus'], 'estatus:');
});

prueba('A-H04 · D-32: al corregir el nombre de un técnico, sus órdenes activas se actualizan y las cerradas no', function () use ($empV, $adminV) {
    $nombre = 'Tecnico Viejo ' . bin2hex(random_bytes(2));
    $u = usuarioTemporal($empV, 'Técnico', ['nombre' => $nombre]);
    $activa = ordenV(['tecnico' => $nombre, 'estatus' => 'Asignado']);
    $cerrada = ordenV(['tecnico' => $nombre, 'estatus' => 'Cerrado con factura']);
    editarUsuarioV($empV, $adminV, $u, ['nombre' => "$nombre Corregido", 'rol' => 'Técnico']);
    $r = llamar($empV, $u, 'Técnico', 'GET', 'backend/detalle.php', ['get' => ['id' => $activa]]);
    como($empV, $adminV, 'Administrador');
    igual("$nombre Corregido", filaV($activa)['tecnico'], 'técnico de la orden activa:');
    igual($nombre, filaV($cerrada)['tecnico'], 'la cerrada conserva el nombre:');
    igual(200, $r['http'], 'el técnico sigue abriendo su orden:');
});

grupo('Identidad única (AS-7)');

/** Alta de usuario por el endpoint real; devuelve cuántos usuarios hay con ese nombre. */
function altaUsuarioV(int $emp, int $adm, string $nombre): int {
    global $pdo;
    llamar($emp, $adm, 'Administrador', 'POST', 'backend/alta-user.php', ['post' => [
        'nombre' => $nombre, 'correo' => 'v-' . bin2hex(random_bytes(4)) . '@pruebas.test', 'telefono' => '',
        'password' => 'Prueba2026!', 'rol' => 'Técnico']]);
    como($emp, $adm, 'Administrador');
    $st = $pdo->prepare('SELECT COUNT(*) FROM usuarios WHERE empresa_id = ? AND nombre = ?');
    $st->execute([$emp, $nombre]);
    return (int)$st->fetchColumn();
}

prueba('A-H07 · AS-7: no se dan de alta dos usuarios con el mismo nombre en la empresa', function () use ($empV, $adminV) {
    $nombre = 'Luis Repetido ' . bin2hex(random_bytes(2));
    altaUsuarioV($empV, $adminV, $nombre);
    igual(1, altaUsuarioV($empV, $adminV, mb_strtoupper($nombre)) + altaUsuarioV($empV, $adminV, $nombre) - 1, 'usuarios con ese nombre (sin distinguir mayúsculas):');
});

prueba('A-H08 · AS-1: el nombre de un usuario no puede llevar "/"', function () use ($empV, $adminV) {
    igual(0, altaUsuarioV($empV, $adminV, 'Juan / Taller ' . bin2hex(random_bytes(2))), 'usuarios creados con "/":');
});

prueba('AS-7: el mismo nombre sí se permite en otra empresa', function () use ($empOtra, $empV, $adminV, $nomV) {
    global $pdo;
    $adminOtra = (int)$pdo->query("SELECT id FROM usuarios WHERE empresa_id = $empOtra AND rol = 'Administrador'")->fetchColumn();
    igual(1, altaUsuarioV($empOtra, $adminOtra, $nomV), 'usuario en la otra empresa:');
    como($empV, $adminV, 'Administrador');
});
