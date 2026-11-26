<?php
require_once __DIR__ . '/../auth/middleware.php';
require_once __DIR__ . '/../auth/permisos.php';
require_once __DIR__ . '/../auth/audit.php';
requirePermiso('clientes');

header('Content-Type: application/json');

$id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$id) {
    http_response_code(400);
    die(json_encode(['success' => false, 'error' => 'id inválido']));
}

// Se desactiva en vez de borrar: conserva el historial de quién tuvo acceso.
$stmt = $pdo->prepare("UPDATE portal_usuarios SET activo = 0 WHERE id = ?");
$stmt->execute([$id]);

registrarAuditoria('portal_usuarios', $id, 'DESACTIVAR', null, null);
echo json_encode(['success' => true]);
