<?php
require_once __DIR__ . '/../auth/middleware.php';
require_once __DIR__ . '/../auth/permisos.php';
requirePermiso('inventario');
header('Content-Type: application/json');

$productoId = (int)($_GET['producto_id'] ?? 0);
if ($productoId <= 0) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Falta producto_id.']);
    exit;
}

$stmt = $pdo->prepare('SELECT * FROM inventario_movimientos WHERE producto_id = ? ORDER BY id DESC LIMIT 200');
$stmt->execute([$productoId]);
echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
