<?php
// Lista ligera de productos activos para selects (registrar refacción usada
// en una incidencia). Requiere permiso de incidencias, no de inventario
// completo: igual que consumir/devolver-refaccion-incidencia.php.
require_once __DIR__ . '/../auth/middleware.php';
require_once __DIR__ . '/../auth/permisos.php';
requirePermiso('incidencias');
header('Content-Type: application/json');

$stmt = $pdo->query(
    "SELECT id, sku, nombre, unidad, stock_actual FROM inventario_productos WHERE activo = 1 ORDER BY nombre"
);
echo json_encode(['success' => true, 'productos' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
