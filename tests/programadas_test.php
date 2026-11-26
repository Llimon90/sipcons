<?php
// Órdenes programadas (D-42): una orden con fecha futura queda fuera de la bandeja, los
// contadores, las alertas y el SLA hasta su fecha; se consulta en "Programadas".
require_once __DIR__ . '/../backend/lib/incidencias_helpers.php';

[$empP, $adminP] = empresaTemporal('Programadas');
$sxP = bin2hex(random_bytes(2));
$nomP = "Tecnico Programado $sxP";
$tecP = usuarioTemporal($empP, 'Técnico', ['nombre' => $nomP]);
como($empP, $adminP, 'Administrador');
clienteCatalogo('Cliente Programado');

function ordenP(string $fecha, string $tecnico = ''): array {
    global $pdo;
    $folio = 'PR-' . bin2hex(random_bytes(3));
    $pdo->prepare("INSERT INTO incidencias (numero_incidente, cliente, contacto, sucursal, fecha, categoria, estatus, falla, tecnico) VALUES (?, 'Cliente Programado', 'x', '', ?, 'General', ?, 'x', ?)")
        ->execute([$folio, $fecha, $tecnico ? 'Asignado' : 'Abierto', $tecnico]);
    return [(int)$pdo->lastInsertId(), $folio];
}
function foliosLista(array $r): array {
    return is_array($r['json']) && isset($r['json'][0]) ? array_column($r['json'], 'numero_incidente') : [];
}
$manana = date('Y-m-d', strtotime('+1 day'));
$hoy = date('Y-m-d');

grupo('Órdenes programadas (D-42)');

prueba('D-42: una orden con fecha futura no aparece en la lista de órdenes activas, sí en "Programadas"', function () use ($empP, $adminP, $manana) {
    [, $folio] = ordenP($manana);
    $activas = llamar($empP, $adminP, 'Administrador', 'GET', 'backend/server.php');
    verdadero(!in_array($folio, foliosLista($activas), true), 'la programada salió en la lista de activas');
    $prog = llamar($empP, $adminP, 'Administrador', 'GET', 'backend/server.php', ['get' => ['programadas' => '1']]);
    verdadero(in_array($folio, foliosLista($prog), true), 'la programada no salió en "Programadas": ' . mb_substr($prog['texto'], 0, 150));
});

prueba('D-42: el día indicado la orden se vuelve activa sola', function () use ($empP, $adminP, $hoy) {
    [, $folio] = ordenP($hoy);
    $activas = llamar($empP, $adminP, 'Administrador', 'GET', 'backend/server.php');
    verdadero(in_array($folio, foliosLista($activas), true), 'la orden de hoy no está en la lista de activas');
});

prueba('D-42: Inicio no cuenta las programadas en la bandeja ni en los contadores, y las lista como próximas', function () use ($empP, $adminP, $manana) {
    global $pdo;
    $pdo->exec("DELETE FROM incidencias");
    [, $folio] = ordenP($manana);
    $r = llamar($empP, $adminP, 'Administrador', 'GET', 'backend/inicio.php', ['get' => ['accion' => 'panel']]);
    igual(0, (int)($r['json']['indicadores']['abiertas'] ?? -1), 'abiertas:');
    igual([], array_column($r['json']['bandeja'] ?? [], 'numero_incidente'), 'bandeja:');
    igual([$folio], array_column($r['json']['programadas'] ?? [], 'numero_incidente'), 'próximas programadas:');
    $c = llamar($empP, $adminP, 'Administrador', 'GET', 'backend/inicio.php', ['get' => ['accion' => 'contadores']]);
    igual(0, (int)($c['json']['incidencias'] ?? -1), 'contador del menú:');
});

prueba('D-42 · D-9: el Técnico ve sus programadas y no las ajenas', function () use ($empP, $tecP, $nomP, $manana) {
    [, $mia] = ordenP($manana, $nomP);
    [, $ajena] = ordenP($manana, 'Otro Tecnico');
    $prog = llamar($empP, $tecP, 'Técnico', 'GET', 'backend/server.php', ['get' => ['programadas' => '1']]);
    $folios = foliosLista($prog);
    verdadero(in_array($mia, $folios, true), 'no ve su programada');
    verdadero(!in_array($ajena, $folios, true), 've una programada ajena');
});

prueba('D-42: una programada no cuenta como atrasada', function () use ($manana) {
    igual(false, ordenAtrasada(date('Y-m-d', strtotime('+30 days')), 'Abierto'), 'atrasada:');
});
