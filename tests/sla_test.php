<?php
// SLA, atraso, estadísticas y zona horaria (D-15 a D-18, D-29, D-30, D-33).
// Hallazgos S-H02 a S-H05 (auditorias/2026-11-10-sla.md) y H-01, H-02, H-05 de
// auditorias/2026-11-10-diff-fix-estatus-motivos.md.
require_once __DIR__ . '/../backend/lib/estadisticas_helpers.php';
require_once __DIR__ . '/../backend/lib/incidencias_helpers.php';
require_once __DIR__ . '/../config/marca_empresa.php';
require_once __DIR__ . '/../config/provision.php';

[$empS, $adminS] = empresaTemporal('SLA');
$sxS = bin2hex(random_bytes(2));
$nomS = "Tecnico SLA $sxS";
$tecS = usuarioTemporal($empS, 'Técnico', ['nombre' => $nomS]);
como($empS, $adminS, 'Administrador');

/** Orden con eventos de historial [accion, estatus_antes, estatus_despues, creado_en, extra_nuevos]. */
function ordenConHistorial(array $fila, array $eventos): array {
    global $pdo;
    $f = $fila + ['numero_incidente' => 'S-' . bin2hex(random_bytes(3)), 'cliente' => 'C', 'contacto' => 'x', 'fecha' => '2026-01-01',
        'categoria' => 'General', 'estatus' => 'Abierto', 'falla' => 'x', 'tecnico' => ''];
    $cols = implode(', ', array_map(fn($c) => "`$c`", array_keys($f)));
    $pdo->prepare("INSERT INTO incidencias ($cols) VALUES (" . implode(',', array_fill(0, count($f), '?')) . ')')->execute(array_values($f));
    $id = (int)$pdo->lastInsertId();
    $ev = $pdo->prepare("INSERT INTO auditoria (usuario_id, accion, tabla, registro_id, datos_anteriores, datos_nuevos, creado_en) VALUES (1, ?, 'incidencias', ?, ?, ?, ?)");
    foreach ($eventos as [$accion, $antes, $despues, $cuando, $extra]) {
        $ev->execute([$accion, $id, $antes === null ? null : json_encode(['estatus' => $antes]),
            json_encode(['estatus' => $despues] + ($extra ?? [])), $cuando]);
    }
    $st = $pdo->prepare('SELECT * FROM incidencias WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch(PDO::FETCH_ASSOC);
}
function ordenS(array $c): int {
    global $pdo;
    $f = $c + ['numero_incidente' => 'S-' . bin2hex(random_bytes(3)), 'cliente' => 'C', 'contacto' => 'x', 'fecha' => date('Y-m-d'),
        'categoria' => 'General', 'estatus' => 'Asignado', 'falla' => 'x', 'tecnico' => ''];
    $cols = implode(', ', array_map(fn($k) => "`$k`", array_keys($f)));
    $pdo->prepare("INSERT INTO incidencias ($cols) VALUES (" . implode(',', array_fill(0, count($f), '?')) . ')')->execute(array_values($f));
    return (int)$pdo->lastInsertId();
}
function haceDias(int $n): string { return date('Y-m-d', strtotime("-$n days")); }

grupo('SLA de cierre (D-15, D-17, D-30)');

prueba('S-H04 · D-15: una orden cancelada no cuenta como respuesta ni como cierre', function () {
    global $pdo;
    $f = ordenConHistorial(['estatus' => 'Cancelado'], [
        ['CREATE', null, 'Abierto', '2026-01-01 08:00:00', null],
        ['UPDATE', 'Abierto', 'Asignado', '2026-01-01 08:30:00', null],
        ['UPDATE', 'Asignado', 'Completado', '2026-01-01 09:00:00', null],
        ['UPDATE', 'Completado', 'Cancelado', '2026-01-02 09:00:00', null],
    ]);
    $a = analizarTiemposIncidencias($pdo, [$f]);
    igual(0, $a['agregado']['muestras_respuesta'], 'muestras de respuesta:');
    igual(0, $a['agregado']['muestras_cierre'], 'muestras de cierre:');
});

prueba('S-H03 · D-15: una orden reabierta que sigue activa no cuenta como cerrada', function () {
    global $pdo;
    $f = ordenConHistorial(['estatus' => 'Asignado', 'tecnico' => 'x'], [
        ['CREATE', null, 'Abierto', '2026-01-01 08:00:00', null],
        ['UPDATE', 'Abierto', 'Completado', '2026-01-01 10:00:00', null],
        ['UPDATE', 'Completado', 'Asignado', '2026-01-02 08:00:00', null],
    ]);
    $a = analizarTiemposIncidencias($pdo, [$f]);
    igual(null, $a['por_incidencia'][(string)$f['id']]['cierre_horas'], 'horas de cierre de una orden activa:');
});

prueba('H-01 · D-15: si se reabre, el SLA llega hasta la última vez que se completó', function () {
    global $pdo;
    $f = ordenConHistorial(['estatus' => 'Cerrado con factura', 'tecnico' => 'x'], [
        ['CREATE', null, 'Abierto', '2026-01-01 08:00:00', null],
        ['UPDATE', 'Abierto', 'Completado', '2026-01-01 10:00:00', null],
        ['UPDATE', 'Completado', 'Asignado', '2026-01-02 08:00:00', null],
        ['UPDATE', 'Asignado', 'Completado', '2026-01-03 08:00:00', null],
        ['UPDATE', 'Completado', 'Cerrado con factura', '2026-01-05 08:00:00', null],
    ]);
    $a = analizarTiemposIncidencias($pdo, [$f]);
    igual(48.0, (float)$a['por_incidencia'][(string)$f['id']]['cierre_horas'], 'horas de cierre (del alta al último Completado):');
});

prueba('D-15: el cierre administrativo después de Completado no alarga el SLA', function () {
    global $pdo;
    $f = ordenConHistorial(['estatus' => 'Cerrado sin factura', 'tecnico' => 'x'], [
        ['CREATE', null, 'Abierto', '2026-01-01 08:00:00', null],
        ['UPDATE', 'Abierto', 'Completado', '2026-01-01 20:00:00', null],
        ['UPDATE', 'Completado', 'Cerrado sin factura', '2026-01-09 08:00:00', null],
    ]);
    $a = analizarTiemposIncidencias($pdo, [$f]);
    igual(12.0, (float)$a['por_incidencia'][(string)$f['id']]['cierre_horas'], 'horas de cierre:');
});

prueba('S-H05 · D-17: el cierre se atribuye a quien la completó, no a quien la tiene hoy', function () {
    global $pdo;
    $f = ordenConHistorial(['estatus' => 'Cerrado con factura', 'tecnico' => 'Pedro Gomez'], [
        ['CREATE', null, 'Abierto', '2026-01-01 08:00:00', null],
        ['UPDATE', 'Abierto', 'Completado', '2026-01-01 12:00:00', ['tecnico' => 'Juan Perez']],
        ['UPDATE', 'Completado', 'Cerrado con factura', '2026-01-03 12:00:00', ['tecnico' => 'Pedro Gomez']],
    ]);
    $a = analizarTiemposIncidencias($pdo, [$f]);
    verdadero(isset($a['por_tecnico']['Juan Perez']), 'Juan Perez (quien la completó) no aparece en el SLA por técnico');
    verdadero(!isset($a['por_tecnico']['Pedro Gomez']), 'Pedro Gomez recibe un cierre que no hizo');
});

prueba('D-30: una orden capturada ya completada o cerrada no entra a las muestras de SLA', function () {
    global $pdo;
    $f = ordenConHistorial(['estatus' => 'Cerrado con factura', 'tecnico' => 'x'], [
        ['CREATE', null, 'Completado', '2026-01-10 08:00:00', null],
        ['UPDATE', 'Completado', 'Cerrado con factura', '2026-01-11 08:00:00', null],
    ]);
    $a = analizarTiemposIncidencias($pdo, [$f]);
    igual(0, $a['agregado']['muestras_cierre'], 'muestras de cierre:');
    igual(0, $a['agregado']['muestras_respuesta'], 'muestras de respuesta:');
});

grupo('Atraso: un solo cálculo (D-18, D-33)');

prueba('H-05 · D-18: con meta de N días, el día N no está atrasada en alertas, Inicio ni panel; el día N+1 sí', function () use ($empS, $adminS, $tecS, $nomS) {
    $sla = slaDiasEmpresa();
    $enMeta = ordenS(['fecha' => haceDias($sla), 'tecnico' => $nomS]);
    $atrasada = ordenS(['fecha' => haceDias($sla + 1), 'tecnico' => $nomS]);
    $al = llamar($empS, $adminS, 'Administrador', 'GET', 'backend/alertas_incidentes.php');
    $ids = array_map('intval', array_column($al['json']['alertas'] ?? [], 'id'));
    verdadero(!in_array($enMeta, $ids, true), "alertas: la orden del día $sla ya sale atrasada");
    verdadero(in_array($atrasada, $ids, true), 'alertas: falta la orden atrasada');
    $panel = llamar($empS, $tecS, 'Técnico', 'GET', 'backend/estadisticas_tecnico.php');
    igual(1, (int)($panel['json']['data']['resumen']['vencidas'] ?? -1), 'vencidas en el panel del técnico:');
    $ini = llamar($empS, $tecS, 'Técnico', 'GET', 'backend/inicio.php', ['get' => ['accion' => 'panel']]);
    igual(1, (int)($ini['json']['indicadores']['atrasadas'] ?? -1), 'atrasadas en Inicio del técnico:');
    como($empS, $adminS, 'Administrador');
});

prueba('D-33: Inicio avisa de las completadas con más de 5 días sin cerrar', function () use ($empS, $adminS) {
    $vieja = ordenConHistorial(['estatus' => 'Completado', 'tecnico' => 'x', 'fecha' => haceDias(20)], [
        ['CREATE', null, 'Abierto', haceDias(20) . ' 08:00:00', null],
        ['UPDATE', 'Abierto', 'Completado', haceDias(6) . ' 08:00:00', null],
    ]);
    ordenConHistorial(['estatus' => 'Completado', 'tecnico' => 'x', 'fecha' => haceDias(20)], [
        ['CREATE', null, 'Abierto', haceDias(20) . ' 08:00:00', null],
        ['UPDATE', 'Abierto', 'Completado', haceDias(2) . ' 08:00:00', null],
    ]);
    $r = llamar($empS, $adminS, 'Administrador', 'GET', 'backend/inicio.php', ['get' => ['accion' => 'panel']]);
    $aviso = array_values(array_filter($r['json']['avisos'] ?? [], fn($a) => ($a['filtro'] ?? '') === 'por_cerrar'));
    verdadero(count($aviso) === 1, 'no hay aviso de completadas sin cerrar (' . mb_substr($r['texto'], 0, 120) . ')');
    verdadero(str_starts_with($aviso[0]['titulo'] ?? '', '1 '), 'el aviso debe contar solo la completada hace más de 5 días: ' . ($aviso[0]['titulo'] ?? ''));
});

grupo('Estadísticas con los 8 estatus (H-02)');

prueba('H-02 · D-1: las estadísticas cuentan En proceso y Cancelado, y la eficiencia no castiga las canceladas', function () use ($empS, $adminS) {
    [$emp2, $adm2] = empresaTemporal('SLA Estadisticas');
    como($emp2, $adm2, 'Administrador');
    foreach (['Completado', 'Completado', 'En proceso', 'Cancelado', 'Cancelado'] as $e) ordenS(['estatus' => $e, 'tecnico' => 'Ana', 'motivo_estatus' => $e === 'Cancelado' ? 'x' : null]);
    $r = llamar($emp2, $adm2, 'Administrador', 'GET', 'backend/estadisticas.php', ['get' => ['action' => 'estadisticas_generales']]);
    $d = $r['json']['data'] ?? [];
    igual(1, (int)($d['incidencias_en_proceso'] ?? -1), 'en proceso:');
    igual(2, (int)($d['incidencias_canceladas'] ?? -1), 'canceladas:');
    igual(66.7, (float)($d['eficiencia_total'] ?? -1), 'eficiencia (2 resueltas de 3 no canceladas):');
    global $conn;
    $t = calcularEstadisticasTecnicos($conn, '');
    igual(3, $t['Ana']['asignadas'] ?? -1, 'asignadas de Ana (sin canceladas):');
    igual(1, $t['Ana']['pendientes'] ?? -1, 'pendientes de Ana:');
    como($empS, $adminS, 'Administrador');
});

grupo('Zona horaria (ZH-1, D-29)');

prueba('S-H02 · ZH-1: no se puede guardar la zona horaria vacía', function () use ($empS) {
    global $pdo;
    lanza(fn() => guardarMarcaEmpresa($pdo, $empS, ['zona_horaria' => '']), InvalidArgumentException::class);
});

prueba('S-H02 · ZH-1: una empresa sin zona no hereda la zona de la empresa anterior', function () use ($empS, $adminS) {
    global $pdo;
    [$otra] = empresaTemporal('SLA Tijuana');
    $pdo->prepare("UPDATE empresas SET marca = JSON_SET(marca, '$.zona_horaria', 'America/Tijuana') WHERE id = ?")->execute([$otra]);
    $pdo->prepare("UPDATE empresas SET marca = JSON_REMOVE(marca, '$.zona_horaria') WHERE id = ?")->execute([$empS]);
    try {
        establecerEmpresa($otra);
        igual('America/Tijuana', date_default_timezone_get(), 'zona con la empresa de Tijuana:');
        establecerEmpresa($empS);
        igual(env('APP_TIMEZONE', 'America/Mexico_City'), date_default_timezone_get(), 'zona con la empresa sin zona:');
    } finally {
        $pdo->prepare("UPDATE empresas SET marca = JSON_SET(marca, '$.zona_horaria', ?) WHERE id = ?")->execute([env('APP_TIMEZONE', 'America/Mexico_City'), $empS]);
        como($empS, $adminS, 'Administrador');
    }
});

prueba('D-29: el registro guarda la zona horaria elegida; una inválida o vacía usa la de la instancia', function () {
    igual('America/Tijuana', zonaHorariaRegistro('America/Tijuana'), 'zona válida:');
    igual(env('APP_TIMEZONE', 'America/Mexico_City'), zonaHorariaRegistro('Marte/Base'), 'zona inválida:');
    igual(env('APP_TIMEZONE', 'America/Mexico_City'), zonaHorariaRegistro(''), 'zona vacía:');
});
