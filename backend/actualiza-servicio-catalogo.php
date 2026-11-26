<?php
require_once __DIR__ . '/../auth/middleware.php';
require_once __DIR__ . '/../auth/permisos.php';
require_once __DIR__ . '/../auth/audit.php';
requirePermiso('catalogo_servicios');
header('Content-Type: application/json');

$data = json_decode(file_get_contents('php://input'), true) ?: [];
$id = (int)($data['id'] ?? 0);
$nombre = trim((string)($data['nombre'] ?? ''));
$descripcion = trim((string)($data['descripcion'] ?? ''));
$categoria = trim((string)($data['categoria'] ?? ''));
$slaHoras = $data['sla_horas_estimado'] ?? null;
$slaHoras = ($slaHoras !== null && $slaHoras !== '') ? (int)$slaHoras : null;
$activo = isset($data['activo']) ? (int)(bool)$data['activo'] : 1;

if ($id <= 0 || $nombre === '') {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Datos incompletos.']);
    exit;
}

try {
    $anteriorStmt = $pdo->prepare('SELECT * FROM catalogo_servicios WHERE id = ?');
    $anteriorStmt->execute([$id]);
    $anterior = $anteriorStmt->fetch(PDO::FETCH_ASSOC);
    if (!$anterior) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'El servicio no existe.']);
        exit;
    }

    $pdo->prepare(
        'UPDATE catalogo_servicios SET nombre = ?, descripcion = ?, categoria = ?, sla_horas_estimado = ?, activo = ? WHERE id = ?'
    )->execute([$nombre, $descripcion !== '' ? $descripcion : null, $categoria !== '' ? $categoria : null, $slaHoras, $activo, $id]);

    registrarAuditoria('catalogo_servicios', $id, 'UPDATE', $anterior, compact('nombre', 'descripcion', 'categoria', 'slaHoras', 'activo'));

    echo json_encode(['success' => true]);
} catch (Throwable $e) {
    error_log('actualiza-servicio-catalogo.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'No se pudo actualizar el servicio.']);
}
