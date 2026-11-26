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
        "SELECT id, usuario_nombre, minutos, descripcion, fecha, creado_en
         FROM bitacora_tiempo WHERE incidencia_id = ? ORDER BY fecha DESC, id DESC"
    );
    $stmt->execute([$incidenciaId]);
    $registros = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $totalMinutos = array_sum(array_column($registros, 'minutos'));

    echo json_encode(['success' => true, 'registros' => $registros, 'total_minutos' => $totalMinutos]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Error al obtener la bitácora: ' . $e->getMessage()]);
}
