<?php
require_once __DIR__ . '/../auth/middleware.php';
require_once __DIR__ . '/../auth/permisos.php';
require_once __DIR__ . '/../auth/audit.php';
requirePermiso('problemas');
header('Content-Type: application/json');

try {
    $data = json_decode(file_get_contents('php://input'), true) ?: [];
    $problemaId = (int)($data['problema_id'] ?? 0);
    $incidenciaId = (int)($data['incidencia_id'] ?? 0);

    if ($problemaId <= 0 || $incidenciaId <= 0) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'Faltan datos para desvincular.']);
        exit;
    }

    $pdo->prepare('DELETE FROM problema_incidencias WHERE problema_id = ? AND incidencia_id = ?')
        ->execute([$problemaId, $incidenciaId]);

    registrarAuditoria('problema_incidencias', $problemaId, 'DELETE', [
        'incidencia_id' => $incidenciaId,
    ], null);

    echo json_encode(['success' => true]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Error al desvincular la incidencia.']);
}
