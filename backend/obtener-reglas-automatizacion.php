<?php
require_once __DIR__ . '/../auth/middleware.php';
requireRole('Administrador', 'Programador');
require_once __DIR__ . '/lib/automatizacion_helpers.php';
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
    $stmt = $pdo->query("SELECT id, nombre, campo_condicion, valor_condicion, accion, valor_accion, activo, orden FROM reglas_automatizacion ORDER BY orden ASC, id ASC");
    $reglas = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $categorias = $pdo->query("SELECT DISTINCT categoria FROM incidencias WHERE categoria IS NOT NULL AND categoria <> '' ORDER BY categoria")->fetchAll(PDO::FETCH_COLUMN);
    $tecnicos = $pdo->query("SELECT DISTINCT alias_tecnico FROM usuarios WHERE alias_tecnico IS NOT NULL AND alias_tecnico <> '' ORDER BY alias_tecnico")->fetchAll(PDO::FETCH_COLUMN);

    echo json_encode([
        'success' => true,
        'reglas' => $reglas,
        'categorias' => $categorias,
        'tecnicos' => $tecnicos,
        'prioridades' => PRIORIDADES_INCIDENCIA,
        'campos_condicion' => CAMPOS_CONDICION_AUTOMATIZACION,
        'acciones' => ACCIONES_AUTOMATIZACION,
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Error al obtener las reglas de automatización: ' . $e->getMessage()]);
}
