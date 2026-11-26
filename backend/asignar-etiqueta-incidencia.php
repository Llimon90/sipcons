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

    // Confirma que ambos existan antes de ligarlos (nunca se confía en el id del cliente)
    $existeIncidencia = $pdo->prepare("SELECT id FROM incidencias WHERE id = ?");
    $existeIncidencia->execute([$incidenciaId]);
    if (!$existeIncidencia->fetchColumn()) {
        http_response_code(404);
        die(json_encode(['success' => false, 'message' => 'La incidencia no existe']));
    }
    $existeEtiqueta = $pdo->prepare("SELECT id FROM etiquetas WHERE id = ?");
    $existeEtiqueta->execute([$etiquetaId]);
    if (!$existeEtiqueta->fetchColumn()) {
        http_response_code(404);
        die(json_encode(['success' => false, 'message' => 'La etiqueta no existe']));
    }

    $pdo->prepare("INSERT IGNORE INTO incidencia_etiquetas (incidencia_id, etiqueta_id) VALUES (?, ?)")
        ->execute([$incidenciaId, $etiquetaId]);

    registrarAuditoria('incidencias', $incidenciaId, 'UPDATE', null, ['etiqueta_asignada' => $etiquetaId]);
    echo json_encode(['success' => true]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Error al asignar la etiqueta: ' . $e->getMessage()]);
}
