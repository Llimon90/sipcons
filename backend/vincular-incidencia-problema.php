<?php
require_once __DIR__ . '/../auth/middleware.php';
require_once __DIR__ . '/../auth/permisos.php';
require_once __DIR__ . '/../auth/audit.php';
requirePermiso('problemas');
header('Content-Type: application/json');

try {
    $data = json_decode(file_get_contents('php://input'), true) ?: [];
    $problemaId = (int)($data['problema_id'] ?? 0);
    $folioIncidencia = trim((string)($data['numero_incidente'] ?? ''));

    if ($problemaId <= 0 || $folioIncidencia === '') {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'Falta el problema o el folio de la incidencia.']);
        exit;
    }

    $stmtProblema = $pdo->prepare('SELECT id, folio FROM problemas WHERE id = ?');
    $stmtProblema->execute([$problemaId]);
    $problema = $stmtProblema->fetch(PDO::FETCH_ASSOC);
    if (!$problema) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'El problema no existe.']);
        exit;
    }

    $stmtInc = $pdo->prepare('SELECT id FROM incidencias WHERE numero_incidente = ?');
    $stmtInc->execute([$folioIncidencia]);
    $incidenciaId = $stmtInc->fetchColumn();
    if (!$incidenciaId) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'No existe ninguna incidencia con ese folio.']);
        exit;
    }

    $pdo->prepare('INSERT IGNORE INTO problema_incidencias (problema_id, incidencia_id) VALUES (?, ?)')
        ->execute([$problemaId, (int)$incidenciaId]);

    registrarAuditoria('problema_incidencias', $problemaId, 'CREATE', null, [
        'incidencia_id' => (int)$incidenciaId, 'numero_incidente' => $folioIncidencia,
    ], $problema['folio']);

    echo json_encode(['success' => true]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Error al vincular la incidencia.']);
}
