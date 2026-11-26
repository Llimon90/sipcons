<?php
// Estatus de la orden de servicio: lista cerrada, motivos, reapertura, D-5 y una
// sola definición de "activa" (sección 0.1, D-1 a D-6, D-37).
// Hallazgos H-01 a H-05, H-07 y H-08 (auditorias/2026-11-10-ciclo-vida.md).
require_once __DIR__ . '/../backend/lib/incidencias_helpers.php';
require_once __DIR__ . '/../backend/lib/cotizaciones_helpers.php';

[$empE, $adminE] = empresaTemporal('Estatus');
$sxE = bin2hex(random_bytes(2));
$nomE = "Tecnico Estatus $sxE";
$tecE = usuarioTemporal($empE, 'Técnico', ['nombre' => $nomE]);
como($empE, $adminE, 'Administrador');
$pdo->exec("INSERT INTO clientes (nombre, rfc, direccion, telefono, contactos, email) VALUES ('CLIENTE ESTATUS', '', '', '', '', '')");

function altaE(int $emp, int $u, string $rol, array $c = []): array {
    return llamar($emp, $u, $rol, 'POST', 'backend/server.php', ['post' => $c + [
        'reporte_cliente' => '', 'cliente' => 'CLIENTE ESTATUS', 'contacto' => 'Quien reporta', 'sucursal' => '',
        'fecha' => date('Y-m-d'), 'tecnico' => '', 'status' => 'Abierto', 'falla' => 'Mantenimiento', 'notas' => '']]);
}
function ordenE(array $c = []): int {
    global $pdo;
    $f = $c + ['numero_incidente' => 'E-' . bin2hex(random_bytes(3)), 'cliente' => 'CLIENTE ESTATUS', 'contacto' => 'x',
        'fecha' => date('Y-m-d'), 'categoria' => 'General', 'estatus' => 'Abierto', 'falla' => 'Servicio', 'tecnico' => '', 'notas' => ''];
    $cols = implode(', ', array_map(fn($k) => "`$k`", array_keys($f)));
    $pdo->prepare("INSERT INTO incidencias ($cols) VALUES (" . implode(',', array_fill(0, count($f), '?')) . ')')->execute(array_values($f));
    return (int)$pdo->lastInsertId();
}
function filaE(int $id): array {
    global $pdo;
    return $pdo->query("SELECT * FROM incidencias WHERE id = $id")->fetch(PDO::FETCH_ASSOC) ?: [];
}
function editarE(int $emp, int $u, string $rol, int $id, array $cambios): array {
    $f = filaE($id);
    return llamar($emp, $u, $rol, 'POST', 'backend/actualiza.php', ['post' => $cambios + [
        'id' => $id, 'version' => versionIncidencia($f), 'reporte_cliente' => '', 'cliente' => $f['cliente'], 'contacto' => $f['contacto'],
        'sucursal' => '', 'categoria' => $f['categoria'], 'fecha' => substr((string)$f['fecha'], 0, 10), 'tecnico' => (string)$f['tecnico'],
        'estatus' => $f['estatus'], 'falla' => $f['falla'], 'accion' => '', 'notas' => '', 'motivo_estatus' => (string)($f['motivo_estatus'] ?? '')]]);
}
function rechazadoE(array $r, string $msg): void {
    verdadero(empty($r['json']['success']) && $r['http'] >= 400 && $r['http'] < 500, "$msg (HTTP {$r['http']}: " . mb_substr($r['texto'], 0, 140) . ')');
}
function okE(array $r, string $msg): void {
    igual(200, $r['http'], "$msg (" . mb_substr($r['texto'], 0, 140) . '):');
}

grupo('Lista cerrada de estatus (D-1)');

prueba('H-01 · D-1: el alta rechaza un estatus fuera de la lista o vacío', function () use ($empE, $adminE) {
    rechazadoE(altaE($empE, $adminE, 'Administrador', ['status' => 'En revisión']), 'se aceptó "En revisión"');
    rechazadoE(altaE($empE, $adminE, 'Administrador', ['status' => '']), 'se aceptó un estatus vacío');
    rechazadoE(altaE($empE, $adminE, 'Administrador', ['status' => 'Resuelto']), 'se aceptó "Resuelto"');
});

prueba('H-01 · D-1: la edición rechaza un estatus fuera de la lista', function () use ($empE, $adminE) {
    $id = ordenE();
    rechazadoE(editarE($empE, $adminE, 'Administrador', $id, ['estatus' => 'Programado']), 'se aceptó "Programado"');
    igual('Abierto', filaE($id)['estatus'], 'el estatus no debió cambiar:');
});

prueba('D-1: el estatus se guarda con su forma oficial aunque llegue en minúsculas', function () use ($empE, $adminE) {
    $r = altaE($empE, $adminE, 'Administrador', ['status' => 'completado']);
    okE($r, 'alta');
    igual('Completado', filaE((int)$r['json']['id'])['estatus'], 'estatus guardado:');
});

prueba('0.1: En proceso es un estatus válido (con técnico)', function () use ($empE, $adminE, $nomE) {
    $r = altaE($empE, $adminE, 'Administrador', ['status' => 'En proceso', 'tecnico' => $nomE]);
    okE($r, 'alta');
    igual('En proceso', filaE((int)$r['json']['id'])['estatus'], 'estatus guardado:');
});

prueba('D-2: se puede dar de alta directo en Completado (captura posterior)', function () use ($empE, $tecE, $nomE) {
    $r = altaE($empE, $tecE, 'Técnico', ['status' => 'Completado', 'tecnico' => $nomE]);
    okE($r, 'alta del Técnico en Completado');
});

grupo('Técnicos y estatus (D-5)');

prueba('H-03 · 0.1: elegir Asignado o En proceso sin técnico se rechaza', function () use ($empE, $adminE) {
    rechazadoE(altaE($empE, $adminE, 'Administrador', ['status' => 'Asignado', 'tecnico' => '']), 'Asignado sin técnico');
    rechazadoE(altaE($empE, $adminE, 'Administrador', ['status' => 'En proceso', 'tecnico' => '']), 'En proceso sin técnico');
    $id = ordenE();
    rechazadoE(editarE($empE, $adminE, 'Administrador', $id, ['estatus' => 'En proceso']), 'pasar a En proceso sin técnico');
});

prueba('H-03 · D-5: una orden Abierta que recibe técnico pasa sola a Asignado', function () use ($empE, $adminE, $nomE) {
    $r = altaE($empE, $adminE, 'Administrador', ['status' => 'Abierto', 'tecnico' => $nomE]);
    okE($r, 'alta');
    igual('Asignado', filaE((int)$r['json']['id'])['estatus'], 'estatus en el alta:');
    $id = ordenE();
    okE(editarE($empE, $adminE, 'Administrador', $id, ['tecnico' => $nomE]), 'edición');
    igual('Asignado', filaE($id)['estatus'], 'estatus en la edición:');
});

prueba('H-03 · D-5: una orden Asignada o En proceso que se queda sin técnicos regresa a Abierto', function () use ($empE, $adminE, $nomE) {
    foreach (['Asignado', 'En proceso'] as $est) {
        $id = ordenE(['estatus' => $est, 'tecnico' => $nomE]);
        okE(editarE($empE, $adminE, 'Administrador', $id, ['tecnico' => '']), "edición desde $est");
        igual('Abierto', filaE($id)['estatus'], "estatus (antes $est):");
    }
});

grupo('Motivos de Pendiente y Cancelado (0.1, D-16)');

prueba('H-04 · 0.1: Pendiente exige motivo, y el motivo se guarda', function () use ($empE, $adminE, $nomE) {
    $id = ordenE(['estatus' => 'Asignado', 'tecnico' => $nomE]);
    rechazadoE(editarE($empE, $adminE, 'Administrador', $id, ['estatus' => 'Pendiente', 'motivo_estatus' => '  ']), 'Pendiente sin motivo');
    okE(editarE($empE, $adminE, 'Administrador', $id, ['estatus' => 'Pendiente', 'motivo_estatus' => 'Refacción: compresor']), 'Pendiente con motivo');
    igual('Refacción: compresor', filaE($id)['motivo_estatus'], 'motivo guardado:');
    rechazadoE(altaE($empE, $adminE, 'Administrador', ['status' => 'Pendiente', 'tecnico' => $nomE]), 'alta en Pendiente sin motivo');
});

prueba('D-37: al salir de Pendiente el motivo se borra', function () use ($empE, $adminE, $nomE) {
    $id = ordenE(['estatus' => 'Pendiente', 'tecnico' => $nomE, 'motivo_estatus' => 'Cliente o acceso']);
    okE(editarE($empE, $adminE, 'Administrador', $id, ['estatus' => 'En proceso']), 'edición');
    igual(null, filaE($id)['motivo_estatus'], 'motivo después de salir de Pendiente:');
});

prueba('H-04 · 0.1: Cancelado exige motivo y permiso de cierre', function () use ($empE, $adminE, $tecE, $nomE) {
    $id = ordenE(['estatus' => 'Asignado', 'tecnico' => $nomE]);
    rechazadoE(editarE($empE, $adminE, 'Administrador', $id, ['estatus' => 'Cancelado', 'motivo_estatus' => '']), 'Cancelado sin motivo');
    rechazadoE(editarE($empE, $tecE, 'Técnico', $id, ['estatus' => 'Cancelado', 'motivo_estatus' => 'Duplicada']), 'el Técnico canceló');
    okE(editarE($empE, $adminE, 'Administrador', $id, ['estatus' => 'Cancelado', 'motivo_estatus' => 'Duplicada']), 'cancelación del Administrador');
    igual('Cancelado', filaE($id)['estatus'], 'estatus:');
});

grupo('Reapertura (D-3)');

prueba('H-05 · D-3: el Técnico no puede reabrir una orden cerrada', function () use ($empE, $tecE, $nomE) {
    $id = ordenE(['estatus' => 'Cerrado sin factura', 'tecnico' => $nomE]);
    rechazadoE(editarE($empE, $tecE, 'Técnico', $id, ['estatus' => 'Asignado', 'motivo_reapertura' => 'Falló otra vez']), 'el Técnico reabrió');
    igual('Cerrado sin factura', filaE($id)['estatus'], 'estatus:');
});

prueba('D-3: el Técnico sí puede editar notas de una orden cerrada sin reabrirla (RP-12)', function () use ($empE, $tecE, $nomE) {
    $id = ordenE(['estatus' => 'Cerrado con factura', 'tecnico' => $nomE]);
    okE(editarE($empE, $tecE, 'Técnico', $id, ['notas' => 'nota posterior']), 'edición sin cambio de estatus');
});

prueba('H-05 · D-3: reabrir exige motivo, y el motivo queda en el historial', function () use ($empE, $adminE, $nomE) {
    global $pdo;
    foreach (['Cerrado con factura', 'Cancelado'] as $est) {
        $id = ordenE(['estatus' => $est, 'tecnico' => $nomE, 'motivo_estatus' => $est === 'Cancelado' ? 'Duplicada' : null]);
        rechazadoE(editarE($empE, $adminE, 'Administrador', $id, ['estatus' => 'Asignado', 'motivo_estatus' => '']), "reabrió $est sin motivo");
        okE(editarE($empE, $adminE, 'Administrador', $id, ['estatus' => 'Asignado', 'motivo_reapertura' => 'El equipo volvió a fallar']), "reapertura de $est");
        $datos = (string)$pdo->query("SELECT datos_nuevos FROM auditoria WHERE tabla = 'incidencias' AND registro_id = '$id' ORDER BY id DESC LIMIT 1")->fetchColumn();
        verdadero(str_contains($datos, 'El equipo volvió a fallar') || str_contains($datos, 'El equipo volvió a fallar'), "el historial no guarda el motivo de reapertura ($est)");
    }
});

prueba('D-3: pasar de Cerrado a Cancelado no es reabrir (no pide motivo de reapertura)', function () use ($empE, $adminE, $nomE) {
    $id = ordenE(['estatus' => 'Cerrado sin factura', 'tecnico' => $nomE]);
    okE(editarE($empE, $adminE, 'Administrador', $id, ['estatus' => 'Cancelado', 'motivo_estatus' => 'Se cerró por error']), 'cambio a Cancelado');
});

grupo('Motivo del servicio (D-6)');

prueba('H-08 · D-6: el motivo del servicio es obligatorio en el alta', function () use ($empE, $adminE) {
    rechazadoE(altaE($empE, $adminE, 'Administrador', ['falla' => '   ']), 'alta sin motivo del servicio');
});

grupo('Una sola definición de "activa" (D-4)');

prueba('H-07 · D-4: la lista principal incluye En proceso y Completado y excluye Cancelado', function () use ($empE, $adminE, $nomE) {
    $enProceso = ordenE(['estatus' => 'En proceso', 'tecnico' => $nomE]);
    $completado = ordenE(['estatus' => 'Completado', 'tecnico' => $nomE]);
    $cancelado = ordenE(['estatus' => 'Cancelado', 'motivo_estatus' => 'Duplicada']);
    $r = llamar($empE, $adminE, 'Administrador', 'GET', 'backend/server.php');
    $ids = array_map('intval', array_column(isset($r['json'][0]) ? $r['json'] : [], 'id'));
    verdadero(in_array($enProceso, $ids, true), 'falta la orden En proceso');
    verdadero(in_array($completado, $ids, true), 'falta la orden Completado');
    verdadero(!in_array($cancelado, $ids, true), 'aparece la orden Cancelada');
});

prueba('H-07 · D-4: la búsqueda "solo activas" incluye En proceso y excluye Cancelado', function () use ($empE, $adminE, $nomE) {
    $cliente = 'BUSCA ' . bin2hex(random_bytes(3));
    $enProceso = ordenE(['estatus' => 'En proceso', 'tecnico' => $nomE, 'cliente' => $cliente]);
    $cancelado = ordenE(['estatus' => 'Cancelado', 'cliente' => $cliente, 'motivo_estatus' => 'x']);
    $r = llamar($empE, $adminE, 'Administrador', 'GET', 'backend/buscar_reportes.php', ['get' => ['cliente' => $cliente, 'solo_activas' => '1']]);
    $filas = $r['json']['data'] ?? $r['json']['resultados'] ?? (isset($r['json'][0]) ? $r['json'] : []);
    $ids = array_map('intval', array_column($filas, 'id'));
    verdadero(in_array($enProceso, $ids, true), 'falta la orden En proceso (' . mb_substr($r['texto'], 0, 120) . ')');
    verdadero(!in_array($cancelado, $ids, true), 'aparece la orden Cancelada');
});

prueba('H-07 · D-4: el contador de Inicio no cuenta las canceladas', function () use ($empE, $adminE) {
    $antes = llamar($empE, $adminE, 'Administrador', 'GET', 'backend/inicio.php', ['get' => ['accion' => 'contadores']])['json']['incidencias'];
    ordenE(['estatus' => 'Cancelado', 'motivo_estatus' => 'x']);
    $despues = llamar($empE, $adminE, 'Administrador', 'GET', 'backend/inicio.php', ['get' => ['accion' => 'contadores']])['json']['incidencias'];
    igual($antes, $despues, 'activas antes y después de crear una cancelada:');
});

prueba('D-4 · D-33: las alertas de atraso no muestran canceladas ni completadas', function () use ($empE, $adminE, $nomE) {
    $vieja = date('Y-m-d', strtotime('-90 days'));
    $cancelada = ordenE(['estatus' => 'Cancelado', 'fecha' => $vieja, 'motivo_estatus' => 'x']);
    $completada = ordenE(['estatus' => 'Completado', 'fecha' => $vieja, 'tecnico' => $nomE]);
    $activa = ordenE(['estatus' => 'En proceso', 'fecha' => $vieja, 'tecnico' => $nomE]);
    $r = llamar($empE, $adminE, 'Administrador', 'GET', 'backend/alertas_incidentes.php');
    $ids = array_map('intval', array_column($r['json']['alertas'] ?? [], 'id'));
    verdadero(in_array($activa, $ids, true), 'falta la orden En proceso atrasada');
    verdadero(!in_array($cancelada, $ids, true), 'aparece la cancelada');
    verdadero(!in_array($completada, $ids, true), 'aparece la completada');
});

grupo('Cotización aprobada (CV-9, D-5)');

prueba('H-03 · D-5: al reactivar desde una cotización, una orden Pendiente sin técnico pasa a Abierto', function () use ($empE, $adminE) {
    global $pdo;
    como($empE, $adminE, 'Administrador');
    $id = ordenE(['estatus' => 'Pendiente', 'tecnico' => '', 'motivo_estatus' => 'Aprobación de cotización']);
    $cot = cotCrearDesdeIncidencia($pdo, $id);
    cotReactivarIncidencia($pdo, (int)$cot['id']);
    igual('Abierto', filaE($id)['estatus'], 'estatus:');
    igual(null, filaE($id)['motivo_estatus'], 'motivo:');
});

prueba('CV-9: al reactivar desde una cotización, una orden Pendiente con técnico pasa a Asignado', function () use ($empE, $adminE, $nomE) {
    global $pdo;
    como($empE, $adminE, 'Administrador');
    $id = ordenE(['estatus' => 'Pendiente', 'tecnico' => $nomE, 'motivo_estatus' => 'Aprobación de cotización']);
    $cot = cotCrearDesdeIncidencia($pdo, $id);
    cotReactivarIncidencia($pdo, (int)$cot['id']);
    igual('Asignado', filaE($id)['estatus'], 'estatus:');
});

grupo('Correcciones de la auditoría del paso 4');

prueba('H-03 · D-9: un Técnico con el módulo de cotizaciones no saca de Pendiente una orden ajena', function () use ($empE, $adminE, $tecE) {
    global $pdo;
    como($empE, $adminE, 'Administrador');
    $ajena = ordenE(['estatus' => 'Pendiente', 'tecnico' => 'Otro Tecnico Ajeno', 'motivo_estatus' => 'Refacción']);
    $cot = cotCrearDesdeIncidencia($pdo, $ajena);
    $pdo->prepare("UPDATE permisos_rol SET permitido = 1 WHERE rol = 'Técnico' AND modulo = 'cotizaciones'")->execute();
    $r = llamar($empE, $tecE, 'Técnico', 'POST', 'backend/cotizaciones.php', ['get' => ['accion' => 'reactivar_incidencia'], 'json' => ['id' => (int)$cot['id']]]);
    $pdo->prepare("UPDATE permisos_rol SET permitido = 0 WHERE rol = 'Técnico' AND modulo = 'cotizaciones'")->execute();
    como($empE, $adminE, 'Administrador');
    verdadero(empty($r['json']['exito']), 'reactivó una orden ajena (HTTP ' . $r['http'] . ')');
    igual('Pendiente', filaE($ajena)['estatus'], 'estatus de la orden ajena:');
});

prueba('H-07 · D-6: el motivo del servicio también es obligatorio al editar', function () use ($empE, $adminE) {
    $id = ordenE();
    rechazadoE(editarE($empE, $adminE, 'Administrador', $id, ['falla' => '  ']), 'edición sin motivo del servicio');
});

prueba('H-07 · RP-12 · D-3: sin permiso de cierre no se cierra, cancela ni reabre', function () use ($empE, $adminE, $nomE) {
    $sup = usuarioTemporal($empE, 'Supervisor', ['nombre' => 'Supervisor Sin Cierre ' . bin2hex(random_bytes(2)), 'puede_cerrar' => 0]);
    $abierta = ordenE(['estatus' => 'Completado', 'tecnico' => $nomE]);
    rechazadoE(editarE($empE, $sup, 'Supervisor', $abierta, ['estatus' => 'Cerrado con factura']), 'cerró sin permiso');
    rechazadoE(editarE($empE, $sup, 'Supervisor', $abierta, ['estatus' => 'Cancelado', 'motivo_estatus' => 'Duplicada']), 'canceló sin permiso');
    $cerrada = ordenE(['estatus' => 'Cerrado sin factura', 'tecnico' => $nomE]);
    rechazadoE(editarE($empE, $sup, 'Supervisor', $cerrada, ['estatus' => 'Asignado', 'motivo_reapertura' => 'x']), 'reabrió sin permiso');
    como($empE, $adminE, 'Administrador');
    igual('Completado', filaE($abierta)['estatus'], 'estatus de la completada:');
    igual('Cerrado sin factura', filaE($cerrada)['estatus'], 'estatus de la cerrada:');
});

prueba('H-06 · D-4: Mi empresa cuenta las canceladas en el desglose de órdenes', function () use ($empE, $adminE) {
    ordenE(['estatus' => 'Cancelado', 'motivo_estatus' => 'x']);
    $r = llamar($empE, $adminE, 'Administrador', 'GET', 'backend/mi_empresa.php', ['get' => ['accion' => 'uso']]);
    $t = $r['json']['totales'] ?? [];
    verdadero(isset($t['canceladas']) && $t['canceladas'] >= 1, 'el desglose no trae canceladas (' . mb_substr($r['texto'], 0, 120) . ')');
    igual((int)($t['incidencias'] ?? -1), (int)($t['abiertas'] ?? 0) + (int)($t['completadas'] ?? 0) + (int)($t['cerradas'] ?? 0) + (int)($t['canceladas'] ?? 0), 'el desglose suma el total:');
});
