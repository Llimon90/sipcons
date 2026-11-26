<?php
require_once __DIR__ . '/../auth/middleware.php';
requireRole('Administrador', 'Programador');
require_once __DIR__ . '/lib/sla_helpers.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

try {
    $stmt = $pdo->query("SELECT id, categoria, prioridad, horas_respuesta, horas_cierre, activo FROM sla_reglas ORDER BY categoria IS NULL DESC, categoria, prioridad");
    $reglas = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Categorías reales usadas en incidencias, para que el formulario ofrezca
    // un selector en vez de que el admin tenga que escribir el texto exacto.
    $categorias = $pdo->query("SELECT DISTINCT categoria FROM incidencias WHERE categoria IS NOT NULL AND categoria <> '' ORDER BY categoria")->fetchAll(PDO::FETCH_COLUMN);

    echo json_encode([
        'success' => true,
        'reglas' => $reglas,
        'categorias' => $categorias,
        'prioridades' => PRIORIDADES_INCIDENCIA,
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Error al obtener las reglas de SLA: ' . $e->getMessage()]);
}
