<?php
// Cambiar estatus/asignar técnico a una solicitud de servicio (personal).
require_once __DIR__ . '/../auth/middleware.php';
require_once __DIR__ . '/../auth/permisos.php';
require_once __DIR__ . '/../auth/audit.php';
requirePermiso('catalogo_servicios');
header('Content-Type: application/json');

const ESTATUS_SOLICITUD_VALIDOS = ['Pendiente', 'Aprobada', 'En proceso', 'Completada', 'Rechazada'];

$data = json_decode(file_get_contents('php://input'), true) ?: [];
$id = (int)($data['id'] ?? 0);
$estatus = (string)($data['estatus'] ?? '');
$tecnico = trim((string)($data['tecnico_asignado'] ?? ''));

if ($id <= 0 || !in_array($estatus, ESTATUS_SOLICITUD_VALIDOS, true)) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Datos inválidos.']);
    exit;
}

try {
    $anteriorStmt = $pdo->prepare('SELECT * FROM solicitudes_servicio WHERE id = ?');
    $anteriorStmt->execute([$id]);
    $anterior = $anteriorStmt->fetch(PDO::FETCH_ASSOC);
    if (!$anterior) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'La solicitud no existe.']);
        exit;
    }

    $pdo->prepare('UPDATE solicitudes_servicio SET estatus = ?, tecnico_asignado = ? WHERE id = ?')
        ->execute([$estatus, $tecnico !== '' ? $tecnico : null, $id]);

    registrarAuditoria('solicitudes_servicio', $id, 'UPDATE', $anterior, compact('estatus', 'tecnico'), $anterior['folio'] ?? null);

    echo json_encode(['success' => true]);
} catch (Throwable $e) {
    error_log('actualiza-solicitud-servicio.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'No se pudo actualizar la solicitud.']);
}
