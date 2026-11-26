<?php
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

$incidenciaId = (int)($_GET['incidencia_id'] ?? 0);
if ($incidenciaId <= 0) {
    http_response_code(400);
    die(json_encode(['success' => false, 'message' => 'id de incidencia inválido']));
}

try {
    $stmt = $pdo->prepare(
        "SELECT e.id, e.nombre, e.color FROM incidencia_etiquetas ie
         JOIN etiquetas e ON e.id = ie.etiqueta_id
         WHERE ie.incidencia_id = ? ORDER BY e.nombre ASC"
    );
    $stmt->execute([$incidenciaId]);
    echo json_encode(['success' => true, 'etiquetas' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Error al obtener etiquetas de la incidencia: ' . $e->getMessage()]);
}
