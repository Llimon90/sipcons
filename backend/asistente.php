<?php
// Asistente guiado por rol (D-20).
//   GET            -> { exito, estado: nuevo|cerrado|terminado|null, rol }
//   POST {estado}  -> guarda 'cerrado' o 'terminado' en la cuenta del usuario
require_once __DIR__ . '/../auth/middleware.php';
require_once __DIR__ . '/lib/asistente_helpers.php';
header('Content-Type: application/json; charset=utf-8');

function responderAsistente(array $datos, int $http = 200): void {
    http_response_code($http);
    echo json_encode($datos, JSON_UNESCAPED_UNICODE);
    exit;
}

$usuarioId = (int)($_SESSION['user_id'] ?? 0);
$empresaId = (int)($_SESSION['empresa_id'] ?? 0);
$enSoporte = !empty($_SESSION['soporte']);  // el soporte de plataforma no tiene recorrido propio
$rol = normalizarRol((string)($_SESSION['rol'] ?? ''));

try {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        $body = json_decode(file_get_contents('php://input'), true) ?: [];
        $estado = (string)($body['estado'] ?? '');
        if (!in_array($estado, ASISTENTE_ESTADOS, true)) responderAsistente(['exito' => false, 'mensaje' => 'Estado no válido.'], 400);
        if (!$enSoporte) asistenteGuardar($pdo, $usuarioId, $empresaId, $estado);
        responderAsistente(['exito' => true, 'estado' => $estado]);
    }
    responderAsistente(['exito' => true, 'estado' => $enSoporte ? 'terminado' : asistenteEstado($pdo, $usuarioId, $empresaId), 'rol' => $rol]);
} catch (Throwable $e) {
    error_log('Asistente: ' . $e->getMessage());
    responderAsistente(['exito' => false, 'mensaje' => 'No se pudo consultar el recorrido guiado.'], 500);
}
