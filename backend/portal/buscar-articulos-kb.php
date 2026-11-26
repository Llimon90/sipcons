<?php
// Búsqueda de artículos de la base de conocimiento para el portal de
// clientes: SOLO artículos con visibilidad 'Publico', nunca internos.
require_once __DIR__ . '/../../auth/portal_middleware.php';
requirePortalAuth();

$texto = trim((string)($_GET['q'] ?? ''));

$sql = "SELECT id, titulo, categoria, actualizado_en FROM kb_articulos WHERE visibilidad = 'Publico'";
$params = [];
if ($texto !== '') {
    $sql .= ' AND (titulo LIKE ? OR contenido LIKE ? OR categoria LIKE ?)';
    $like = '%' . $texto . '%';
    $params = [$like, $like, $like];
}
$sql .= ' ORDER BY actualizado_en DESC';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
echo json_encode(['success' => true, 'articulos' => $stmt->fetchAll(PDO::FETCH_ASSOC)], JSON_UNESCAPED_UNICODE);
