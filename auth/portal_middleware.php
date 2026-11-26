<?php
// Sesión y autenticación del portal de autoservicio de clientes.
//
// A propósito NO incluye config/auth.php (sesión de personal): usa su propio
// nombre de sesión y su propia cookie, para que una cuenta de portal nunca
// alcance nada del sistema interno (ni al revés). Requiere config/database.php
// (expone $conn/$pdo), que a su vez ya carga el .env.
require_once __DIR__ . '/../config/database.php';

const PORTAL_SESSION_NAME = 'sipcons_portal';
const PORTAL_INTENTOS_MAXIMOS = 5;
const PORTAL_BLOQUEO_MINUTOS = 15;
const PORTAL_RESET_EXPIRA_MINUTOS = 60;

if (session_status() === PHP_SESSION_NONE) {
    session_name(PORTAL_SESSION_NAME);

    $portalSessionPath = __DIR__ . '/../storage/sessions_portal';
    if (!is_dir($portalSessionPath)) {
        @mkdir($portalSessionPath, 0700, true);
    }
    if (is_dir($portalSessionPath) && is_writable($portalSessionPath)) {
        session_save_path($portalSessionPath);
    }

    session_set_cookie_params([
        'lifetime' => 0, // el cliente cierra sesión explícitamente o cierra el navegador
        'path'     => '/portal',
        'secure'   => isset($_SERVER['HTTPS']),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

header('Access-Control-Allow-Origin: *');
header('Content-Type: application/json');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

function portalEstaLogueado(): bool {
    return !empty($_SESSION['portal_usuario_id']) && !empty($_SESSION['portal_cliente_id']);
}

function portalUsuarioActual(): array {
    return [
        'id'         => $_SESSION['portal_usuario_id'] ?? null,
        'cliente_id' => $_SESSION['portal_cliente_id'] ?? null,
        'email'      => $_SESSION['portal_email'] ?? '',
    ];
}

function requirePortalAuth(): void {
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
        http_response_code(200);
        exit;
    }
    if (!portalEstaLogueado()) {
        http_response_code(401);
        die(json_encode(['success' => false, 'error' => 'No autorizado']));
    }
}
