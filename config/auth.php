<?php
// Se carga en prácticamente todas las páginas y endpoints (incluido
// auth/logout.php, que no pasa por config/database.php): env.php lee el .env
// y fija la zona horaria (APP_TIMEZONE) para que date()/time() usen la hora local.
require_once __DIR__ . '/env.php';

if (session_status() === PHP_SESSION_NONE) {
    // 30 días: la sesión persiste hasta que el usuario cierre sesión explícitamente
    $lifetime = (int)($_ENV['SESSION_LIFETIME'] ?? 2592000);

    ini_set('session.gc_maxlifetime', $lifetime);

    // En hosting compartido (cPanel), la carpeta de sesiones suele ser
    // común a todas las cuentas del servidor: si otra app tiene un
    // gc_maxlifetime más corto, su limpieza puede borrar sesiones de
    // esta app antes de tiempo. Usamos una carpeta propia (protegida por
    // .htaccess) para que nuestra configuración de 30 días se respete
    // siempre. Si no se puede crear/escribir, seguimos con la ruta
    // por defecto del servidor en vez de romper el login.
    $sessionPath = __DIR__ . '/../storage/sessions';
    if (!is_dir($sessionPath)) {
        @mkdir($sessionPath, 0700, true);
    }
    if (is_dir($sessionPath) && is_writable($sessionPath)) {
        session_save_path($sessionPath);
    }

    session_set_cookie_params([
        'lifetime' => $lifetime,
        'path'     => '/',
        // S-10: también detrás del proxy de Hostinger (TLS terminado antes de PHP)
        'secure'   => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
                        || strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https'
                        || str_starts_with((string)($_ENV['APP_URL'] ?? getenv('APP_URL') ?: ''), 'https://'),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    session_start();
}

// Empresa de la sesión: activa el prefijo de sus tablas para toda la petición.
// Solo si la conexión ya existe (logout.php carga este archivo sin base de datos).
if (!empty($_SESSION['empresa_id']) && function_exists('establecerEmpresa') && isset($GLOBALS['pdo'])) {
    establecerEmpresa((int)$_SESSION['empresa_id']);
}

const ROL_SUPERADMIN = 'Superadmin';

// Roles que ya no existen (migración 006) y su equivalente. Se normalizan al
// iniciar sesión y en sesiones abiertas antes del cambio, así que el código
// funciona igual aunque la migración todavía no se haya aplicado.
const ROLES_ANTERIORES = ['Técnico/Administrador' => 'Administrador', 'Programador' => 'Administrador'];

function normalizarRol(string $rol): string {
    return ROLES_ANTERIORES[$rol] ?? $rol;
}

if (isset($_SESSION['rol']) && isset(ROLES_ANTERIORES[$_SESSION['rol']])) {
    $_SESSION['rol'] = normalizarRol($_SESSION['rol']);
}

// Peticiones que modifican pero se permiten en modo solo lectura
const ENDPOINTS_PERMITIDOS_SOLO_LECTURA = ['guardar_tema.php', 'analytics_registrar.php', 'logout.php', 'login.php', 'cambiar_password.php'];

// L-13: con la contraseña temporal pendiente de cambiar solo se permite esto
const ENDPOINTS_PERMITIDOS_CAMBIO_PASSWORD = ['cambiar_password.php', 'session_check.php', 'logout.php', 'guardar_tema.php', 'analytics_registrar.php'];

function esSuperadmin(): bool {
    return ($_SESSION['rol'] ?? '') === ROL_SUPERADMIN && empty($_SESSION['empresa_id']);
}

function cerrarSesion(): void {
    $_SESSION = [];
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_destroy();
    }
}

function responderBloqueo(int $codigo, array $datos): void {
    http_response_code($codigo);
    header('Content-Type: application/json');
    $datos += ['success' => false, 'exito' => false];
    $datos['error'] = $datos['error'] ?? $datos['mensaje'] ?? 'Acceso denegado';
    $datos['message'] = $datos['message'] ?? $datos['mensaje'] ?? $datos['error'];
    die(json_encode($datos, JSON_UNESCAPED_UNICODE));
}

function isLoggedIn(): bool {
    return isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
}

/** Huella corta del hash de la contraseña: cambia cuando cambia la contraseña. */
function huellaPassword(string $hash): string {
    return substr(hash('sha256', $hash), 0, 16);
}

/**
 * La cuenta en sesión sigue vigente: existe, es de la misma empresa y su
 * contraseña no cambió desde que inició sesión. Se consulta en cada petición
 * (una lectura por llave primaria), así que eliminar a un usuario o cambiarle
 * la contraseña lo saca al instante, y un cambio de rol aplica sin cerrar sesión.
 */
function revalidarUsuarioSesion(): bool {
    global $pdo;
    static $resultado = null;
    if ($resultado !== null) return $resultado;
    $id = (int)($_SESSION['user_id'] ?? 0);
    if ($id <= 0 || !isset($pdo)) return $resultado = false;
    $st = $pdo->prepare('SELECT empresa_id, rol, nombre, password FROM usuarios WHERE id = ?');
    $st->execute([$id]);
    $u = $st->fetch(PDO::FETCH_ASSOC);
    if (!$u) return $resultado = false;

    $huella = huellaPassword((string)$u['password']);
    if (isset($_SESSION['huella']) && !hash_equals((string)$_SESSION['huella'], $huella)) return $resultado = false;
    $_SESSION['huella'] = $huella; // sesiones abiertas antes de este control

    // Modo soporte: la sesión es del superadministrador (sin empresa) y conserva su rol de soporte
    if (!empty($_SESSION['soporte'])) {
        return $resultado = $u['empresa_id'] === null && $u['rol'] === ROL_SUPERADMIN;
    }
    if ((int)($u['empresa_id'] ?? 0) !== (int)($_SESSION['empresa_id'] ?? 0)) return $resultado = false;
    $_SESSION['rol'] = normalizarRol((string)$u['rol']);
    $_SESSION['nombre'] = $u['nombre'];
    return $resultado = true;
}

/** Tras cambiar la contraseña propia, la sesión actual sigue abierta (las demás se cierran). */
function actualizarHuellaSesion(string $hash): void {
    $_SESSION['huella'] = huellaPassword($hash);
}

/** Cierra una sesión que ya no es válida y responde 401. */
function rechazarSesionInvalida(): void {
    cerrarSesion();
    $appUrl = rtrim($_ENV['APP_URL'] ?? '', '/');
    responderBloqueo(401, ['mensaje' => 'Tu sesión terminó porque tu cuenta cambió. Inicia sesión de nuevo.', 'login_url' => $appUrl . '/auth/login.html']);
}

function currentUser(): array {
    return [
        'id'     => $_SESSION['user_id']  ?? null,
        'nombre' => $_SESSION['nombre']   ?? '',
        'usuario'=> $_SESSION['usuario']  ?? '',
        'rol'    => $_SESSION['rol']      ?? '',
        'empresa_id' => $_SESSION['empresa_id'] ?? null,
    ];
}

function requireAuth(): void {
    // Dejar pasar preflight CORS sin verificar sesión
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        http_response_code(200);
        exit;
    }

    if (!isLoggedIn()) {
        // Un envío (guardar, eliminar...) que llega sin sesión se pierde:
        // se anota para poder explicar después un "guardé y no aparece".
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
            require_once __DIR__ . '/guardados_log.php';
            registrarFalloGuardado('sesion_cerrada', $_POST['id'] ?? null);
        }

        $appUrl   = rtrim($_ENV['APP_URL'] ?? '', '/');
        $loginUrl = $appUrl . '/auth/login.html';

        if (
            isset($_SERVER['HTTP_ACCEPT']) &&
            strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false
        ) {
            http_response_code(401);
            header('Content-Type: application/json');
            die(json_encode(['error' => 'No autorizado', 'login_url' => $loginUrl]));
        }
        header("Location: $loginUrl");
        exit;
    }

    if (!revalidarUsuarioSesion()) rechazarSesionInvalida();

    // A partir de aquí toda petición autenticada opera dentro de una empresa
    if (empty($_SESSION['empresa_id'])) {
        if (esSuperadmin()) {
            responderBloqueo(403, ['mensaje' => 'La cuenta de plataforma no opera dentro de una empresa.']);
        }
        cerrarSesion(); // sesión anterior a la migración multiempresa
        responderBloqueo(401, ['mensaje' => 'Tu sesión expiró. Inicia sesión de nuevo.', 'login_url' => '/auth/login.html']);
    }

    // En modo soporte la plataforma entra aunque la cuenta esté vencida o suspendida
    if (!empty($_SESSION['soporte'])) {
        return;
    }
    // L-13: la contraseña temporal que entregó el panel se cambia antes de usar el sistema
    require_once __DIR__ . '/usuarios.php';
    global $pdo;
    if (isset($pdo) && !in_array(basename($_SERVER['SCRIPT_NAME'] ?? ''), ENDPOINTS_PERMITIDOS_CAMBIO_PASSWORD, true)
        && usuarioDebeCambiarPassword($pdo, (int)($_SESSION['user_id'] ?? 0))) {
        responderBloqueo(403, ['mensaje' => 'Cambia tu contraseña temporal para continuar.', 'codigo' => 'cambio_password', 'redirect' => 'ajustes.html#seguridad']);
    }
    $situacion = situacionEmpresa(empresaActual());
    if ($situacion['acceso'] === 'bloqueado') {
        responderBloqueo(403, ['mensaje' => $situacion['mensaje'], 'estado_empresa' => $situacion['estado']]);
    }
    $metodo = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if ($situacion['acceso'] === 'lectura' && !in_array($metodo, ['GET', 'HEAD'], true)
        && !in_array(basename($_SERVER['SCRIPT_NAME'] ?? ''), ENDPOINTS_PERMITIDOS_SOLO_LECTURA, true)) {
        responderBloqueo(403, ['mensaje' => $situacion['mensaje'], 'estado_empresa' => $situacion['estado'], 'solo_lectura' => true]);
    }
}

/** Solo la cuenta de plataforma (superadministrador, sin empresa). */
function requireSuperadmin(): void {
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
        http_response_code(200);
        exit;
    }
    if (isLoggedIn() && !revalidarUsuarioSesion()) rechazarSesionInvalida();
    if (!isLoggedIn() || !esSuperadmin()) {
        responderBloqueo(isLoggedIn() ? 403 : 401, ['mensaje' => 'Acceso exclusivo de la cuenta de plataforma.']);
    }
}

/**
 * ¿Puede el usuario en sesión poner una incidencia en "Cerrado con factura"
 * o "Cerrado sin factura"?
 *  - El rol Técnico nunca puede.
 *  - Cualquier otro usuario puede, salvo que el administrador de su empresa le
 *    quite el permiso en Usuarios (usuarios.puede_cerrar = 0, migración 003).
 *    Se consulta en cada petición: el cambio aplica sin cerrar sesión.
 */
function puedeCerrarIncidencias(): bool {
    if (($_SESSION['rol'] ?? '') === 'Técnico') return false;
    $id = (int)($_SESSION['user_id'] ?? 0);
    if ($id <= 0 || !usuariosTienePuedeCerrar()) return true;
    global $pdo;
    $st = $pdo->prepare('SELECT puede_cerrar FROM usuarios WHERE id = ?');
    $st->execute([$id]);
    $valor = $st->fetchColumn();
    return $valor === false || (int)$valor === 1;
}

/** La columna usuarios.puede_cerrar existe (migración 003 aplicada). */
function usuariosTienePuedeCerrar(): bool {
    static $existe = null;
    if ($existe === null) {
        global $pdo;
        try {
            $existe = (bool)$pdo->query("SHOW COLUMNS FROM usuarios LIKE 'puede_cerrar'")->fetch();
        } catch (Throwable $e) {
            $existe = false;
        }
    }
    return $existe;
}

const ESTATUS_DE_CIERRE = ['Cerrado con factura', 'Cerrado sin factura'];

/** La columna usuarios.puede_autorizar_descuentos existe (migración 007 aplicada). */
function usuariosTieneAutorizaDescuentos(): bool {
    static $existe = null;
    if ($existe === null) {
        global $pdo;
        try {
            $existe = (bool)$pdo->query("SHOW COLUMNS FROM usuarios LIKE 'puede_autorizar_descuentos'")->fetch();
        } catch (Throwable $e) {
            $existe = false;
        }
    }
    return $existe;
}

function requireRole(string ...$roles): void {
    requireAuth();
    if (!in_array($_SESSION['rol'] ?? '', $roles, true)) {
        http_response_code(403);
        header('Content-Type: application/json');
        die(json_encode(['error' => 'Acceso denegado']));
    }
}
