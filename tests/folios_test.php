<?php
// Folios y doble envío (D-7, CV-12, MT-6, D-36). Hallazgos H-09, H-13
// (auditorias/2026-11-10-ciclo-vida.md) y C-H03, C-H04, C-H05 (2026-11-10-concurrencia.md).
require_once __DIR__ . '/../backend/lib/incidencias_helpers.php';

[$empF, $adminF] = empresaTemporal('Folios');
como($empF, $adminF, 'Administrador');

function altaF(int $emp, int $u, array $c = []): array {
    $actual = EmpresaContexto::$id;
    establecerEmpresa($emp);
    clienteCatalogo($c['cliente'] ?? 'CLIENTE FOLIOS');
    establecerEmpresa($actual);
    return llamar($emp, $u, 'Administrador', 'POST', 'backend/server.php', ['post' => $c + [
        'reporte_cliente' => '', 'cliente' => 'CLIENTE FOLIOS', 'contacto' => 'x', 'sucursal' => '', 'fecha' => date('Y-m-d'),
        'tecnico' => '', 'status' => 'Abierto', 'falla' => 'Servicio', 'notas' => '']]);
}
function contarF(string $sql, array $p = []): int {
    global $pdo;
    $st = $pdo->prepare($sql);
    $st->execute($p);
    return (int)$st->fetchColumn();
}

grupo('Doble envío del alta (D-7)');

prueba('H-09 · D-7: el mismo envío (misma clave) no crea dos órdenes y responde la que ya existe', function () use ($empF, $adminF) {
    $clave = 'prueba-' . bin2hex(random_bytes(8));
    $cliente = 'DOBLE ENVIO ' . bin2hex(random_bytes(2));
    $a = altaF($empF, $adminF, ['cliente' => $cliente, 'clave_envio' => $clave]);
    $b = altaF($empF, $adminF, ['cliente' => $cliente, 'clave_envio' => $clave]);
    como($empF, $adminF, 'Administrador');
    igual(1, contarF('SELECT COUNT(*) FROM incidencias WHERE cliente = ?', [$cliente]), 'órdenes creadas con la misma clave:');
    igual(200, $b['http'], 'el segundo envío responde (' . mb_substr($b['texto'], 0, 100) . '):');
    igual($a['json']['numero_incidente'] ?? 'a', $b['json']['numero_incidente'] ?? 'b', 'el segundo envío devuelve el mismo folio:');
});

prueba('C-H04 · D-7: dos envíos simultáneos con la misma clave crean una sola orden', function () use ($empF, $adminF) {
    $clave = 'sim-' . bin2hex(random_bytes(8));
    $cliente = 'SIMULTANEO ' . bin2hex(random_bytes(2));
    clienteCatalogo($cliente);
    $r = llamarSimultaneo(4, $empF, $adminF, 'Administrador', 'POST', 'backend/server.php', ['post' => [
        'reporte_cliente' => '', 'cliente' => $cliente, 'contacto' => 'x', 'sucursal' => '', 'fecha' => date('Y-m-d'),
        'tecnico' => '', 'status' => 'Abierto', 'falla' => 'Servicio', 'notas' => '', 'clave_envio' => $clave]]);
    como($empF, $adminF, 'Administrador');
    igual(1, contarF('SELECT COUNT(*) FROM incidencias WHERE cliente = ?', [$cliente]), 'órdenes creadas:');
    igual(4, count(array_filter($r, fn($x) => !empty($x['json']['success']))), 'envíos que respondieron éxito:');
});

prueba('D-7: claves distintas sí crean órdenes distintas', function () use ($empF, $adminF) {
    $cliente = 'DOS CLAVES ' . bin2hex(random_bytes(2));
    altaF($empF, $adminF, ['cliente' => $cliente, 'clave_envio' => 'k1-' . bin2hex(random_bytes(6))]);
    altaF($empF, $adminF, ['cliente' => $cliente, 'clave_envio' => 'k2-' . bin2hex(random_bytes(6))]);
    como($empF, $adminF, 'Administrador');
    igual(2, contarF('SELECT COUNT(*) FROM incidencias WHERE cliente = ?', [$cliente]), 'órdenes creadas:');
});

grupo('Folios de órdenes (CV-12, MT-6)');

prueba('H-13 · CV-12: el folio de una orden eliminada no se vuelve a usar', function () use ($empF, $adminF) {
    $r = altaF($empF, $adminF);
    $folio = $r['json']['numero_incidente'] ?? '';
    $b = llamar($empF, $adminF, 'Administrador', 'POST', 'backend/eliminar-incidencia.php', ['json' => ['id' => $r['json']['id'] ?? 0]]);
    igual(200, $b['http'], 'eliminación:');
    $nueva = altaF($empF, $adminF);
    como($empF, $adminF, 'Administrador');
    verdadero(($nueva['json']['numero_incidente'] ?? $folio) !== $folio, "se reutilizó el folio $folio");
});

prueba('C-H05 · CV-12: altas simultáneas reciben folios distintos', function () use ($empF, $adminF) {
    $cliente = 'FOLIOS SIM ' . bin2hex(random_bytes(2));
    clienteCatalogo($cliente);
    $r = llamarSimultaneo(5, $empF, $adminF, 'Administrador', 'POST', 'backend/server.php', ['post' => [
        'reporte_cliente' => '', 'cliente' => $cliente, 'contacto' => 'x', 'sucursal' => '', 'fecha' => date('Y-m-d'),
        'tecnico' => '', 'status' => 'Abierto', 'falla' => 'Servicio', 'notas' => '']]);
    como($empF, $adminF, 'Administrador');
    $folios = array_filter(array_map(fn($x) => $x['json']['numero_incidente'] ?? null, $r));
    igual(5, count($folios), 'altas con folio:');
    igual(5, count(array_unique($folios)), 'folios distintos:');
});

prueba('CV-12: la base no acepta dos órdenes con el mismo folio', function () {
    global $pdo;
    $folio = 'DUP-' . bin2hex(random_bytes(3));
    $ins = $pdo->prepare("INSERT INTO incidencias (numero_incidente, cliente, contacto, fecha, categoria, estatus, falla, tecnico) VALUES (?, 'C', 'x', CURDATE(), 'General', 'Abierto', 'x', '')");
    $ins->execute([$folio]);
    lanza(fn() => $ins->execute([$folio]), PDOException::class);
});

prueba('D-36 · MT-6: dos empresas pueden tener el mismo folio', function () use ($empF, $adminF) {
    [$e1, $a1] = empresaTemporal('Folio Uno');
    [$e2, $a2] = empresaTemporal('Folio Dos');
    $f1 = altaF($e1, $a1)['json']['numero_incidente'] ?? '1';
    $f2 = altaF($e2, $a2)['json']['numero_incidente'] ?? '2';
    como($empF, $adminF, 'Administrador');
    igual($f1, $f2, 'primer folio de cada empresa:');
});

grupo('Folios de ventas (C-H03)');

prueba('C-H03: ventas simultáneas reciben folios distintos', function () use ($empF, $adminF) {
    $cliente = 'VENTAS SIM ' . bin2hex(random_bytes(2));
    $r = [];
    $procs = llamarSimultaneo(4, $empF, $adminF, 'Administrador', 'POST', 'backend/registro_ventas.php', ['post' => [
        'cliente' => $cliente, 'sucursal' => '', 'series' => json_encode(['S/N']), 'equipo' => 'Equipo', 'marca' => 'M', 'modelo' => 'X',
        'garantia' => 0, 'notas' => '', 'fecha_venta' => date('Y-m-d')]]);
    como($empF, $adminF, 'Administrador');
    $folios = array_filter(array_map(fn($x) => $x['json']['folio'] ?? null, $procs));
    igual(4, count($folios), 'ventas con folio (' . mb_substr($procs[0]['texto'], 0, 120) . '):');
    igual(4, count(array_unique($folios)), 'folios distintos:');
});

prueba('C-H03: dos ventas simultáneas con la misma serie no la registran dos veces', function () use ($empF, $adminF) {
    $serie = 'SER-' . bin2hex(random_bytes(4));
    llamarSimultaneo(3, $empF, $adminF, 'Administrador', 'POST', 'backend/registro_ventas.php', ['post' => [
        'cliente' => 'SERIE SIM', 'sucursal' => '', 'series' => json_encode([$serie]), 'equipo' => 'Equipo', 'marca' => 'M', 'modelo' => 'X',
        'garantia' => 0, 'notas' => '', 'fecha_venta' => date('Y-m-d')]]);
    como($empF, $adminF, 'Administrador');
    igual(1, contarF('SELECT COUNT(*) FROM padron_equipos WHERE numero_serie = ?', [$serie]), 'equipos con esa serie:');
});

grupo('Nombre de usuario único por empresa (AS-7, D-36)');

prueba('AS-7: la base no acepta dos usuarios con el mismo nombre en la empresa, pero sí en otra', function () use ($empF) {
    global $pdo;
    [$otra] = empresaTemporal('Folios Otra');
    $nombre = 'Usuario Unico ' . bin2hex(random_bytes(3));
    usuarioTemporal($empF, 'Técnico', ['nombre' => $nombre]);
    usuarioTemporal($otra, 'Técnico', ['nombre' => $nombre]);
    lanza(fn() => usuarioTemporal($empF, 'Técnico', ['nombre' => mb_strtoupper($nombre)]), PDOException::class);
});
