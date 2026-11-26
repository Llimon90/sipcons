<?php
// Modo demostración. La empresa con estado 'demo' es la demo pública: el
// visitante puede usar todo el sistema (sus datos se restablecen cada noche con
// cron/reset_demo.php), pero no puede tocar lo que dejaría la demo inservible
// para el siguiente visitante:
//   - contraseñas (de ninguna cuenta)
//   - cuentas de demostración (DEMO_CUENTAS): ni editarlas ni borrarlas
//   - borrar usuarios
//   - la matriz de privilegios por rol
require_once __DIR__ . '/env.php';

// DEMO_MODE=true en .env: la plataforma ofrece la demo pública (credenciales en
// el login y reinicio nocturno de la empresa demo).
function demoActivo(): bool {
    return filter_var(env('DEMO_MODE', 'false'), FILTER_VALIDATE_BOOLEAN);
}

/** ¿La empresa de la sesión es la demo pública? (ahí aplican las restricciones) */
function enEmpresaDemo(): bool {
    $empresa = function_exists('empresaActual') ? empresaActual() : null;
    return $empresa !== null && $empresa['estado'] === 'demo';
}

/** Usuarios (campo usuarios.usuario) protegidos en modo demo. */
function demoCuentas(): array {
    $lista = array_map('trim', explode(',', (string)env('DEMO_CUENTAS', 'demo@sistema.com')));
    return array_values(array_filter($lista, 'strlen'));
}

/** Credenciales que se muestran en la pantalla de login (solo si DEMO_MODE). */
function demoCredencialesPublicas(): ?array {
    if (!demoActivo()) return null;
    $cuentas = demoCuentas();
    return [
        'usuario'  => $cuentas[0] ?? '',
        'password' => (string)env('DEMO_PASSWORD', 'demo123'),
        'reinicio' => (string)env('DEMO_RESET_HORA', '03:00'),
    ];
}

function esCuentaDemo(PDO $pdo, $usuarioId): bool {
    $stmt = $pdo->prepare("SELECT usuario FROM usuarios WHERE id = ? LIMIT 1");
    $stmt->execute([(int)$usuarioId]);
    $usuario = $stmt->fetchColumn();
    return $usuario !== false && in_array($usuario, demoCuentas(), true);
}

/**
 * Corta la petición con 403 si el modo demo está activo. $accion completa la
 * frase "Modo demostración: <accion> está deshabilitado(a)...".
 * Responde con las claves que usan los distintos frontends (message/mensaje/error).
 */
function demoBloquear(string $accion, bool $condicion = true): void {
    if (!enEmpresaDemo() || !$condicion) return;
    $texto = "Modo demostración: $accion no está disponible en esta cuenta de prueba.";
    http_response_code(403);
    header('Content-Type: application/json');
    die(json_encode([
        'success' => false, 'exito' => false, 'demo' => true,
        'message' => $texto, 'mensaje' => $texto, 'error' => $texto,
    ], JSON_UNESCAPED_UNICODE));
}
