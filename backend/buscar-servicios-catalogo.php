<?php
// Lista del catálogo para el personal (incluye inactivos). La variante para
// el portal de clientes es backend/portal/catalogo-servicios.php (solo activos).
require_once __DIR__ . '/../auth/middleware.php';
require_once __DIR__ . '/../auth/permisos.php';
requirePermiso('catalogo_servicios');
header('Content-Type: application/json');

$stmt = $pdo->query('SELECT * FROM catalogo_servicios ORDER BY nombre ASC');
echo json_encode(['success' => true, 'servicios' => $stmt->fetchAll(PDO::FETCH_ASSOC)], JSON_UNESCAPED_UNICODE);
