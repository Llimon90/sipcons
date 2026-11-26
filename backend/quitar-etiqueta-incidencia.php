<?php
require_once __DIR__ . '/../auth/middleware.php';
requirePermiso('incidencias');
require_once __DIR__ . '/../auth/audit.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

try {
    $data = json_decode(file_get_contents('php://input'), true) ?: [];
    $incidenciaId = (int)($data['incidencia_id'] ?? 0);
    $etiquetaId = (int)($data['etiqueta_id'] ?? 0);
    if ($incidenciaId <= 0 || $etiquetaId <= 0) {
        http_response_code(400);
        die(json_encode(['success' => false, 'message' => 'Datos inválidos']));
    }

    $pdo->prepare("DELETE FROM incidencia_etiquetas WHERE incidencia_id = ? AND etiqueta_id = ?")
        ->execute([$incidenciaId, $etiquetaId]);

    registrarAuditoria('incidencias', $incidenciaId, 'UPDATE', ['etiqueta_quitada' => $etiquetaId], null);
    echo json_encode(['success' => true]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Error al quitar la etiqueta: ' . $e->getMessage()]);
}
