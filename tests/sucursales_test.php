<?php
// Multisucursal (D-44): cada cliente tiene su catálogo de sucursales; órdenes, padrón,
// ventas y cotizaciones guardan la sucursal (sucursal_id) además del texto.
require_once __DIR__ . '/../backend/lib/sucursales_helpers.php';
require_once __DIR__ . '/../backend/lib/cotizaciones_helpers.php';

[$empS, $adminS] = empresaTemporal('Sucursales');
$tecS = usuarioTemporal($empS, 'Técnico', ['nombre' => 'Tecnico Sucursal ' . bin2hex(random_bytes(2))]);
como($empS, $adminS, 'Administrador');
clienteCatalogo('Maquiladora Norte');
clienteCatalogo('Maquiladora Sur');
$cliNorte = (int)$pdo->query("SELECT id FROM clientes WHERE nombre = 'Maquiladora Norte'")->fetchColumn();
$cliSur = (int)$pdo->query("SELECT id FROM clientes WHERE nombre = 'Maquiladora Sur'")->fetchColumn();

function altaS(int $emp, int $u, string $cliente, string $sucursal): array {
    return llamar($emp, $u, 'Administrador', 'POST', 'backend/server.php', ['post' => [
        'reporte_cliente' => '', 'cliente' => $cliente, 'contacto' => 'x', 'sucursal' => $sucursal, 'fecha' => date('Y-m-d'),
        'tecnico' => '', 'status' => 'Abierto', 'falla' => 'x', 'notas' => '']]);
}
function sucursalesS(int $cliente): array {
    global $pdo;
    return $pdo->query("SELECT id, nombre FROM cliente_sucursales WHERE cliente_id = $cliente ORDER BY id")->fetchAll(PDO::FETCH_KEY_PAIR);
}
function apiS(int $emp, int $u, string $rol, string $accion, array $get = [], ?array $json = null): array {
    return llamar($emp, $u, $rol, $json === null ? 'GET' : 'POST', 'backend/sucursales.php', ['get' => ['accion' => $accion] + $get, 'json' => $json]);
}

grupo('Catálogo de sucursales (D-44)');

prueba('D-44: el alta de una orden con una sucursal nueva la da de alta en el cliente y la liga; el mismo texto la reutiliza', function () use ($empS, $adminS, $cliNorte) {
    global $pdo;
    $r1 = altaS($empS, $adminS, 'Maquiladora Norte', 'Planta Uno');
    $r2 = altaS($empS, $adminS, 'Maquiladora Norte', '  planta uno ');
    como($empS, $adminS, 'Administrador');
    igual(200, $r1['http'], 'alta (' . mb_substr($r1['texto'], 0, 120) . '):');
    $suc = sucursalesS($cliNorte);
    igual(['Planta Uno'], array_values($suc), 'sucursales del cliente:');
    $ids = $pdo->query("SELECT DISTINCT sucursal_id FROM incidencias WHERE id IN ({$r1['json']['id']}, {$r2['json']['id']})")->fetchAll(PDO::FETCH_COLUMN);
    igual([(string)array_key_first($suc)], array_map('strval', $ids), 'sucursal_id de las dos órdenes:');
});

prueba('D-44: dos clientes con una sucursal del mismo nombre tienen sucursales distintas', function () use ($empS, $adminS, $cliNorte, $cliSur) {
    altaS($empS, $adminS, 'Maquiladora Sur', 'Planta Uno');
    como($empS, $adminS, 'Administrador');
    $norte = array_key_first(sucursalesS($cliNorte));
    $sur = array_key_first(sucursalesS($cliSur));
    verdadero($norte && $sur && $norte !== $sur, "las sucursales se mezclaron ($norte, $sur)");
});

prueba('D-44: la sincronización liga los registros anteriores (padrón y ventas) que solo tenían el texto', function () use ($empS, $adminS, $cliNorte) {
    global $pdo;
    como($empS, $adminS, 'Administrador');
    $pdo->prepare("INSERT INTO padron_equipos (cliente, sucursal, equipo, marca, modelo, numero_serie, origen) VALUES ('Maquiladora Norte', 'Planta Dos', 'Báscula', 'M', 'X', ?, 'Externo')")
        ->execute(['PD-' . bin2hex(random_bytes(3))]);
    $equipo = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO ventas (folio, cliente, sucursal, fecha_registro) VALUES (?, 'Maquiladora Norte', 'Planta Dos', CURDATE())")
        ->execute(['VS-' . bin2hex(random_bytes(3))]);
    $venta = (int)$pdo->lastInsertId();
    sucSincronizarCliente($pdo, $cliNorte);
    $planta = array_search('Planta Dos', sucursalesS($cliNorte), true);
    verdadero($planta !== false, 'no se creó la sucursal Planta Dos');
    igual($planta, (int)$pdo->query("SELECT sucursal_id FROM padron_equipos WHERE id = $equipo")->fetchColumn(), 'sucursal del equipo:');
    igual($planta, (int)$pdo->query("SELECT sucursal_id FROM ventas WHERE id = $venta")->fetchColumn(), 'sucursal de la venta:');
});

prueba('D-44: la cotización guarda la sucursal del cliente', function () use ($empS, $adminS, $cliSur) {
    como($empS, $adminS, 'Administrador');
    global $pdo;
    $cot = cotGuardar($pdo, 0, ['cliente_id' => $cliSur, 'sucursal' => 'Planta Uno', 'fecha' => date('Y-m-d'),
        'vigencia_hasta' => date('Y-m-d', strtotime('+15 days')), 'moneda' => 'MXN', 'tipo_cambio' => 1], []);
    igual(array_key_first(sucursalesS($cliSur)), (int)$cot['sucursal_id'], 'sucursal_id:');
});

prueba('D-44: la lista de sucursales trae sus equipos y órdenes activas', function () use ($empS, $adminS, $cliNorte) {
    $r = apiS($empS, $adminS, 'Administrador', 'listar', ['cliente_id' => $cliNorte]);
    igual(200, $r['http'], 'listar (' . mb_substr($r['texto'], 0, 150) . '):');
    $porNombre = array_column($r['json']['sucursales'] ?? [], null, 'nombre');
    igual(2, (int)($porNombre['Planta Uno']['ordenes_activas'] ?? -1), 'órdenes activas de Planta Uno:');
    igual(1, (int)($porNombre['Planta Dos']['equipos'] ?? -1), 'equipos de Planta Dos:');
});

prueba('D-44: renombrar una sucursal actualiza el padrón y las órdenes activas, no las cerradas', function () use ($empS, $adminS, $cliNorte) {
    global $pdo;
    como($empS, $adminS, 'Administrador');
    $planta = array_search('Planta Dos', sucursalesS($cliNorte), true);
    $pdo->prepare("INSERT INTO incidencias (numero_incidente, cliente, contacto, sucursal, sucursal_id, fecha, categoria, estatus, falla, tecnico) VALUES (?, 'Maquiladora Norte', 'x', 'Planta Dos', ?, CURDATE(), 'General', 'Cerrado sin factura', 'x', '')")
        ->execute(['SC-' . bin2hex(random_bytes(3)), $planta]);
    $cerrada = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO incidencias (numero_incidente, cliente, contacto, sucursal, sucursal_id, fecha, categoria, estatus, falla, tecnico) VALUES (?, 'Maquiladora Norte', 'x', 'Planta Dos', ?, CURDATE(), 'General', 'Abierto', 'x', '')")
        ->execute(['SA-' . bin2hex(random_bytes(3)), $planta]);
    $activa = (int)$pdo->lastInsertId();
    $r = apiS($empS, $adminS, 'Administrador', 'guardar', [], ['id' => $planta, 'cliente_id' => $cliNorte, 'nombre' => 'Planta Dos (Parque Industrial)', 'direccion' => 'Av. Uno 100']);
    como($empS, $adminS, 'Administrador');
    igual(200, $r['http'], 'guardar (' . mb_substr($r['texto'], 0, 150) . '):');
    igual('Planta Dos (Parque Industrial)', $pdo->query("SELECT sucursal FROM padron_equipos WHERE sucursal_id = $planta LIMIT 1")->fetchColumn(), 'padrón:');
    igual('Planta Dos (Parque Industrial)', $pdo->query("SELECT sucursal FROM incidencias WHERE id = $activa")->fetchColumn(), 'orden activa:');
    igual('Planta Dos', $pdo->query("SELECT sucursal FROM incidencias WHERE id = $cerrada")->fetchColumn(), 'orden cerrada:');
});

prueba('D-44: no se repite el nombre de una sucursal en el mismo cliente', function () use ($empS, $adminS, $cliNorte) {
    $r = apiS($empS, $adminS, 'Administrador', 'guardar', [], ['cliente_id' => $cliNorte, 'nombre' => 'PLANTA UNO']);
    igual(400, $r['http'], 'duplicada:');
});

prueba('D-44: el Técnico consulta las sucursales pero no las edita desde el catálogo', function () use ($empS, $tecS, $cliNorte) {
    $r = apiS($empS, $tecS, 'Técnico', 'listar', ['cliente_id' => $cliNorte]);
    igual(200, $r['http'], 'listar:');
    $r = apiS($empS, $tecS, 'Técnico', 'guardar', [], ['cliente_id' => $cliNorte, 'nombre' => 'Planta Tres']);
    igual(403, $r['http'], 'guardar:');
});
