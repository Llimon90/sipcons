<?php
// Catálogo completo (para administrarlo en Ajustes). Para el uso normal en
// una incidencia, ver backend/buscar-etiquetas.php (permiso 'incidencias').
require_once __DIR__ . '/../auth/middleware.php';
requireRole('Administrador', 'Programador');

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

try {
    $stmt = $pdo->query("SELECT id, nombre, color FROM etiquetas ORDER BY nombre ASC");
    echo json_encode(['success' => true, 'etiquetas' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Error al obtener etiquetas: ' . $e->getMessage()]);
}
