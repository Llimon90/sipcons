<?php
// La operación es en Tijuana (Baja California), no en el huso horario por
// defecto del hosting (normalmente CDMX). Se fija aquí porque este archivo
// se carga en prácticamente todas las páginas y endpoints (incluido
// auth/logout.php, que no pasa por config/database.php), así que date()/time()
// en PHP siempre usan la hora local. date_default_timezone_set() es seguro de
// llamar más de una vez si config/database.php también lo hace.
date_default_timezone_set('America/Tijuana');

require_once __DIR__ . '/env.php'; // SESSION_LIFETIME también aplica en logout.php

// ---------------------------------------------------------------- SESIÓN
// PHP bloquea el archivo de sesión (flock) desde session_start() hasta que
// termina el script. En el hosting compartido, con disco lento, eso hacía que
// las peticiones simultáneas de una misma pestaña (session_check, analíticas,
// técnicos, la API de la página...) se formaran en fila esperando el candado
// y acabaran en 504.
//
// Por eso la sesión se abre en modo SOLO LECTURA (read_and_close): se lee
// $_SESSION y el candado se suelta en el acto. Solo login.php y logout.php
// escriben en la sesión; esos scripts definen, ANTES de incluir este archivo:
//     define('SESION_ESCRITURA', true);
// Cualquier script nuevo que necesite modificar $_SESSION debe hacer lo mismo
// (y llamar session_write_close() en cuanto termine de escribir).
if (session_status() === PHP_SESSION_NONE && !defined('SIPCONS_SESION_INICIADA')) {
    define('SIPCONS_SESION_INICIADA', true);
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

    $cookieParams = [
        'lifetime' => $lifetime,
        'path'     => '/',
        'secure'   => isset($_SERVER['HTTPS']),
        'httponly' => true,
        'samesite' => 'Lax',
    ];
    session_set_cookie_params($cookieParams);

    $sesionEscritura = defined('SESION_ESCRITURA') && SESION_ESCRITURA;

    if ($sesionEscritura) {
        session_start();
    } elseif (isset($_COOKIE[session_name()])) {
        session_start(['read_and_close' => true]);

        // read_and_close no reescribe el archivo, así que su fecha de
        // modificación (la que usa la limpieza de PHP para borrar sesiones
        // viejas) no avanzaría. Una vez al día se abre un instante en modo
        // escritura para renovarla y se reenvía la cookie con otros
        // $lifetime segundos: el usuario activo no pierde la sesión.
        if (!empty($_SESSION['user_id'])
            && time() - (int)($_SESSION['_renovada'] ?? 0) > 86400
            && !headers_sent()
            && @session_start()
        ) {
            $_SESSION['_renovada'] = time();
            session_write_close();
            setcookie(session_name(), session_id(), [
                'expires'  => time() + $lifetime,
                'path'     => $cookieParams['path'],
                'secure'   => $cookieParams['secure'],
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
        }
    } else {
        // Sin cookie de sesión no hay nada que leer: no se toca el disco ni
        // se crea un archivo de sesión vacío (evita miles de archivos/inodos
        // por peticiones anónimas, bots o monitores).
        $_SESSION = [];
    }
    unset($sesionEscritura, $cookieParams);
}

function isLoggedIn(): bool {
    return isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
}

function currentUser(): array {
    return [
        'id'     => $_SESSION['user_id']  ?? null,
        'nombre' => $_SESSION['nombre']   ?? '',
        'usuario'=> $_SESSION['usuario']  ?? '',
        'rol'    => $_SESSION['rol']      ?? '',
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
}

/**
 * ¿Puede el usuario en sesión poner una incidencia en "Cerrado con factura"
 * o "Cerrado sin factura"?
 *  - El rol Técnico nunca puede.
 *  - Usuarios restringidos individualmente (cualquier rol) en el .env de cada
 *    servidor: USUARIOS_SIN_CIERRE=ariana,otro_usuario (nombre de usuario de
 *    inicio de sesión, sin importar mayúsculas). Para quitar la restricción
 *    basta con borrar al usuario de esa línea; no hay que cambiar código.
 */
function puedeCerrarIncidencias(): bool {
    if (($_SESSION['rol'] ?? '') === 'Técnico') return false;

    $lista = array_filter(array_map(
        fn($u) => mb_strtolower(trim($u)),
        explode(',', (string)($_ENV['USUARIOS_SIN_CIERRE'] ?? ''))
    ));
    $usuario = mb_strtolower(trim((string)($_SESSION['usuario'] ?? '')));
    return $usuario === '' || !in_array($usuario, $lista, true);
}

const ESTATUS_DE_CIERRE = ['Cerrado con factura', 'Cerrado sin factura'];

function requireRole(string ...$roles): void {
    requireAuth();
    if (!in_array($_SESSION['rol'] ?? '', $roles, true)) {
        http_response_code(403);
        header('Content-Type: application/json');
        die(json_encode(['error' => 'Acceso denegado']));
    }
}
