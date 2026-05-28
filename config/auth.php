<?php
if (session_status() === PHP_SESSION_NONE) {
    $lifetime = (int)($_ENV['SESSION_LIFETIME'] ?? 28800); // 8 horas por defecto

    session_set_cookie_params([
        'lifetime' => $lifetime,
        'path'     => '/',
        'secure'   => isset($_SERVER['HTTPS']),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    session_start();
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

function requireRole(string ...$roles): void {
    requireAuth();
    if (!in_array($_SESSION['rol'] ?? '', $roles, true)) {
        http_response_code(403);
        header('Content-Type: application/json');
        die(json_encode(['error' => 'Acceso denegado']));
    }
}
