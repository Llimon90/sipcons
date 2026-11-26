<?php
require_once __DIR__ . '/../auth/middleware.php';
require_once __DIR__ . '/../auth/permisos.php';
requirePermiso('cambios');
header('Content-Type: application/json');

$texto = trim((string)($_GET['q'] ?? ''));
$estatus = trim((string)($_GET['estatus'] ?? ''));
$riesgo = trim((string)($_GET['riesgo'] ?? ''));

$sql = 'SELECT * FROM cambios WHERE 1=1';
$params = [];

if ($texto !== '') {
    $sql .= ' AND (folio LIKE ? OR titulo LIKE ?)';
    $like = "%$texto%";
    $params[] = $like;
    $params[] = $like;
}
if ($estatus !== '') {
    $sql .= ' AND estatus = ?';
    $params[] = $estatus;
}
if ($riesgo !== '') {
    $sql .= ' AND riesgo = ?';
    $params[] = $riesgo;
}
$sql .= ' ORDER BY id DESC LIMIT 200';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC), JSON_UNESCAPED_UNICODE);
