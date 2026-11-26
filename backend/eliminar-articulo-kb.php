<?php
require_once __DIR__ . '/../auth/middleware.php';
require_once __DIR__ . '/../auth/permisos.php';
require_once __DIR__ . '/../auth/audit.php';
requirePermiso('base_conocimiento');
header('Content-Type: application/json');

$data = json_decode(file_get_contents('php://input'), true) ?: [];
$id = (int)($data['id'] ?? 0);

if ($id <= 0) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Id inválido.']);
    exit;
}

try {
    $anteriorStmt = $pdo->prepare('SELECT * FROM kb_articulos WHERE id = ?');
    $anteriorStmt->execute([$id]);
    $anterior = $anteriorStmt->fetch(PDO::FETCH_ASSOC);
    if (!$anterior) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'El artículo no existe.']);
        exit;
    }

    $pdo->prepare('DELETE FROM kb_articulos WHERE id = ?')->execute([$id]);
    registrarAuditoria('kb_articulos', $id, 'DELETE', $anterior, null);

    echo json_encode(['success' => true]);
} catch (Throwable $e) {
    error_log('eliminar-articulo-kb.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'No se pudo eliminar el artículo.']);
}
