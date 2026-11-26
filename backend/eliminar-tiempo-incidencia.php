<?php
// Solo quien registró el tiempo o un Administrador/Programador puede borrarlo.
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
    $id = (int)($data['id'] ?? 0);
    if ($id <= 0) {
        http_response_code(400);
        die(json_encode(['success' => false, 'message' => 'id inválido']));
    }

    $stmt = $pdo->prepare("SELECT id, incidencia_id, usuario_id FROM bitacora_tiempo WHERE id = ?");
    $stmt->execute([$id]);
    $registro = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$registro) {
        http_response_code(404);
        die(json_encode(['success' => false, 'message' => 'El registro no existe']));
    }

    $rol = $_SESSION['rol'] ?? '';
    $esPropio = $registro['usuario_id'] !== null && (int)$registro['usuario_id'] === (int)($_SESSION['user_id'] ?? 0);
    $esAdmin = in_array($rol, ['Administrador', 'Técnico/Administrador', 'Programador'], true);
    if (!$esPropio && !$esAdmin) {
        http_response_code(403);
        die(json_encode(['success' => false, 'message' => 'No puedes eliminar un registro de tiempo de otro usuario']));
    }

    $pdo->prepare("DELETE FROM bitacora_tiempo WHERE id = ?")->execute([$id]);
    registrarAuditoria('bitacora_tiempo', $registro['incidencia_id'], 'DELETE', $registro, null);

    echo json_encode(['success' => true]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Error al eliminar el registro: ' . $e->getMessage()]);
}
