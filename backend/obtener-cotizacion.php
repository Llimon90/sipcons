<?php
require_once __DIR__ . '/../auth/middleware.php';
require_once __DIR__ . '/../auth/permisos.php';
requirePermiso('cotizaciones');
header('Content-Type: application/json');

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Falta el id de la cotización.']);
    exit;
}

$stmt = $pdo->prepare('SELECT * FROM cotizaciones WHERE id = ?');
$stmt->execute([$id]);
$cotizacion = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$cotizacion) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Cotización no encontrada.']);
    exit;
}

$stmtDetalle = $pdo->prepare('SELECT * FROM cotizacion_detalles WHERE cotizacion_id = ? ORDER BY id');
$stmtDetalle->execute([$id]);
$cotizacion['detalles'] = $stmtDetalle->fetchAll(PDO::FETCH_ASSOC);

echo json_encode(['success' => true, 'cotizacion' => $cotizacion]);
