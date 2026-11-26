<?php
require_once __DIR__ . '/../auth/middleware.php';
require_once __DIR__ . '/../auth/permisos.php';
require_once __DIR__ . '/../auth/audit.php';
requirePermiso('inventario');
header('Content-Type: application/json');

// "Eliminar" un producto lo desactiva (activo = 0) en vez de borrarlo: ya
// pudo haber movimientos o líneas de cotización ligadas a su id, y esa
// historia no debe perderse. El catálogo de alta/búsqueda solo muestra
// productos activos.

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Falta el id del producto.']);
    exit;
}

$stmt = $pdo->prepare('SELECT * FROM inventario_productos WHERE id = ?');
$stmt->execute([$id]);
$anterior = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$anterior) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Producto no encontrado.']);
    exit;
}

$pdo->prepare('UPDATE inventario_productos SET activo = 0 WHERE id = ?')->execute([$id]);
registrarAuditoria('inventario_productos', $id, 'DELETE', $anterior, ['activo' => false]);

echo json_encode(['success' => true]);
