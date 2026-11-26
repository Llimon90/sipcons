<?php
// Búsqueda de artículos para el personal (ve internos y públicos). La
// variante del portal (solo públicos) es backend/portal/buscar-articulos-kb.php.
require_once __DIR__ . '/../auth/middleware.php';
require_once __DIR__ . '/../auth/permisos.php';
requirePermiso('base_conocimiento');
header('Content-Type: application/json');

$texto = trim((string)($_GET['q'] ?? ''));

$sql = 'SELECT id, titulo, categoria, visibilidad, vistas, actualizado_en FROM kb_articulos';
$params = [];
if ($texto !== '') {
    $sql .= ' WHERE titulo LIKE ? OR contenido LIKE ? OR categoria LIKE ?';
    $like = '%' . $texto . '%';
    $params = [$like, $like, $like];
}
$sql .= ' ORDER BY actualizado_en DESC';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
echo json_encode(['success' => true, 'articulos' => $stmt->fetchAll(PDO::FETCH_ASSOC)], JSON_UNESCAPED_UNICODE);
