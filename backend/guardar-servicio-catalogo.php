<?php
require_once __DIR__ . '/../auth/middleware.php';
require_once __DIR__ . '/../auth/permisos.php';
require_once __DIR__ . '/../auth/audit.php';
requirePermiso('catalogo_servicios');
header('Content-Type: application/json');

$data = json_decode(file_get_contents('php://input'), true) ?: [];
$nombre = trim((string)($data['nombre'] ?? ''));
$descripcion = trim((string)($data['descripcion'] ?? ''));
$categoria = trim((string)($data['categoria'] ?? ''));
$slaHoras = $data['sla_horas_estimado'] ?? null;
$slaHoras = ($slaHoras !== null && $slaHoras !== '') ? (int)$slaHoras : null;

if ($nombre === '') {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'El nombre del servicio es obligatorio.']);
    exit;
}

try {
    $stmt = $pdo->prepare(
        'INSERT INTO catalogo_servicios (nombre, descripcion, categoria, sla_horas_estimado, activo) VALUES (?, ?, ?, ?, 1)'
    );
    $stmt->execute([$nombre, $descripcion !== '' ? $descripcion : null, $categoria !== '' ? $categoria : null, $slaHoras]);
    $id = (int)$pdo->lastInsertId();

    registrarAuditoria('catalogo_servicios', $id, 'CREATE', null, compact('nombre', 'descripcion', 'categoria', 'slaHoras'));

    echo json_encode(['success' => true, 'id' => $id]);
} catch (Throwable $e) {
    error_log('guardar-servicio-catalogo.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'No se pudo guardar el servicio.']);
}
