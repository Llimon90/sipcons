<?php
// Preferencias personales del usuario en sesión (O-15).
//   GET                 -> { exito, preferencias: {clave: valor} }
//   POST {clave, valor} -> guarda una preferencia conocida (PREFERENCIAS_CLAVES)
require_once __DIR__ . '/../auth/middleware.php';
require_once __DIR__ . '/lib/preferencias_helpers.php';
header('Content-Type: application/json; charset=utf-8');

function responderPreferencias(array $datos, int $http = 200): void {
    http_response_code($http);
    echo json_encode($datos, JSON_UNESCAPED_UNICODE);
    exit;
}

$usuarioId = (int)($_SESSION['user_id'] ?? 0);
$empresaId = (int)($_SESSION['empresa_id'] ?? 0);
try {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        $body = json_decode(file_get_contents('php://input'), true) ?: [];
        try {
            $p = empty($_SESSION['soporte']) ? preferenciasGuardar($pdo, $usuarioId, $empresaId, (string)($body['clave'] ?? ''), $body['valor'] ?? null) : [];
        } catch (InvalidArgumentException $e) {
            responderPreferencias(['exito' => false, 'mensaje' => $e->getMessage()], 400);
        }
        responderPreferencias(['exito' => true, 'preferencias' => (object)$p]);
    }
    responderPreferencias(['exito' => true, 'preferencias' => (object)preferenciasLeer($pdo, $usuarioId, $empresaId)]);
} catch (Throwable $e) {
    error_log('Preferencias: ' . $e->getMessage());
    responderPreferencias(['exito' => false, 'mensaje' => 'No se pudieron consultar tus preferencias.'], 500);
}
