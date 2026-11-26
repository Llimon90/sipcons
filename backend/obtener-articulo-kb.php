<?php
// Detalle de un artículo para el personal (incrementa vistas). Requiere
// sesión de personal con permiso de base_conocimiento.
require_once __DIR__ . '/../auth/middleware.php';
require_once __DIR__ . '/../auth/permisos.php';
requirePermiso('base_conocimiento');
header('Content-Type: application/json');

$id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$id) {
    http_response_code(404);
    die(json_encode(['success' => false, 'message' => 'Artículo no encontrado']));
}

$stmt = $pdo->prepare('SELECT * FROM kb_articulos WHERE id = ?');
$stmt->execute([$id]);
$articulo = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$articulo) {
    http_response_code(404);
    die(json_encode(['success' => false, 'message' => 'Artículo no encontrado']));
}

$pdo->prepare('UPDATE kb_articulos SET vistas = vistas + 1 WHERE id = ?')->execute([$id]);

echo json_encode(['success' => true, 'articulo' => $articulo], JSON_UNESCAPED_UNICODE);
