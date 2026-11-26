<?php
// Lista de incidencias del cliente en sesión. SIEMPRE filtra por el
// cliente_id de la SESIÓN (nunca por algo que mande el navegador): un
// usuario del portal no puede pedir los tickets de otro cliente.
require_once __DIR__ . '/../../auth/portal_middleware.php';
requirePortalAuth();

$clienteId = (int)$_SESSION['portal_cliente_id'];

$stmt = $pdo->prepare("SELECT id, numero_incidente, reporte_cliente, sucursal, categoria, prioridad, estatus, falla, fecha
    FROM incidencias WHERE cliente_id = ? ORDER BY fecha DESC, id DESC");
$stmt->execute([$clienteId]);

echo json_encode(['success' => true, 'tickets' => $stmt->fetchAll(PDO::FETCH_ASSOC)], JSON_UNESCAPED_UNICODE);
