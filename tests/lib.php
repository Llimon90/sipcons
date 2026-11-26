<?php
// Mini sistema de pruebas sin dependencias (no hay Composer en el hosting).
//
//   C:\xampp\php\php.exe tests\ejecutar.php            todas las pruebas
//   C:\xampp\php\php.exe tests\ejecutar.php cotiz      solo los archivos que contengan "cotiz"
//
// Las pruebas corren contra la base LOCAL: crean empresas temporales con
// crearEmpresa() y las borran al terminar (aunque una prueba falle).
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/plataforma.php';
require_once __DIR__ . '/../auth/permisos.php';

$host = $_ENV['DB_HOST'] ?? '';
if (!in_array($host, ['localhost', '127.0.0.1'], true)) {
    fwrite(STDERR, "Abortado: las pruebas solo corren contra una base local (DB_HOST=$host).\n");
    exit(1);
}

$_SESSION = [];
$GLOBALS['__pruebas'] = ['ok' => 0, 'fallas' => [], 'empresas' => []];

class FallaPrueba extends Exception {}

function prueba(string $nombre, callable $fn): void {
    try {
        $fn();
        $GLOBALS['__pruebas']['ok']++;
        echo "  \033[32m✓\033[0m $nombre\n";
    } catch (Throwable $e) {
        $GLOBALS['__pruebas']['fallas'][] = $nombre;
        $donde = $e instanceof FallaPrueba ? '' : ' (' . get_class($e) . ' en ' . basename($e->getFile()) . ':' . $e->getLine() . ')';
        echo "  \033[31m✗ $nombre\033[0m\n      " . $e->getMessage() . "$donde\n";
        global $pdo;
        if ($pdo->inTransaction()) $pdo->rollBack();
    }
}

function grupo(string $titulo): void { echo "\n$titulo\n"; }

function igual($esperado, $real, string $msg = ''): void {
    if (is_float($esperado) || is_float($real)) {
        if (abs((float)$esperado - (float)$real) > 0.0001) throw new FallaPrueba("$msg esperado " . var_export($esperado, true) . ', se obtuvo ' . var_export($real, true));
        return;
    }
    if ($esperado !== $real) throw new FallaPrueba("$msg esperado " . var_export($esperado, true) . ', se obtuvo ' . var_export($real, true));
}

function verdadero($valor, string $msg): void {
    if (!$valor) throw new FallaPrueba($msg);
}

/** La función debe lanzar $clase; si se da $contiene, el mensaje (o motivo) debe incluirlo. */
function lanza(callable $fn, string $clase, string $contiene = ''): Throwable {
    try {
        $fn();
    } catch (Throwable $e) {
        global $pdo;
        if ($pdo->inTransaction()) $pdo->rollBack();
        if (!($e instanceof $clase)) throw new FallaPrueba("se esperaba $clase y se lanzó " . get_class($e) . ': ' . $e->getMessage());
        $texto = $e->getMessage() . ' ' . ($e->motivo ?? '');
        if ($contiene !== '' && mb_stripos($texto, $contiene) === false) throw new FallaPrueba("el mensaje no contiene \"$contiene\": " . $e->getMessage());
        return $e;
    }
    throw new FallaPrueba("se esperaba $clase y no se lanzó nada");
}

/** Empresa temporal (se borra al final). Devuelve [id, id del administrador]. */
function empresaTemporal(string $nombre): array {
    global $pdo;
    $correo = 'prueba-' . bin2hex(random_bytes(4)) . '@pruebas.test';
    $id = crearEmpresa($pdo, ['nombre' => $nombre . ' (prueba)', 'estado' => 'activa', 'admin_nombre' => 'Admin ' . $nombre,
        'admin_usuario' => $correo, 'admin_password' => 'Prueba2026!', 'contacto_correo' => $correo]);
    $pdo->prepare('UPDATE empresas SET pagado_hasta = DATE_ADD(CURDATE(), INTERVAL 30 DAY) WHERE id = ?')->execute([$id]);
    $GLOBALS['__pruebas']['empresas'][] = $id;
    $admin = (int)$pdo->query('SELECT id FROM usuarios WHERE empresa_id = ' . (int)$id . " AND rol = 'Administrador'")->fetchColumn();
    return [$id, $admin];
}

/** Activa la empresa y la sesión de un usuario con ese rol. */
function como(int $empresaId, int $usuarioId, string $rol, string $nombre = 'Usuario de prueba'): void {
    establecerEmpresa($empresaId);
    $_SESSION = ['user_id' => $usuarioId, 'rol' => $rol, 'nombre' => $nombre, 'empresa_id' => $empresaId];
}

function usuarioTemporal(int $empresaId, string $rol, array $extra = []): int {
    global $pdo;
    $correo = 'u-' . bin2hex(random_bytes(4)) . '@pruebas.test';
    $pdo->prepare('INSERT INTO usuarios (empresa_id, nombre, correo, telefono, usuario, password, rol) VALUES (?, ?, ?, ?, ?, ?, ?)')
        ->execute([$empresaId, "$rol de prueba " . bin2hex(random_bytes(3)), $correo, '', $correo, password_hash('x', PASSWORD_DEFAULT), $rol]);  // nombre único en la empresa (AS-7)
    $id = (int)$pdo->lastInsertId();
    foreach ($extra as $col => $valor) $pdo->prepare("UPDATE usuarios SET `$col` = ? WHERE id = ?")->execute([$valor, $id]);
    return $id;
}

function limpiarEmpresasTemporales(): void {
    global $pdo;
    establecerEmpresa(null);
    foreach ($GLOBALS['__pruebas']['empresas'] as $id) eliminarEmpresa($pdo, $id);
    $GLOBALS['__pruebas']['empresas'] = [];
}

/**
 * Llama a un endpoint real (backend/...) en otro proceso, con la sesión de
 * $usuarioId/$rol en $empresaId. Devuelve ['http' => int, 'json' => ?array, 'texto' => string].
 *   llamar($emp, $tec, 'Técnico', 'POST', 'backend/actualiza.php', ['post' => [...]])
 *   llamar($emp, $adm, 'Administrador', 'GET', 'backend/detalle.php', ['get' => ['id' => 5]])
 *   llamar($emp, $adm, 'Administrador', 'POST', 'backend/eliminar-incidencia.php', ['json' => ['id' => 5]])
 */
function llamar(int $empresaId, int $usuarioId, string $rol, string $metodo, string $script, array $datos = []): array {
    global $pdo;
    $nombre = (string)$pdo->query('SELECT nombre FROM usuarios WHERE id = ' . (int)$usuarioId)->fetchColumn();
    $peticion = [
        'script' => $script, 'metodo' => $metodo,
        'get' => $datos['get'] ?? [], 'post' => $datos['post'] ?? [],
        'json' => $datos['json'] ?? null,
        'sesion' => ['user_id' => $usuarioId, 'rol' => $rol, 'nombre' => $nombre, 'empresa_id' => $empresaId] + ($datos['sesion'] ?? []),
    ];
    $archivo = tempnam(sys_get_temp_dir(), 'sdp');
    file_put_contents($archivo, json_encode($peticion, JSON_UNESCAPED_UNICODE));
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/endpoint_runner.php') . ' ' . escapeshellarg($archivo);
    $salida = (string)shell_exec($cmd . ' 2>&1');
    @unlink($archivo);
    $http = 0;
    if (preg_match('/\n?@@HTTP (\d{3})\s*$/', $salida, $m)) {
        $http = (int)$m[1];
        $salida = substr($salida, 0, -strlen($m[0]));
    }
    // Los endpoints de incidencias usan otra conexión: la empresa de la prueba
    // vuelve a quedar activa en este proceso.
    establecerEmpresa($empresaId);
    return ['http' => $http, 'json' => json_decode(trim($salida), true), 'texto' => $salida];
}

/**
 * Lanza la misma petición en $n procesos a la vez (para probar carreras y doble envío).
 * Devuelve las respuestas de llamar() en el orden de lanzamiento.
 */
function llamarSimultaneo(int $n, int $empresaId, int $usuarioId, string $rol, string $metodo, string $script, array $datos = []): array {
    global $pdo;
    $nombre = (string)$pdo->query('SELECT nombre FROM usuarios WHERE id = ' . (int)$usuarioId)->fetchColumn();
    $procesos = [];
    for ($i = 0; $i < $n; $i++) {
        $archivo = tempnam(sys_get_temp_dir(), 'sdp');
        file_put_contents($archivo, json_encode([
            'script' => $script, 'metodo' => $metodo, 'get' => $datos['get'] ?? [], 'post' => $datos['post'] ?? [], 'json' => $datos['json'] ?? null,
            'sesion' => ['user_id' => $usuarioId, 'rol' => $rol, 'nombre' => $nombre, 'empresa_id' => $empresaId],
        ], JSON_UNESCAPED_UNICODE));
        $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/endpoint_runner.php') . ' ' . escapeshellarg($archivo);
        $p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $procesos[] = [$p, $pipes, $archivo];
    }
    $salidas = [];
    foreach ($procesos as [$p, $pipes, $archivo]) {
        $texto = stream_get_contents($pipes[1]);
        fclose($pipes[1]); fclose($pipes[2]); proc_close($p); @unlink($archivo);
        $http = preg_match('/@@HTTP (\d{3})\s*$/', $texto, $m) ? (int)$m[1] : 0;
        $salidas[] = ['http' => $http, 'json' => json_decode(trim(preg_replace('/\n?@@HTTP \d{3}\s*$/', '', $texto)), true), 'texto' => $texto];
    }
    establecerEmpresa($empresaId);
    return $salidas;
}

/** D-26: da de alta un cliente en el catálogo de la empresa activa (si no existe). */
function clienteCatalogo(string $nombre): void {
    global $pdo;
    $st = $pdo->prepare('SELECT COUNT(*) FROM clientes WHERE nombre = ?');
    $st->execute([$nombre]);
    if (!(int)$st->fetchColumn()) {
        $pdo->prepare("INSERT INTO clientes (nombre, rfc, direccion, telefono, contactos, email) VALUES (?, '', '', '', '', '')")->execute([$nombre]);
    }
}
