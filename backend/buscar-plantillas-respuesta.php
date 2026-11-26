<?php
// Catálogo de plantillas activas para el selector del detalle de incidencia
// (cualquier rol con permiso del módulo 'incidencias').
require_once __DIR__ . '/../auth/middleware.php';
requirePermiso('incidencias');

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

try {
    $stmt = $pdo->query("SELECT id, titulo, contenido, categoria FROM plantillas_respuesta WHERE activo = 1 ORDER BY titulo ASC");
    echo json_encode(['success' => true, 'plantillas' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Error al buscar plantillas: ' . $e->getMessage()]);
}
