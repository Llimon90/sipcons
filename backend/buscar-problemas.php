<?php
require_once __DIR__ . '/../auth/middleware.php';
require_once __DIR__ . '/../auth/permisos.php';
requirePermiso('problemas');
header('Content-Type: application/json');

$texto = trim((string)($_GET['q'] ?? ''));
$estatus = trim((string)($_GET['estatus'] ?? ''));

$sql = 'SELECT p.*, (SELECT COUNT(*) FROM problema_incidencias pi WHERE pi.problema_id = p.id) AS incidencias_vinculadas
        FROM problemas p WHERE 1=1';
$params = [];

if ($texto !== '') {
    $sql .= ' AND (p.folio LIKE ? OR p.titulo LIKE ?)';
    $like = "%$texto%";
    $params[] = $like;
    $params[] = $like;
}
if ($estatus !== '') {
    $sql .= ' AND p.estatus = ?';
    $params[] = $estatus;
}
$sql .= ' ORDER BY p.id DESC LIMIT 200';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC), JSON_UNESCAPED_UNICODE);
