<?php
require_once __DIR__ . '/../auth/middleware.php';
require_once __DIR__ . '/../auth/permisos.php';
requirePermiso('catalogo_servicios');
header('Content-Type: application/json');

$estatus = trim((string)($_GET['estatus'] ?? ''));

$sql = "SELECT s.*, c.nombre AS servicio_nombre FROM solicitudes_servicio s
        JOIN catalogo_servicios c ON c.id = s.servicio_id";
$params = [];
if ($estatus !== '') {
    $sql .= ' WHERE s.estatus = ?';
    $params[] = $estatus;
}
$sql .= ' ORDER BY s.id DESC';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
echo json_encode(['success' => true, 'solicitudes' => $stmt->fetchAll(PDO::FETCH_ASSOC)], JSON_UNESCAPED_UNICODE);
