<?php
// Solicitudes de servicio del cliente en sesión. SIEMPRE filtra por el
// cliente_id de la SESIÓN, igual que mis-tickets.php.
require_once __DIR__ . '/../../auth/portal_middleware.php';
requirePortalAuth();

$clienteId = (int)$_SESSION['portal_cliente_id'];

$stmt = $pdo->prepare(
    "SELECT s.id, s.folio, s.sucursal, s.descripcion, s.estatus, s.fecha, c.nombre AS servicio_nombre
     FROM solicitudes_servicio s JOIN catalogo_servicios c ON c.id = s.servicio_id
     WHERE s.cliente_id = ? ORDER BY s.fecha DESC, s.id DESC"
);
$stmt->execute([$clienteId]);

echo json_encode(['success' => true, 'solicitudes' => $stmt->fetchAll(PDO::FETCH_ASSOC)], JSON_UNESCAPED_UNICODE);
