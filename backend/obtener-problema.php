<?php
require_once __DIR__ . '/../auth/middleware.php';
require_once __DIR__ . '/../auth/permisos.php';
requirePermiso('problemas');
header('Content-Type: application/json');

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Falta el id del problema.']);
    exit;
}

$stmt = $pdo->prepare('SELECT * FROM problemas WHERE id = ?');
$stmt->execute([$id]);
$problema = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$problema) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'El problema no existe.']);
    exit;
}

$stmtInc = $pdo->prepare(
    'SELECT i.id, i.numero_incidente, i.cliente, i.categoria, i.estatus, i.fecha
     FROM problema_incidencias pi
     JOIN incidencias i ON i.id = pi.incidencia_id
     WHERE pi.problema_id = ?
     ORDER BY i.id DESC'
);
$stmtInc->execute([$id]);
$problema['incidencias'] = $stmtInc->fetchAll(PDO::FETCH_ASSOC);

echo json_encode(['success' => true, 'problema' => $problema], JSON_UNESCAPED_UNICODE);
