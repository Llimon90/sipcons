<?php
require_once __DIR__ . '/../auth/middleware.php';
header('Content-Type: application/json');

$incidenciaId = filter_var($_GET['incidencia_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$incidenciaId) {
    http_response_code(404);
    die(json_encode(['success' => false, 'message' => 'Incidencia no encontrada']));
}

$stmt = $pdo->prepare(
    'SELECT id, autor_nombre, comentario, visible_cliente, creado_en FROM incidencia_comentarios
     WHERE incidencia_id = ? ORDER BY creado_en ASC, id ASC'
);
$stmt->execute([$incidenciaId]);

echo json_encode(['success' => true, 'comentarios' => $stmt->fetchAll(PDO::FETCH_ASSOC)], JSON_UNESCAPED_UNICODE);
