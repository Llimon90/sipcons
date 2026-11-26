<?php
// D-50: Servidesk como satélite de Solid Suite. Solid Admin decide (estado, vence con gracia, plan, límites)
// y Servidesk lo aplica por Solid Link (api/solid.php, config/solid_link.php).
require_once __DIR__ . '/../config/solid_link.php';
require_once __DIR__ . '/../config/contratacion.php';

grupo('Solid Link (D-50)');

function conSatelite(callable $fn) {
    putenv('SOLID_LINK_TOKEN=token-solid-de-prueba-0123456789');
    $_ENV['SOLID_LINK_TOKEN'] = 'token-solid-de-prueba-0123456789';
    try { return $fn(); } finally { putenv('SOLID_LINK_TOKEN'); unset($_ENV['SOLID_LINK_TOKEN']); }
}

prueba('D-50: sin SOLID_LINK_TOKEN la instancia no es satélite y su gracia es la configurada', function () {
    verdadero(!solidSatelite(), 'no satélite');
    igual((int)configPlataforma('dias_gracia'), diasConfig('dias_gracia'));
});

prueba('D-50: el token se compara completo y sin revelar longitud', function () {
    conSatelite(function () {
        verdadero(solidTokenValido('Bearer token-solid-de-prueba-0123456789'), 'válido');
        verdadero(!solidTokenValido('Bearer token-solid-de-prueba-012345678'), 'incompleto');
        verdadero(!solidTokenValido('token-solid-de-prueba-0123456789'), 'sin Bearer');
    });
});

prueba('D-50: alta desde el panel crea empresa, tablas y administrador con su correo, en prueba hasta vence', function () use ($pdo) {
    conSatelite(function () use ($pdo) {
        $correo = 'sat-' . bin2hex(random_bytes(3)) . '@pruebas.test';
        $r = solidCrearCuenta($pdo, ['nombre' => 'Taller Satélite', 'estado' => 'prueba', 'vence' => date('Y-m-d', strtotime('+10 days')),
            'plan' => 'servidesk-basico', 'limites' => ['usuarios' => 3], 'contacto' => ['nombre' => 'Ana', 'correo' => $correo]]);
        $e = solidEmpresaPorSlug($pdo, $r['id']);
        $GLOBALS['__pruebas']['empresas'][] = (int)$e['id'];
        igual($correo, $r['acceso']['usuario']);
        verdadero(strlen($r['acceso']['password']) >= 12, 'contraseña temporal');
        igual('prueba', $e['estado']);
        igual(date('Y-m-d', strtotime('+10 days')), $e['prueba_hasta']);
        igual('servidesk-basico', $e['plan_externo']);
        igual(3, limiteUsuariosEmpresa($pdo, (int)$e['id']), 'límite del panel aun en prueba');
        $u = $pdo->prepare("SELECT password FROM usuarios WHERE empresa_id = ? AND usuario = ?");
        $u->execute([(int)$e['id'], $correo]);
        verdadero(password_verify($r['acceso']['password'], (string)$u->fetchColumn()), 'el administrador entra con la contraseña entregada');
        lanza(fn() => solidCrearCuenta($pdo, ['nombre' => 'Sin correo', 'contacto' => []]), InvalidArgumentException::class, 'correo');
    });
});

prueba('D-50: vencido el pago la cuenta queda en solo lectura hasta que el panel decida (sin gracia propia)', function () use ($pdo) {
    conSatelite(function () use ($pdo) {
        [$emp] = empresaTemporal('Satélite vencido');
        $e = empresaPorId($pdo, $emp);
        solidAplicar($pdo, $e, ['estado' => 'activa', 'vence' => date('Y-m-d', strtotime('-1 day'))]);
        $s = situacionEmpresa(empresaPorId($pdo, $emp));
        igual('solo_lectura', $s['estado'], 'sin gracia local: la gracia ya venía en vence');
        solidAplicar($pdo, empresaPorId($pdo, $emp), ['vence' => date('Y-m-d', strtotime('-400 days'))]);
        igual('solo_lectura', situacionEmpresa(empresaPorId($pdo, $emp))['estado'], 'no se suspende sola');
        solidAplicar($pdo, empresaPorId($pdo, $emp), ['estado' => 'suspendida']);
        igual('bloqueado', situacionEmpresa(empresaPorId($pdo, $emp))['acceso'], 'el panel suspende');
        solidAplicar($pdo, empresaPorId($pdo, $emp), ['estado' => 'activa', 'vence' => date('Y-m-d', strtotime('+30 days'))]);
        igual('total', situacionEmpresa(empresaPorId($pdo, $emp))['acceso'], 'pago registrado en el panel');
        lanza(fn() => solidAplicar($pdo, empresaPorId($pdo, $emp), ['estado' => 'pagada']), InvalidArgumentException::class, 'estado');
        lanza(fn() => solidAplicar($pdo, empresaPorId($pdo, $emp), ['vence' => '31/12/2026']), InvalidArgumentException::class, 'vence');
    });
});

prueba('D-50: en modo satélite no se contrata en línea ni se cobra desde el panel local', function () use ($pdo) {
    [$emp] = empresaTemporal('Satélite contratar');
    verdadero(empresaPuedeContratar(empresaPorId($pdo, $emp)), 'sin satélite sí puede');
    conSatelite(function () use ($pdo, $emp) {
        verdadero(!empresaPuedeContratar(empresaPorId($pdo, $emp)), 'en satélite contrata con Solid Admin');
    });
});

prueba('D-50: la cuenta reporta uso y contacto; restablecer acceso cambia la contraseña del administrador', function () use ($pdo) {
    conSatelite(function () use ($pdo) {
        [$emp, $admin] = empresaTemporal('Satélite uso');
        $e = empresaPorId($pdo, $emp);
        $c = solidCuenta($pdo, $e);
        igual($e['slug'], $c['id']);
        igual(1, $c['uso']['usuarios']);
        verdadero(array_key_exists('ultima_actividad', $c['uso']), 'última actividad');
        $acc = solidRestablecerAcceso($pdo, $e);
        $hash = (string)$pdo->query("SELECT password FROM usuarios WHERE id = $admin")->fetchColumn();
        verdadero(password_verify($acc['password'], $hash), 'contraseña nueva');
        solidAplicar($pdo, $e, ['nombre' => 'Nombre desde el panel', 'contacto' => ['telefono' => '5511112222']]);
        igual('Nombre desde el panel', empresaPorId($pdo, $emp)['nombre']);
        igual('5511112222', empresaPorId($pdo, $emp)['contacto_telefono']);
    });
});

prueba('D-50: el endpoint responde 404 sin token configurado y 401 con token equivocado', function () {
    $correr = function (string $token, string $auth) {
        $f = tempnam(sys_get_temp_dir(), 'sl');
        file_put_contents($f, '<?php $_SERVER["REQUEST_METHOD"]="GET"; $_GET["ruta"]="info"; $_SERVER["HTTP_AUTHORIZATION"]=' . var_export($auth, true) . ';'
            . ($token ? 'putenv("SOLID_LINK_TOKEN=' . $token . '"); $_ENV["SOLID_LINK_TOKEN"]="' . $token . '";' : 'putenv("SOLID_LINK_TOKEN="); $_ENV["SOLID_LINK_TOKEN"]="";')
            . ' register_shutdown_function(function(){ echo "@@HTTP ".http_response_code(); }); require ' . var_export(realpath(__DIR__ . '/../api/solid.php'), true) . ';');
        $out = (string)shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($f) . ' 2>&1');
        @unlink($f);
        return $out;
    };
    verdadero(strpos($correr('', 'Bearer x'), '@@HTTP 404') !== false, '404 sin token configurado');
    verdadero(strpos($correr('token-solid-de-prueba-0123456789', 'Bearer otro-token-cualquiera-123456'), '@@HTTP 401') !== false, '401 token equivocado');
    $ok = $correr('token-solid-de-prueba-0123456789', 'Bearer token-solid-de-prueba-0123456789');
    verdadero(strpos($ok, '"contrato":1') !== false && strpos($ok, '@@HTTP 200') !== false, 'info con token correcto: ' . substr($ok, 0, 200));
});

// ---------------------------------------------------------------- auditoría integral 2026-11-24
prueba('L-11 · D-50: en satélite, la prueba vencida queda en solo lectura y la prueba sin vence tiene acceso total', function () use ($pdo) {
    conSatelite(function () use ($pdo) {
        [$emp] = empresaTemporal('Satélite prueba');
        solidAplicar($pdo, empresaPorId($pdo, $emp), ['estado' => 'prueba', 'vence' => date('Y-m-d', strtotime('-1 day'))]);
        $s = situacionEmpresa(empresaPorId($pdo, $emp));
        igual('lectura', $s['acceso'], 'prueba vencida:');
        verdadero(stripos($s['mensaje'], 'contratar un plan al iniciar sesión') === false, 'no promete contratar en línea: ' . $s['mensaje']);
        solidAplicar($pdo, empresaPorId($pdo, $emp), ['estado' => 'prueba', 'vence' => null]);
        igual('total', situacionEmpresa(empresaPorId($pdo, $emp))['acceso'], 'prueba sin vencimiento:');
    });
});

prueba('L-12 · D-50: un alta con datos inválidos no deja ninguna empresa creada', function () use ($pdo) {
    conSatelite(function () use ($pdo) {
        $antes = (int)$pdo->query('SELECT COUNT(*) FROM empresas')->fetchColumn();
        $correo = 'inv-' . bin2hex(random_bytes(3)) . '@pruebas.test';
        lanza(fn() => solidCrearCuenta($pdo, ['nombre' => 'Inválida', 'estado' => 'prueba', 'vence' => '2026-13-01', 'contacto' => ['correo' => $correo]]),
            InvalidArgumentException::class, 'vence');
        lanza(fn() => solidCrearCuenta($pdo, ['nombre' => 'Inválida', 'estado' => 'pagada', 'contacto' => ['correo' => $correo]]),
            InvalidArgumentException::class, 'estado');
        igual($antes, (int)$pdo->query('SELECT COUNT(*) FROM empresas')->fetchColumn(), 'empresas:');
    });
});

prueba('Q-4 · D-50: vence rechaza fechas que no existen y acepta el 29 de febrero bisiesto', function () use ($pdo) {
    conSatelite(function () use ($pdo) {
        [$emp] = empresaTemporal('Satélite fechas');
        lanza(fn() => solidAplicar($pdo, empresaPorId($pdo, $emp), ['estado' => 'activa', 'vence' => '2026-02-30']), InvalidArgumentException::class, 'vence');
        lanza(fn() => solidAplicar($pdo, empresaPorId($pdo, $emp), ['estado' => 'activa', 'vence' => '2027-02-29']), InvalidArgumentException::class, 'vence');
        solidAplicar($pdo, empresaPorId($pdo, $emp), ['estado' => 'activa', 'vence' => '2028-02-29']);
        igual('2028-02-29', empresaPorId($pdo, $emp)['pagado_hasta']);
    });
});

prueba('Q-3 · D-50: repetir el alta con el mismo correo indica que la cuenta ya existe y cómo entregar el acceso', function () use ($pdo) {
    conSatelite(function () use ($pdo) {
        $correo = 'rep-' . bin2hex(random_bytes(3)) . '@pruebas.test';
        $r = solidCrearCuenta($pdo, ['nombre' => 'Repetida', 'estado' => 'prueba', 'contacto' => ['correo' => $correo]]);
        $GLOBALS['__pruebas']['empresas'][] = (int)solidEmpresaPorSlug($pdo, $r['id'])['id'];
        lanza(fn() => solidCrearCuenta($pdo, ['nombre' => 'Repetida', 'estado' => 'prueba', 'contacto' => ['correo' => $correo]]),
            SolidConflicto::class, 'restablecer');
    });
});

prueba('L-13: la contraseña temporal del panel obliga a cambiarla antes de usar el sistema', function () use ($pdo) {
    conSatelite(function () use ($pdo) {
        $correo = 'tmp-' . bin2hex(random_bytes(3)) . '@pruebas.test';
        $r = solidCrearCuenta($pdo, ['nombre' => 'Temporal', 'estado' => 'activa', 'vence' => date('Y-m-d', strtotime('+30 days')), 'contacto' => ['correo' => $correo]]);
        $e = solidEmpresaPorSlug($pdo, $r['id']);
        $GLOBALS['__pruebas']['empresas'][] = (int)$e['id'];
        $uid = (int)$pdo->query("SELECT id FROM usuarios WHERE empresa_id = {$e['id']}")->fetchColumn();
        $resp = llamar((int)$e['id'], $uid, 'Administrador', 'GET', 'backend/inicio.php');
        igual(403, $resp['http'], 'inicio con contraseña temporal:');
        igual('cambio_password', $resp['json']['codigo'] ?? null, 'código:');
        $resp = llamar((int)$e['id'], $uid, 'Administrador', 'POST', 'backend/cambiar_password.php', ['json' => ['password_actual' => $r['acceso']['password'], 'password_nueva' => 'NuevaClave-2026']]);
        verdadero(!empty($resp['json']['success']), 'cambio de contraseña: ' . $resp['texto']);
        igual(200, llamar((int)$e['id'], $uid, 'Administrador', 'GET', 'backend/inicio.php')['http'], 'inicio después del cambio:');
        // Restablecer acceso vuelve a exigir el cambio
        solidRestablecerAcceso($pdo, $e);
        igual(1, (int)$pdo->query("SELECT debe_cambiar_password FROM usuarios WHERE id = $uid")->fetchColumn(), 'después de restablecer:');
    });
});

prueba('S-3 · D-50: el límite de usuarios se respeta (máximo sí, máximo + 1 no)', function () use ($pdo) {
    conSatelite(function () use ($pdo) {
        [$emp, $admin] = empresaTemporal('Satélite límite');
        solidAplicar($pdo, empresaPorId($pdo, $emp), ['estado' => 'activa', 'vence' => date('Y-m-d', strtotime('+30 days')), 'limites' => ['usuarios' => 2]]);
        $alta = fn($n) => llamar($emp, $admin, 'Administrador', 'POST', 'backend/alta-user.php', ['post' => [
            'nombre' => "Usuario $n " . bin2hex(random_bytes(2)), 'correo' => "lim$n-" . bin2hex(random_bytes(3)) . '@pruebas.test',
            'password' => 'Clave-segura-123', 'rol' => 'Técnico'], 'sesion' => ['huella' => null]]);
        verdadero(!empty($alta(2)['json']['success']), 'el 2.º usuario entra');
        $r = $alta(3);
        verdadero(empty($r['json']['success']), 'el 3.º se rechaza');
        verdadero(stripos((string)($r['json']['message'] ?? ''), 'plan superior') === false, 'en satélite no manda a cambiar de plan aquí: ' . ($r['json']['message'] ?? ''));
    });
});

// ---------------------------------------------------------------- auditoría Argon, bloques 1 y 2 (2026-11-24)
prueba('H-04 · D-50: en satélite, limites.usuarios = null es sin límite (no cae al plan local)', function () use ($pdo) {
    conSatelite(function () use ($pdo) {
        [$emp] = empresaTemporal('Satélite sin límite');
        $pdo->prepare('UPDATE empresas SET plan_id = 2 WHERE id = ?')->execute([$emp]);
        solidAplicar($pdo, empresaPorId($pdo, $emp), ['estado' => 'activa', 'vence' => date('Y-m-d', strtotime('+30 days')), 'limites' => ['usuarios' => null]]);
        igual(null, limiteUsuariosEmpresa($pdo, $emp));
    });
});

prueba('H-05 · D-56: el alta responde conflicto si el correo ya es de otra cuenta (también de plataforma o como correo)', function () use ($pdo) {
    conSatelite(function () use ($pdo) {
        $super = (string)$pdo->query("SELECT usuario FROM usuarios WHERE empresa_id IS NULL LIMIT 1")->fetchColumn();
        if ($super !== '') lanza(fn() => solidCrearCuenta($pdo, ['nombre' => 'X', 'contacto' => ['correo' => $super]]), SolidConflicto::class, '');
        [$emp, $u] = empresaTemporal('Correo distinto');
        $correo = 'alterno-' . bin2hex(random_bytes(3)) . '@pruebas.test';
        $pdo->prepare('UPDATE usuarios SET correo = ? WHERE id = ?')->execute([$correo, $u]);
        lanza(fn() => solidCrearCuenta($pdo, ['nombre' => 'X', 'contacto' => ['correo' => $correo]]), SolidConflicto::class, '');
    });
});

prueba('H-05 · D-56: en satélite, un alta en prueba sin vence no hereda los días de prueba locales', function () use ($pdo) {
    conSatelite(function () use ($pdo) {
        $r = solidCrearCuenta($pdo, ['nombre' => 'Prueba abierta', 'estado' => 'prueba', 'contacto' => ['correo' => 'abierta-' . bin2hex(random_bytes(3)) . '@pruebas.test']]);
        $e = solidEmpresaPorSlug($pdo, $r['id']);
        $GLOBALS['__pruebas']['empresas'][] = (int)$e['id'];
        igual(null, $e['prueba_hasta']);
    });
});

prueba('H-06 · D-56: datos demasiado largos o límites no enteros se rechazan sin crear nada', function () use ($pdo) {
    conSatelite(function () use ($pdo) {
        $antes = (int)$pdo->query('SELECT COUNT(*) FROM empresas')->fetchColumn();
        $correo = 'largo-' . bin2hex(random_bytes(3)) . '@pruebas.test';
        lanza(fn() => solidCrearCuenta($pdo, ['nombre' => 'Largo', 'contacto' => ['correo' => $correo, 'telefono' => '+52 (664) 123-4567 ext. 102']]), InvalidArgumentException::class, 'teléfono');
        lanza(fn() => solidCrearCuenta($pdo, ['nombre' => str_repeat('N', 151), 'contacto' => ['correo' => $correo]]), InvalidArgumentException::class, 'nombre');
        lanza(fn() => solidCrearCuenta($pdo, ['nombre' => 'Límite', 'limites' => ['usuarios' => 2.5], 'contacto' => ['correo' => $correo]]), InvalidArgumentException::class, 'usuarios');
        igual($antes, (int)$pdo->query('SELECT COUNT(*) FROM empresas')->fetchColumn(), 'empresas:');
    });
});

prueba('H-11 · L-13: si un administrador le asigna otra contraseña, deja de ser temporal', function () use ($pdo) {
    [$emp, $admin] = empresaTemporal('Temporal admin');
    $tec = usuarioTemporal($emp, 'Técnico');
    $pdo->prepare('UPDATE usuarios SET debe_cambiar_password = 1 WHERE id = ?')->execute([$tec]);
    $u = $pdo->query("SELECT nombre, correo, telefono, rol FROM usuarios WHERE id = $tec")->fetch(PDO::FETCH_ASSOC);
    $r = llamar($emp, $admin, 'Administrador', 'POST', 'backend/actualiza-usuario.php', ['json' => $u + ['id' => $tec, 'password' => 'Otra-clave-2026']]);
    igual(0, (int)$pdo->query("SELECT debe_cambiar_password FROM usuarios WHERE id = $tec")->fetchColumn(), 'bandera tras ' . $r['texto'] . ':');
});
