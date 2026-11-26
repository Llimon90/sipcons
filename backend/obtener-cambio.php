<?php
require_once __DIR__ . '/../auth/middleware.php';
require_once __DIR__ . '/../auth/permisos.php';
requirePermiso('cambios');
header('Content-Type: application/json');

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Falta el id del cambio.']);
    exit;
}

$stmt = $pdo->prepare('SELECT * FROM cambios WHERE id = ?');
$stmt->execute([$id]);
$cambio = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$cambio) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'El cambio no existe.']);
    exit;
}

echo json_encode(['success' => true, 'cambio' => $cambio], JSON_UNESCAPED_UNICODE);
