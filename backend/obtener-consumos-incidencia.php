<?php
// Refacciones consumidas (netas) en una incidencia, para mostrarlas en su
// detalle. Puerto acotado de inventario.php:consumos_incidencia (sin
// servicios/reemplazos, que no se portaron esta ronda).
require_once __DIR__ . '/../auth/middleware.php';
require_once __DIR__ . '/../auth/permisos.php';
requirePermiso('incidencias');
header('Content-Type: application/json');

$incidenciaId = (int)($_GET['incidencia_id'] ?? 0);
if ($incidenciaId <= 0) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Falta el id de la incidencia.']);
    exit;
}

$stmt = $pdo->prepare(
    "SELECT p.id AS producto_id, p.sku, p.nombre, p.unidad, p.precio_venta,
            SUM(CASE WHEN m.tipo = 'Salida' THEN m.cantidad WHEN m.tipo = 'Entrada' THEN -m.cantidad ELSE 0 END) AS cantidad,
            MAX(m.creado_en) AS ultimo
     FROM inventario_movimientos m
     JOIN inventario_productos p ON p.id = m.producto_id
     WHERE m.referencia_tipo = 'incidencia' AND m.referencia_id = ?
     GROUP BY p.id, p.sku, p.nombre, p.unidad, p.precio_venta
     HAVING cantidad > 0
     ORDER BY ultimo DESC"
);
$stmt->execute([$incidenciaId]);
echo json_encode(['success' => true, 'consumos' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
