<?php
require_once __DIR__ . '/../auth/middleware.php';
require_once __DIR__ . '/../auth/permisos.php';
require_once __DIR__ . '/../auth/audit.php';
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
$anterior = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$anterior) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Cotización no encontrada.']);
    exit;
}
if ($anterior['estatus'] === 'Convertida') {
    http_response_code(409);
    echo json_encode(['success' => false, 'message' => 'No se puede borrar una cotización ya convertida en venta.']);
    exit;
}

// ON DELETE CASCADE en cotizacion_detalles (migración 032) se encarga de las líneas.
$pdo->prepare('DELETE FROM cotizaciones WHERE id = ?')->execute([$id]);
registrarAuditoria('cotizaciones', $id, 'DELETE', $anterior, null, $anterior['folio']);

echo json_encode(['success' => true]);
