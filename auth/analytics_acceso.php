<?php
// Control de acceso del panel de analíticas de uso.
//
// No depende del login del portal ni de roles: solo entra quien conozca el
// token largo ANALYTICS_TOKEN definido en el .env del servidor (mínimo 32
// caracteres; si falta o es más corto, el panel queda deshabilitado).
//
// Flujo: se abre public/analiticas.php?k=TOKEN una vez; si es correcto se
// entrega una cookie HttpOnly con un HMAC derivado del token (nunca el token
// mismo) y se redirige a la URL limpia, para que el token no quede en el
// historial del navegador. Cambiar el token en el .env invalida todo acceso.
// Nada en el portal enlaza a este panel.

const ANALYTICS_COOKIE = 'sipcons_an_panel';
const ANALYTICS_COOKIE_HORAS = 12;

function analyticsTokenConfigurado(): ?string {
    $t = trim((string)($_ENV['ANALYTICS_TOKEN'] ?? ''));
    return strlen($t) >= 32 ? $t : null;
}

function analyticsCookieValor(string $token): string {
    return hash_hmac('sha256', 'sipcons-analytics-panel-v1', $token);
}

function puedeVerAnaliticas(): bool {
    $token = analyticsTokenConfigurado();
    if ($token === null) return false;
    $cookie = $_COOKIE[ANALYTICS_COOKIE] ?? '';
    return is_string($cookie) && $cookie !== '' && hash_equals(analyticsCookieValor($token), $cookie);
}

// Valida el token presentado y, si es correcto, entrega la cookie de acceso.
function analyticsIniciarAcceso(string $intento): bool {
    $token = analyticsTokenConfigurado();
    if ($token === null || !hash_equals($token, $intento)) {
        usleep(600000); // frena intentos por fuerza bruta
        error_log('Analíticas: intento de acceso con token inválido desde ' . ($_SERVER['REMOTE_ADDR'] ?? '?'));
        return false;
    }

    // La cookie aplica a toda la app (el panel está en /public y su API en /backend).
    $base = rtrim(str_replace('\\', '/', dirname(dirname($_SERVER['SCRIPT_NAME'] ?? '/public/x'))), '/');
    setcookie(ANALYTICS_COOKIE, analyticsCookieValor($token), [
        'expires'  => time() + ANALYTICS_COOKIE_HORAS * 3600,
        'path'     => $base === '' ? '/' : $base,
        'secure'   => !empty($_SERVER['HTTPS']),
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    return true;
}

function analyticsResponder404(bool $json): void {
    http_response_code(404);
    if ($json) {
        header('Content-Type: application/json');
        die(json_encode(['error' => 'No encontrado']));
    }
    header('Content-Type: text/html; charset=utf-8');
    die('<!DOCTYPE html><html lang="es"><head><meta charset="utf-8"><title>404</title></head><body><h1>404 Not Found</h1></body></html>');
}

function requireAnaliticas(): void {
    if (!puedeVerAnaliticas()) {
        analyticsResponder404(true);
    }
}
