<?php
// Auditoría integral 2026-11-25, bloque 8: huecos de prueba que midió QA.
// RP-18/RP-19 estados de la empresa y sus bordes, RP-2 modo soporte, RP-3 plataforma en satélite (D-50).
require_once __DIR__ . '/../config/solid_link.php';

$superE = (int)$pdo->query("SELECT id FROM usuarios WHERE empresa_id IS NULL AND rol = 'Superadmin' ORDER BY id LIMIT 1")->fetchColumn();

function situacionCon(int $emp, array $campos): array {
    global $pdo;
    $sets = implode(', ', array_map(fn($k) => "$k = ?", array_keys($campos)));
    $pdo->prepare("UPDATE empresas SET $sets WHERE id = ?")->execute([...array_values($campos), $emp]);
    return situacionEmpresa(empresaPorId($pdo, $emp));
}
$hace = fn(int $dias) => date('Y-m-d', strtotime("-$dias days"));

grupo('Estados de la empresa, modo soporte y plataforma (bloque 8)');

prueba('RP-18: bordes de gracia, solo lectura y suspensión por atraso', function () use ($hace) {
    [$emp] = empresaTemporal('Bordes de estado');
    $g = diasConfig('dias_gracia');
    $l = diasConfig('dias_solo_lectura');
    igual('total', situacionCon($emp, ['estado' => 'activa', 'pagado_hasta' => date('Y-m-d'), 'verificacion_hasta' => null])['acceso'], 'vence hoy:');
    igual('gracia', situacionCon($emp, ['pagado_hasta' => $hace($g)])['estado'], "atraso $g (último de gracia):");
    igual('solo_lectura', situacionCon($emp, ['pagado_hasta' => $hace($g + 1)])['estado'], 'atraso ' . ($g + 1) . ':');
    igual('solo_lectura', situacionCon($emp, ['pagado_hasta' => $hace($l)])['estado'], "atraso $l (último de solo lectura):");
    igual('bloqueado', situacionCon($emp, ['pagado_hasta' => $hace($l + 1)])['acceso'], 'atraso ' . ($l + 1) . ':');
});

prueba('RP-19: la prueba que termina hoy sigue abierta; la de ayer se bloquea (sin satélite)', function () use ($hace) {
    [$emp] = empresaTemporal('Prueba bordes');
    $s = situacionCon($emp, ['estado' => 'prueba', 'prueba_hasta' => date('Y-m-d'), 'verificacion_hasta' => null]);
    igual(['total', 0], [$s['acceso'], $s['dias']], 'hoy:');
    igual('bloqueado', situacionCon($emp, ['prueba_hasta' => $hace(1)])['acceso'], 'ayer:');
    igual('total', situacionCon($emp, ['verificacion_hasta' => date('Y-m-d', strtotime('+3 days'))])['acceso'], 'con pago en verificación:');
});

prueba('RP-18: en solo lectura un POST se rechaza y cambiar la contraseña sí se permite; suspendida bloquea todo', function () use ($hace) {
    [$emp, $admin] = empresaTemporal('Solo lectura endpoints');
    situacionCon($emp, ['estado' => 'activa', 'pagado_hasta' => $hace(diasConfig('dias_gracia') + 2), 'verificacion_hasta' => null]);
    $r = llamar($emp, $admin, 'Administrador', 'POST', 'backend/finanzas.php', ['get' => ['accion' => 'crear_cuenta'], 'json' => []]);
    igual(403, $r['http'], 'POST en solo lectura:');
    verdadero(!empty($r['json']['solo_lectura']), 'marca de solo lectura: ' . substr($r['texto'], 0, 80));
    igual(200, llamar($emp, $admin, 'Administrador', 'GET', 'backend/inicio.php')['http'], 'GET en solo lectura:');
    $r = llamar($emp, $admin, 'Administrador', 'POST', 'backend/cambiar_password.php', ['json' => ['password_actual' => 'x', 'password_nueva' => 'y']]);
    verdadero($r['http'] !== 403, 'cambiar la contraseña bloqueado en solo lectura (HTTP ' . $r['http'] . ')');
    situacionCon($emp, ['pagado_hasta' => $hace(diasConfig('dias_solo_lectura') + 1)]);
    igual(403, llamar($emp, $admin, 'Administrador', 'GET', 'backend/inicio.php')['http'], 'GET suspendida:');
});

prueba('RP-2: el soporte de plataforma entra a una cuenta suspendida y no cambia contraseñas', function () use ($superE, $hace) {
    if (!$superE) { verdadero(true, 'sin superadministrador local'); return; }
    [$emp] = empresaTemporal('Soporte suspendida');
    situacionCon($emp, ['estado' => 'suspendida']);
    $sesion = ['sesion' => ['soporte' => ['user_id' => $superE, 'nombre' => 'Super', 'usuario' => 'super', 'rol' => 'Superadmin', 'desde' => date('Y-m-d H:i:s')]]];
    igual(200, llamar($emp, $superE, ROL_ACCESO_TOTAL, 'GET', 'backend/inicio.php', $sesion)['http'], 'inicio en soporte:');
    $r = llamar($emp, $superE, ROL_ACCESO_TOTAL, 'POST', 'backend/cambiar_password.php', $sesion + ['json' => ['password_actual' => 'x', 'password_nueva' => 'yyyyyyyy']]);
    igual(403, $r['http'], 'cambiar contraseña en soporte:');
});

prueba('RP-3 · D-50: en satélite, el panel de plataforma no da altas, pagos ni cambios de estado (sí notas y soporte)', function () use ($superE) {
    if (!$superE) { verdadero(true, 'sin superadministrador local'); return; }
    putenv('SOLID_LINK_TOKEN=token-solid-de-prueba-0123456789');
    try {
        foreach (['crear', 'pago', 'estado', 'extender_prueba', 'eliminar', 'guardar_plan'] as $accion) {
            $r = llamar(0, $superE, 'Superadmin', 'POST', 'plataforma/api.php', ['get' => ['accion' => $accion], 'json' => []]);
            igual(409, $r['http'], "$accion en satélite:");
        }
        $r = llamar(0, $superE, 'Superadmin', 'GET', 'plataforma/api.php', ['get' => ['accion' => 'resumen']]);
        igual(200, $r['http'], 'resumen en satélite: ' . substr($r['texto'], 0, 80));
    } finally {
        putenv('SOLID_LINK_TOKEN');
    }
});

prueba('D-20 · RP-2: en modo soporte no se abre ni se guarda el recorrido guiado', function () use ($superE) {
    if (!$superE) { verdadero(true, 'sin superadministrador local'); return; }
    [$emp] = empresaTemporal('Soporte recorrido');
    $sesion = ['sesion' => ['soporte' => ['user_id' => $superE, 'nombre' => 'Super', 'usuario' => 'super', 'rol' => 'Superadmin', 'desde' => date('Y-m-d H:i:s')]]];
    $r = llamar($emp, $superE, ROL_ACCESO_TOTAL, 'GET', 'backend/asistente.php', $sesion);
    igual('terminado', $r['json']['estado'] ?? null, 'estado en soporte: ' . substr($r['texto'], 0, 80));
});
