<?php
require_once __DIR__ . '/../auth/middleware.php';
require_once __DIR__ . '/../auth/permisos.php';
require_once __DIR__ . '/../auth/audit.php';
requirePermiso('base_conocimiento');
header('Content-Type: application/json');

$data = json_decode(file_get_contents('php://input'), true) ?: [];
$id = (int)($data['id'] ?? 0);
$titulo = trim((string)($data['titulo'] ?? ''));
$contenido = trim((string)($data['contenido'] ?? ''));
$categoria = trim((string)($data['categoria'] ?? ''));
$visibilidad = ($data['visibilidad'] ?? '') === 'Publico' ? 'Publico' : 'Interno';

if ($id <= 0 || $titulo === '' || $contenido === '') {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Datos incompletos.']);
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

    $pdo->prepare('UPDATE kb_articulos SET titulo = ?, contenido = ?, categoria = ?, visibilidad = ? WHERE id = ?')
        ->execute([$titulo, $contenido, $categoria !== '' ? $categoria : null, $visibilidad, $id]);

    registrarAuditoria('kb_articulos', $id, 'UPDATE', $anterior, compact('titulo', 'categoria', 'visibilidad'));

    echo json_encode(['success' => true]);
} catch (Throwable $e) {
    error_log('actualiza-articulo-kb.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'No se pudo actualizar el artículo.']);
}
