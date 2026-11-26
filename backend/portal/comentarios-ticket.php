<?php
// Comentarios de un ticket visibles para el cliente. Solo lectura, solo
// comentarios con visible_cliente=1, y solo si el ticket es del cliente de
// la sesión (si no, responde 404 igual que ticket-detalle.php).
require_once __DIR__ . '/../../auth/portal_middleware.php';
requirePortalAuth();

$incidenciaId = filter_var($_GET['incidencia_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$incidenciaId) {
    http_response_code(404);
    die(json_encode(['success' => false, 'message' => 'Ticket no encontrado']));
}

$clienteId = (int)$_SESSION['portal_cliente_id'];

$ticket = $pdo->prepare('SELECT id FROM incidencias WHERE id = ? AND cliente_id = ?');
$ticket->execute([$incidenciaId, $clienteId]);
if (!$ticket->fetchColumn()) {
    http_response_code(404);
    die(json_encode(['success' => false, 'message' => 'Ticket no encontrado']));
}

$stmt = $pdo->prepare(
    "SELECT autor_nombre, comentario, creado_en FROM incidencia_comentarios
     WHERE incidencia_id = ? AND visible_cliente = 1 ORDER BY creado_en ASC, id ASC"
);
$stmt->execute([$incidenciaId]);

echo json_encode(['success' => true, 'comentarios' => $stmt->fetchAll(PDO::FETCH_ASSOC)], JSON_UNESCAPED_UNICODE);
