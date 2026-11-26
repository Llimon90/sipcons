<?php
// Correcciones menores del paso 8 (auditorias/2026-11-10-*): permisos de manuales
// (D-28), banderas de usuario (P-H05), panel del técnico (P-H08, S-H11, N-H12),
// estadísticas muertas (S-H13), avisos de Inicio (N-H05, N-H09), orden de la
// lista (H-19) y corrección de cobros por el Administrativo (D-22).
require_once __DIR__ . '/../backend/lib/finanzas_helpers.php';

[$empM, $adminM] = empresaTemporal('Menores');
$sx = bin2hex(random_bytes(2));
$tecM = usuarioTemporal($empM, 'Técnico', ['nombre' => "Tecnico Menores $sx"]);
$supM = usuarioTemporal($empM, 'Supervisor', ['nombre' => "Supervisor Menores $sx", 'puede_cerrar' => 0]);
$admvoM = usuarioTemporal($empM, 'Administrativo', ['nombre' => "Administrativo Menores $sx"]);
como($empM, $adminM, 'Administrador');

function permisoM(string $rol, string $modulo, int $v): void {
    global $pdo;
    $pdo->prepare('UPDATE permisos_rol SET permitido = ? WHERE rol = ? AND modulo = ?')->execute([$v, $rol, $modulo]);
}
function ordenM(array $c): int {
    global $pdo;
    $f = $c + ['numero_incidente' => 'M-' . bin2hex(random_bytes(3)), 'cliente' => 'C', 'contacto' => 'x', 'fecha' => date('Y-m-d'),
        'categoria' => 'General', 'estatus' => 'Abierto', 'falla' => 'x', 'tecnico' => ''];
    $cols = implode(', ', array_map(fn($k) => "`$k`", array_keys($f)));
    $pdo->prepare("INSERT INTO incidencias ($cols) VALUES (" . implode(',', array_fill(0, count($f), '?')) . ')')->execute(array_values($f));
    return (int)$pdo->lastInsertId();
}

grupo('Manuales y catálogo de marcas (D-28)');

prueba('D-28 · P-H10: el Técnico no elimina marcas; el Supervisor sí', function () use ($empM, $tecM, $supM, $adminM) {
    global $pdo;
    $marca = function () use ($pdo) {
        $pdo->prepare('INSERT INTO marcas (nombre) VALUES (?)')->execute(['MARCA ' . bin2hex(random_bytes(3))]);
        return (int)$pdo->lastInsertId();
    };
    $sigue = fn($id) => (int)$pdo->query("SELECT COUNT(*) FROM marcas WHERE id = $id AND (activo = 1 OR activo IS NULL) AND fecha_eliminacion IS NULL")->fetchColumn();
    $m1 = $marca();
    $r = llamar($empM, $tecM, 'Técnico', 'POST', 'backend/soporte_backend.php', ['get' => ['action' => 'delete_marca'], 'json' => ['marca_id' => $m1]]);
    como($empM, $adminM, 'Administrador');
    igual(403, $r['http'], 'Técnico:');
    igual(1, $sigue($m1), 'la marca sigue:');
    $m2 = $marca();
    llamar($empM, $supM, 'Supervisor', 'POST', 'backend/soporte_backend.php', ['get' => ['action' => 'delete_marca'], 'json' => ['marca_id' => $m2]]);
    como($empM, $adminM, 'Administrador');
    igual(0, $sigue($m2), 'el Supervisor sí la elimina:');
});

prueba('D-28: el Técnico sigue consultando manuales y marcas', function () use ($empM, $tecM, $adminM) {
    $r = llamar($empM, $tecM, 'Técnico', 'GET', 'backend/soporte_backend.php', ['get' => ['action' => 'get_marcas']]);
    como($empM, $adminM, 'Administrador');
    igual(200, $r['http'], 'consulta:');
});

grupo('Banderas de usuario (P-H05)');

prueba('P-H05: quien no es Administrador no se otorga "puede cerrar" ni "autorizar descuentos"', function () use ($empM, $supM, $adminM, $sx) {
    global $pdo;
    permisoM('Supervisor', 'usuarios', 1);
    try {
        $correo = (string)$pdo->query("SELECT correo FROM usuarios WHERE id = $supM")->fetchColumn();
        llamar($empM, $supM, 'Supervisor', 'POST', 'backend/actualiza-usuario.php', ['json' => [
            'id' => $supM, 'nombre' => "Supervisor Menores $sx", 'correo' => $correo, 'telefono' => '', 'rol' => 'Supervisor',
            'puede_cerrar' => 1, 'puede_autorizar_descuentos' => 1]]);
    } finally {
        como($empM, $adminM, 'Administrador');
        permisoM('Supervisor', 'usuarios', 0);
    }
    $f = $pdo->query("SELECT puede_cerrar, puede_autorizar_descuentos FROM usuarios WHERE id = $supM")->fetch(PDO::FETCH_ASSOC);
    igual(0, (int)$f['puede_cerrar'], 'puede_cerrar:');
    igual(0, (int)$f['puede_autorizar_descuentos'], 'puede_autorizar_descuentos:');
});

prueba('P-H05: editar un usuario sin mandar sus banderas no las cambia', function () use ($empM, $adminM) {
    global $pdo;
    $u = usuarioTemporal($empM, 'Administrativo', ['nombre' => 'Banderas ' . bin2hex(random_bytes(2)), 'puede_cerrar' => 0, 'puede_autorizar_descuentos' => 1]);
    $f = $pdo->query("SELECT nombre, correo FROM usuarios WHERE id = $u")->fetch(PDO::FETCH_ASSOC);
    llamar($empM, $adminM, 'Administrador', 'POST', 'backend/actualiza-usuario.php', ['json' => [
        'id' => $u, 'nombre' => $f['nombre'], 'correo' => $f['correo'], 'telefono' => '555', 'rol' => 'Administrativo']]);
    como($empM, $adminM, 'Administrador');
    $g = $pdo->query("SELECT puede_cerrar, puede_autorizar_descuentos, telefono FROM usuarios WHERE id = $u")->fetch(PDO::FETCH_ASSOC);
    igual('555', $g['telefono'], 'la edición se aplicó:');
    igual(0, (int)$g['puede_cerrar'], 'puede_cerrar:');
    igual(1, (int)$g['puede_autorizar_descuentos'], 'puede_autorizar_descuentos:');
});

prueba('P-H05: el Administrador sí cambia las banderas', function () use ($empM, $adminM) {
    global $pdo;
    $u = usuarioTemporal($empM, 'Administrativo', ['nombre' => 'Banderas Adm ' . bin2hex(random_bytes(2)), 'puede_cerrar' => 1]);
    $f = $pdo->query("SELECT nombre, correo FROM usuarios WHERE id = $u")->fetch(PDO::FETCH_ASSOC);
    llamar($empM, $adminM, 'Administrador', 'POST', 'backend/actualiza-usuario.php', ['json' => [
        'id' => $u, 'nombre' => $f['nombre'], 'correo' => $f['correo'], 'telefono' => '', 'rol' => 'Administrativo', 'puede_cerrar' => 0, 'puede_autorizar_descuentos' => 1]]);
    como($empM, $adminM, 'Administrador');
    $g = $pdo->query("SELECT puede_cerrar, puede_autorizar_descuentos FROM usuarios WHERE id = $u")->fetch(PDO::FETCH_ASSOC);
    igual(0, (int)$g['puede_cerrar'], 'puede_cerrar:');
    igual(1, (int)$g['puede_autorizar_descuentos'], 'puede_autorizar_descuentos:');
});

grupo('Panel del técnico y estadísticas (P-H08, S-H11, N-H12, S-H13)');

prueba('P-H08: sin el módulo de incidencias no se consulta el panel del técnico', function () use ($empM, $tecM, $adminM) {
    permisoM('Técnico', 'incidencias', 0);
    try {
        igual(403, llamar($empM, $tecM, 'Técnico', 'GET', 'backend/estadisticas_tecnico.php')['http'], 'respuesta:');
    } finally {
        como($empM, $adminM, 'Administrador');
        permisoM('Técnico', 'incidencias', 1);
    }
});

prueba('S-H11: los pendientes de "Juan" no se pierden por las órdenes de "Juan Carlos"', function () use ($empM, $adminM) {
    global $pdo;
    $s = bin2hex(random_bytes(2));
    $juan = usuarioTemporal($empM, 'Técnico', ['nombre' => "Juan $s"]);
    $ins = $pdo->prepare("INSERT INTO incidencias (numero_incidente, cliente, contacto, fecha, categoria, estatus, falla, tecnico) VALUES (?, 'C', 'x', '2025-01-01', 'General', 'Asignado', 'x', ?)");
    $pdo->beginTransaction();
    for ($i = 0; $i < 200; $i++) $ins->execute(["JC-$i-$s", "Juan $s Carlos"]);
    $pdo->commit();
    $mia = ordenM(['tecnico' => "Juan $s", 'estatus' => 'Asignado', 'fecha' => '2026-01-01']);
    $r = llamar($empM, $juan, 'Técnico', 'GET', 'backend/estadisticas_tecnico.php');
    como($empM, $adminM, 'Administrador');
    verdadero(in_array($mia, array_map('intval', array_column($r['json']['data']['pendientes'] ?? [], 'id')), true), 'la orden de Juan no aparece en sus pendientes');
});

prueba('N-H12: un rango de fechas inválido en el panel del técnico responde JSON', function () use ($empM, $tecM, $adminM) {
    $r = llamar($empM, $tecM, 'Técnico', 'GET', 'backend/estadisticas_tecnico.php',
        ['get' => ['action' => 'resumen', 'rangoFecha' => 'custom', 'fechaInicio' => 'no-es-fecha', 'fechaFin' => '2026-11-10']]);
    como($empM, $adminM, 'Administrador');
    verdadero(is_array($r['json']) && $r['http'] < 500, "respuesta no válida (HTTP {$r['http']}): " . mb_substr($r['texto'], 0, 100));
});

prueba('S-H13: detalle-usuario.php ya no entrega estadísticas de la empresa', function () use ($empM, $adminM) {
    $r = llamar($empM, $adminM, 'Administrador', 'GET', 'backend/detalle-usuario.php', ['get' => ['action' => 'estadisticas_incidencias']]);
    como($empM, $adminM, 'Administrador');
    igual(404, $r['http'], 'respuesta:');
});

grupo('Inicio y lista principal (N-H05, N-H09, H-19)');

prueba('N-H05: sin permiso de clientes no aparece el aviso del padrón; sin ventas no hay cambios de ventas', function () use ($empM, $supM, $adminM) {
    global $pdo;
    $pdo->prepare("INSERT INTO padron_equipos (cliente, equipo, servicio, frecuencia_servicio, proximo_servicio) VALUES ('C', 'Eq', 1, 6, CURDATE())")->execute();
    $pdo->prepare("INSERT INTO auditoria (usuario_id, accion, tabla, registro_id, registro_folio) VALUES (1, 'UPDATE', 'ventas', '1', 'VT-M')")->execute();
    permisoM('Supervisor', 'clientes', 0); permisoM('Supervisor', 'ventas', 0); permisoM('Supervisor', 'historial', 1);
    try {
        $r = llamar($empM, $supM, 'Supervisor', 'GET', 'backend/inicio.php', ['get' => ['accion' => 'panel']]);
    } finally {
        como($empM, $adminM, 'Administrador');
        permisoM('Supervisor', 'clientes', 1); permisoM('Supervisor', 'ventas', 1); permisoM('Supervisor', 'historial', 0);
    }
    $equipos = array_filter($r['json']['avisos'] ?? [], fn($a) => strpos($a['titulo'] ?? '', 'equipos con mantenimiento') !== false);
    igual(0, count($equipos), 'avisos del padrón:');
    verdadero(!in_array('ventas', array_column($r['json']['cambios'] ?? [], 'tabla'), true), 'aparecen cambios de ventas');
});

prueba('N-H09: un equipo con el recordatorio apagado no cuenta como "por vencer"', function () use ($empM, $adminM) {
    global $pdo;
    $cuenta = function () use ($empM, $adminM) {
        $r = llamar($empM, $adminM, 'Administrador', 'GET', 'backend/inicio.php', ['get' => ['accion' => 'panel']]);
        foreach ($r['json']['avisos'] ?? [] as $a) if (strpos($a['titulo'] ?? '', 'equipos con mantenimiento') !== false) return (int)$a['titulo'];
        return 0;
    };
    $antes = $cuenta();
    $pdo->prepare("INSERT INTO padron_equipos (cliente, equipo, servicio, calibracion, proxima_calibracion) VALUES ('C', 'Apagado', 0, 0, '2025-01-01')")->execute();
    igual($antes, $cuenta(), 'equipos por vencer:');
});

prueba('H-19: la lista muestra primero la orden más reciente aunque su folio sea más largo', function () use ($empM, $adminM) {
    $vieja = ordenM(['numero_incidente' => 'SD-9999']);
    $nueva = ordenM(['numero_incidente' => 'SD-10000']);
    $r = llamar($empM, $adminM, 'Administrador', 'GET', 'backend/server.php');
    $ids = array_map('intval', array_column(isset($r['json'][0]) ? $r['json'] : [], 'id'));
    verdadero(array_search($nueva, $ids, true) < array_search($vieja, $ids, true), 'SD-10000 aparece después de SD-9999');
});

grupo('Corregir cobros (D-22)');

prueba('D-22: el Administrativo puede cancelar un cobro (para corregirlo); el Técnico no', function () use ($empM, $admvoM, $tecM, $adminM) {
    global $pdo;
    $pdo->prepare("INSERT INTO clientes (nombre) VALUES ('CLIENTE COBRO')")->execute();
    $cli = (int)$pdo->lastInsertId();
    $c = finCrearCuenta($pdo, ['cliente_id' => $cli, 'concepto' => 'Servicio', 'subtotal' => 100, 'iva' => 16]);
    $c = finRegistrarCobro($pdo, (int)$c['id'], ['monto' => 50, 'forma' => 'transferencia']);
    $cobro = (int)$pdo->query("SELECT id FROM cobros WHERE cuenta_id = {$c['id']}")->fetchColumn();
    $t = llamar($empM, $tecM, 'Técnico', 'POST', 'backend/finanzas.php', ['get' => ['accion' => 'cancelar_cobro'], 'json' => ['id' => $cobro, 'motivo' => 'x']]);
    verdadero(empty($t['json']['exito']), 'el Técnico canceló un cobro');
    $r = llamar($empM, $admvoM, 'Administrativo', 'POST', 'backend/finanzas.php', ['get' => ['accion' => 'cancelar_cobro'], 'json' => ['id' => $cobro, 'motivo' => 'Monto equivocado']]);
    como($empM, $adminM, 'Administrador');
    verdadero(!empty($r['json']['exito']), 'el Administrativo no pudo cancelar el cobro: ' . mb_substr($r['texto'], 0, 120));
    igual(1, (int)$pdo->query("SELECT cancelado FROM cobros WHERE id = $cobro")->fetchColumn(), 'cobro cancelado:');
    igual(0.0, (float)$pdo->query("SELECT cobrado FROM cuentas_cobrar WHERE id = {$c['id']}")->fetchColumn(), 'saldo recalculado:');
});
