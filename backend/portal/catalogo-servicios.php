<?php
// Catálogo de servicios visible para el cliente en el portal: solo activos.
require_once __DIR__ . '/../../auth/portal_middleware.php';
requirePortalAuth();

$stmt = $pdo->query("SELECT id, nombre, descripcion, categoria, sla_horas_estimado FROM catalogo_servicios WHERE activo = 1 ORDER BY nombre ASC");
echo json_encode(['success' => true, 'servicios' => $stmt->fetchAll(PDO::FETCH_ASSOC)], JSON_UNESCAPED_UNICODE);
