<?php
require_once __DIR__ . '/../auth/middleware.php';
requirePermiso('incidencias');
require_once __DIR__ . '/../auth/audit.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

try {
    $data = json_decode(file_get_contents('php://input'), true) ?: [];
    $incidenciaId = (int)($data['incidencia_id'] ?? 0);
    $minutos = (int)($data['minutos'] ?? 0);
    if ($incidenciaId <= 0 || $minutos <= 0 || $minutos > 1440) {
        http_response_code(400);
        die(json_encode(['success' => false, 'message' => 'Datos inválidos: minutos debe ser entre 1 y 1440']));
    }

    $existe = $pdo->prepare("SELECT id FROM incidencias WHERE id = ?");
    $existe->execute([$incidenciaId]);
    if (!$existe->fetchColumn()) {
        http_response_code(404);
        die(json_encode(['success' => false, 'message' => 'La incidencia no existe']));
    }

    $descripcion = mb_substr(trim((string)($data['descripcion'] ?? '')), 0, 255);
    $fecha = trim((string)($data['fecha'] ?? date('Y-m-d')));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)) $fecha = date('Y-m-d');

    $pdo->prepare(
        "INSERT INTO bitacora_tiempo (incidencia_id, usuario_id, usuario_nombre, minutos, descripcion, fecha)
         VALUES (?, ?, ?, ?, ?, ?)"
    )->execute([
        $incidenciaId,
        $_SESSION['user_id'] ?? null,
        $_SESSION['nombre'] ?? ($_SESSION['usuario'] ?? null),
        $minutos,
        $descripcion !== '' ? $descripcion : null,
        $fecha,
    ]);

    registrarAuditoria('bitacora_tiempo', $incidenciaId, 'CREATE', null, ['minutos' => $minutos, 'fecha' => $fecha]);
    echo json_encode(['success' => true]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Error al registrar el tiempo: ' . $e->getMessage()]);
}
