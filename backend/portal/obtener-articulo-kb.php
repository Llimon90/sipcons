<?php
// Detalle de un artículo público para el portal de clientes. Nunca expone
// uno con visibilidad 'Interno' (responde 404, igual que un ticket ajeno).
require_once __DIR__ . '/../../auth/portal_middleware.php';
requirePortalAuth();

$id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$id) {
    http_response_code(404);
    die(json_encode(['success' => false, 'message' => 'Artículo no encontrado']));
}

$stmt = $pdo->prepare("SELECT id, titulo, contenido, categoria, actualizado_en FROM kb_articulos WHERE id = ? AND visibilidad = 'Publico'");
$stmt->execute([$id]);
$articulo = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$articulo) {
    http_response_code(404);
    die(json_encode(['success' => false, 'message' => 'Artículo no encontrado']));
}

$pdo->prepare('UPDATE kb_articulos SET vistas = vistas + 1 WHERE id = ?')->execute([$id]);

echo json_encode(['success' => true, 'articulo' => $articulo], JSON_UNESCAPED_UNICODE);
